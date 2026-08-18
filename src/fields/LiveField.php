<?php

namespace justinholtweb\live\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use GraphQL\Type\Definition\Type;
use justinholtweb\live\elements\Update;
use justinholtweb\live\gql\types\LiveFeedGqlType;
use justinholtweb\live\models\LiveFeed;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\Plugin;
use justinholtweb\live\web\assets\composer\ComposerAsset;

/**
 * The field that makes an entry a live post.
 *
 * It stores nothing. Updates are elements of their own, keyed on the owner's ID, so adding this
 * field to an entry type is free and removing it loses nothing. What the field carries is
 * configuration — which update types this feed accepts, which way round it reads — and, in the
 * control panel, the composer itself.
 */
class LiveField extends Field
{
    public const ORDER_NEWEST = 'newest';
    public const ORDER_OLDEST = 'oldest';

    /** Update type UIDs this feed accepts. Empty means all of them. */
    public array $updateTypeUids = [];

    /** Which way the feed reads. Newest-first for news, oldest-first for a running commentary. */
    public string $order = self::ORDER_NEWEST;

    /** How many updates the composer loads at a time. */
    public int $pageSize = 50;

    /** Show other editors working on the same post (Pro). */
    public bool $showPresence = true;

    /** Offer a “post at” time, for updates written ahead of the moment (Pro). */
    public bool $allowScheduled = false;

    public static function displayName(): string
    {
        return Craft::t('live', 'Live');
    }

    public static function icon(): string
    {
        return 'tower-broadcast';
    }

    public static function dbType(): array|string|null
    {
        // Nothing is stored in the content column: the updates are elements.
        return null;
    }

    public static function isRequirable(): bool
    {
        return false;
    }

    public function useFieldset(): bool
    {
        return true;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['order'], 'in', 'range' => [self::ORDER_NEWEST, self::ORDER_OLDEST]];
        $rules[] = [['pageSize'], 'integer', 'min' => 5, 'max' => 500];
        $rules[] = [['showPresence', 'allowScheduled'], 'boolean'];

        return $rules;
    }

    // Value
    // -------------------------------------------------------------------------

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof LiveFeed) {
            return $value;
        }

        return new LiveFeed([
            'owner' => $element,
            'field' => $this,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return null;
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof LiveFeed || $value->count() === 0;
    }

    /**
     * A live blog is not something anyone finds by searching the parent entry for a word somebody
     * typed into it at 4pm, and indexing it would mean rewriting the entry's index rows on every
     * publish — the exact cost this plugin exists to avoid.
     */
    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    // Settings
    // -------------------------------------------------------------------------

    public function getSettingsHtml(): ?string
    {
        $types = Plugin::getInstance()->updateTypes->getAllTypes();

        return Craft::$app->getView()->renderTemplate('live/_field-settings', [
            'field' => $this,
            'types' => $types,
        ]);
    }

    /**
     * The update types this feed accepts, in composer order.
     *
     * @return \justinholtweb\live\models\UpdateType[]
     */
    public function getAllowedTypes(): array
    {
        $all = Plugin::getInstance()->updateTypes->getComposerTypes();

        if (!$this->updateTypeUids) {
            return $all;
        }

        return array_values(array_filter($all, fn($type) => in_array($type->uid, $this->updateTypeUids, true)));
    }

    // Input
    // -------------------------------------------------------------------------

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if (!$element?->id) {
            return Html::tag('div', Craft::t('live', 'Save this entry first — then you can start posting.'), [
                'class' => 'zilch small',
            ]);
        }

        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_PUBLISH)) {
            return Html::tag('div', Craft::t('live', 'You don’t have permission to post live updates.'), [
                'class' => 'zilch small',
            ]);
        }

        $types = $this->getAllowedTypes();

        if (!$types) {
            return Html::tag('div', Craft::t('live', 'No update types are available to this field.'), [
                'class' => 'zilch small',
            ]);
        }

        /** @var LiveFeed $value */
        $post = Plugin::getInstance()->posts->ensurePost((int)$element->id, (int)$this->id, (int)$element->siteId);

        $view = Craft::$app->getView();
        $view->registerAssetBundle(ComposerAsset::class);

        return $view->renderTemplate('live/_composer', [
            'field' => $this,
            'element' => $element,
            'post' => $post,
            'types' => $types,
            'config' => $this->composerConfig($element, $post),
            'updates' => $this->recentUpdates($element),
            'studioUrl' => \craft\helpers\UrlHelper::cpUrl("live/post/$element->id", [
                'fieldId' => $this->id,
                'site' => $element->getSite()->handle,
            ]),
        ]);
    }

    /**
     * @return Update[]
     */
    public function recentUpdates(ElementInterface $element, ?int $limit = null): array
    {
        $query = Update::find()
            ->postId($element->id)
            ->fieldId($this->id)
            ->siteId($element->siteId)
            ->status(null)
            ->limit($limit ?? $this->pageSize);

        if ($this->order === self::ORDER_OLDEST) {
            $query->chronological();
        }

        return $query->all();
    }

    public function composerConfig(ElementInterface $element, LivePost $post): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $user = Craft::$app->getUser()->getIdentity();

        return [
            'postId' => (int)$element->id,
            'fieldId' => (int)$this->id,
            'siteId' => (int)$element->siteId,
            'seq' => (int)$post->seq,
            'state' => $post->state,
            'order' => $this->order,
            'pageSize' => (int)$this->pageSize,
            'pollInterval' => (int)$settings->composerPollInterval * 1000,
            'presence' => $this->showPresence && $settings->presenceEnabled && Plugin::getInstance()->isPro(),
            'presenceInterval' => max(5, (int)floor($settings->presenceTtl / 3)) * 1000,
            'allowScheduled' => $this->allowScheduled && $settings->allowScheduled && Plugin::getInstance()->isPro(),
            'canDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE),
            'canControl' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_CONTROL),
            'userId' => (int)($user?->id ?? 0),
            'userName' => $user?->friendlyName ?? '',
            'types' => array_map(fn($type) => [
                'id' => (int)$type->id,
                'handle' => $type->handle,
                'name' => $type->name,
                'color' => $type->color,
                'icon' => $type->icon,
                'hasTitleField' => (bool)$type->hasTitleField,
                'hasFields' => !empty($type->getFieldLayout()->getCustomFields()),
            ], $this->getAllowedTypes()),
        ];
    }

    // GraphQL
    // -------------------------------------------------------------------------

    /**
     * The field's own GraphQL type, so a headless site can read a feed straight off its entry:
     * `entry { commentary { seq state updates { body } } }`.
     */
    public function getContentGqlType(): Type|array
    {
        return LiveFeedGqlType::getType();
    }

    // Clean-up
    // -------------------------------------------------------------------------

    /**
     * When the owner goes, so does its feed's snapshot directory — otherwise a deleted match report
     * carries on serving updates as static JSON forever.
     */
    public function afterElementDelete(ElementInterface $element): void
    {
        $post = Plugin::getInstance()->posts->getPost((int)$element->id, (int)$this->id, (int)$element->siteId);

        if ($post) {
            Plugin::getInstance()->snapshots->deletePost($post);
        }

        parent::afterElementDelete($element);
    }

    public function afterElementRestore(ElementInterface $element): void
    {
        $post = Plugin::getInstance()->posts->getPost((int)$element->id, (int)$this->id, (int)$element->siteId);

        if ($post) {
            Plugin::getInstance()->snapshots->rebuild($post);
        }

        parent::afterElementRestore($element);
    }
}
