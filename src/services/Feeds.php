<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\helpers\Markdown;
use craft\web\View;
use HTMLPurifier;
use HTMLPurifier_Config;
use justinholtweb\live\elements\Update;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\Plugin;
use Throwable;

/**
 * The read side: turning updates into the payload readers actually receive, and into HTML.
 *
 * Rendering happens once, at publish time, in the same template the page used — so an update that
 * arrives over the wire is byte-identical to one that was there when the page was built. No second
 * implementation of the card in JavaScript, no theme drift, no “why does the new one look wrong”.
 */
class Feeds extends Component
{
    /** Template the plugin falls back to when the site hasn't provided its own. */
    public const DEFAULT_TEMPLATE = 'live/_update';

    private ?HTMLPurifier $_purifier = null;

    /**
     * The JSON shape of one update. This is the plugin's public wire format: additive changes only.
     */
    public function updatePayload(Update $update, bool $includeHtml = true): array
    {
        $type = $update->getType();

        $payload = [
            'id' => (int)$update->id,
            'seq' => (int)$update->seq,
            'rev' => (int)$update->metaValue('rev', 0),
            'type' => $type->handle,
            'typeName' => $type->name,
            'color' => $type->color,
            'icon' => $type->icon,
            'title' => $update->title,
            'body' => $update->body,
            'excerpt' => $update->getExcerpt(160),
            'pinned' => (bool)$update->pinned,
            'highlight' => (bool)$update->highlight,
            'postedAt' => $update->postedAt?->format(DATE_ATOM),
            'timestamp' => $update->postedAt?->getTimestamp(),
            'author' => $update->getAuthor()?->friendlyName,
            'url' => $update->getPost()?->getUrl(),
        ];

        if ($includeHtml) {
            $payload['html'] = $this->renderUpdate($update);
        }

        return $payload;
    }

    /**
     * Render one update with the site's own template.
     *
     * Wrapped in a catch because this runs inside publish: a typo in a site template must not stop
     * an editor getting an update onto the site. It degrades to no pre-rendered HTML, which the
     * client handles by falling back to its own minimal markup.
     */
    public function renderUpdate(Update $update): ?string
    {
        $view = Craft::$app->getView();
        $settings = Plugin::getInstance()->getSettings();
        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            // `live/_update` resolves to the site's own copy if it has one, and to the partial the
            // plugin ships if it doesn't.
            $template = $settings->updateTemplate ?: self::DEFAULT_TEMPLATE;

            if (!$view->doesTemplateExist($template)) {
                return null;
            }

            return trim($view->renderTemplate($template, [
                'update' => $update,
                'post' => $update->getPost(),
            ], View::TEMPLATE_MODE_SITE));
        } catch (Throwable $e) {
            Craft::warning("Live could not render update $update->id: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            return null;
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }

    /**
     * The head payload — what a reader polls for. Deliberately tiny: state, the current sequence,
     * and just enough per-update detail to work out what it is missing.
     */
    public function headPayload(LivePost $post, ?array $carriedRemovals = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $updates = Update::find()
            ->postId($post->postId)
            ->fieldId($post->fieldId)
            ->siteId($post->siteId)
            ->status(Update::STATUS_PUBLISHED)
            ->limit($settings->headWindow)
            ->all();

        return [
            'seq' => (int)$post->seq,
            'state' => $post->state,
            'count' => (int)$post->updateCount,
            'pinned' => $post->pinnedUpdateId ? (int)$post->pinnedUpdateId : null,
            'updatedAt' => time(),
            'poll' => (int)$settings->pollInterval,
            'updates' => array_map(fn(Update $u) => [
                'id' => (int)$u->id,
                'seq' => (int)$u->seq,
                'rev' => (int)$u->metaValue('rev', 0),
            ], $updates),
            // Sequence numbers that have been deleted or held, so a reader can take them back down
            // without reloading the page.
            'removed' => array_values(array_slice($carriedRemovals ?? [], -50)),
        ];
    }

    // Body HTML
    // -------------------------------------------------------------------------

    /**
     * Clean the composer's HTML. The composer writes a deliberately small vocabulary — this is the
     * server-side half of that promise, and it runs on every path into an update, not just the CP.
     */
    public function purifyBody(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        return $this->getPurifier()->purify($html);
    }

    private function getPurifier(): HTMLPurifier
    {
        if ($this->_purifier !== null) {
            return $this->_purifier;
        }

        $settings = Plugin::getInstance()->getSettings();

        $config = HTMLPurifier_Config::createDefault();
        $config->autoFinalize = false;
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.EnableID', false);
        $config->set('HTML.Allowed', $settings->bodyAllowedHtml ?: implode(',', [
            'p', 'br', 'strong', 'em', 'u', 's', 'code', 'pre',
            'a[href|title|target|rel]',
            'ul', 'ol', 'li', 'blockquote',
            'h3', 'h4',
            // `loading` isn't in HTMLPurifier's vocabulary and asking for it is a warning, not a no-op.
            'img[src|alt|width|height]',
        ]));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        // HTMLPurifier warns rather than creates, and the warning becomes an exception under
        // Craft's error handler — which would take a publish down with it.
        $cachePath = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'live-purifier';

        if (!is_dir($cachePath)) {
            FileHelper::createDirectory($cachePath);
        }

        $config->set('Cache.SerializerPath', $cachePath);

        return $this->_purifier = new HTMLPurifier($config);
    }

    /**
     * Turn what an editor typed into the body HTML.
     *
     * The composer's box is a plain textarea, not a rich-text editor, and that is a deliberate
     * choice: an editor covering a match types a sentence and presses ⌘↵, forty times an hour, and
     * every millisecond a CKEditor instance spends booting is a millisecond in the way. Markdown
     * covers the formatting anyone actually uses live — bold, italics, a link, a list — and the
     * result goes through the same purifier as everything else.
     */
    public function renderMarkdown(?string $source): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        // gfm-comment: single newlines become line breaks, which is how people type live.
        return $this->purifyBody(Markdown::process($source, 'gfm-comment'));
    }

    /**
     * Human-friendly relative time used by the CP feed and shipped templates.
     */
    public function timeLabel(Update $update): string
    {
        if (!$update->postedAt) {
            return '';
        }

        $settings = Plugin::getInstance()->getSettings();

        return DateTimeHelper::toDateTime($update->postedAt)->format($settings->timestampFormat);
    }
}
