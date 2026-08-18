<?php

namespace justinholtweb\live\models;

use Countable;
use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use IteratorAggregate;
use justinholtweb\live\elements\db\UpdateQuery;
use justinholtweb\live\elements\Update;
use justinholtweb\live\fields\LiveField;
use justinholtweb\live\Plugin;
use Traversable;

/**
 * What a Live field hands to Twig.
 *
 * ```twig
 * {% for update in entry.commentary.updates.limit(50).all() %}
 * ```
 *
 * The feed is lazy: nothing is queried until a template asks for updates, so an entry that happens
 * to carry a Live field costs nothing on pages that don't show it.
 */
class LiveFeed extends Model implements IteratorAggregate, Countable
{
    public ?ElementInterface $owner = null;
    public ?LiveField $field = null;

    private LivePost|false|null $_post = null;

    /**
     * The head row: state, sequence, counts. Null until the first update is published.
     */
    public function getPost(): ?LivePost
    {
        if ($this->_post !== null) {
            return $this->_post ?: null;
        }

        if (!$this->owner?->id || !$this->field?->id) {
            $this->_post = false;

            return null;
        }

        $post = Plugin::getInstance()->posts->getPost(
            (int)$this->owner->id,
            (int)$this->field->id,
            (int)$this->owner->siteId,
        );

        $this->_post = $post ?? false;

        return $post;
    }

    /**
     * The updates, as a query — so templates can go on to filter, paginate or eager-load.
     */
    public function getUpdates(): UpdateQuery
    {
        /** @var UpdateQuery $query */
        $query = Update::find()
            ->postId($this->owner?->id ?? -1)
            ->fieldId($this->field?->id ?? -1)
            ->siteId($this->owner?->siteId)
            ->status(Update::STATUS_PUBLISHED);

        if ($this->field?->order === LiveField::ORDER_OLDEST) {
            $query->chronological();
        }

        return $query;
    }

    /** Alias so `entry.feed.updates()` and `entry.feed.updates` both work. */
    public function updates(): UpdateQuery
    {
        return $this->getUpdates();
    }

    /**
     * The pinned update, if one is.
     */
    public function getPinned(): ?Update
    {
        $post = $this->getPost();

        if (!$post?->pinnedUpdateId) {
            return null;
        }

        return Update::find()
            ->id($post->pinnedUpdateId)
            ->siteId($this->owner?->siteId)
            ->status(Update::STATUS_PUBLISHED)
            ->one();
    }

    /**
     * Updates flagged as key moments — the “what happened” summary at the top of a long feed.
     */
    public function getHighlights(): UpdateQuery
    {
        return $this->getUpdates()->highlight(true);
    }

    /**
     * The current sequence number. Cheap: one indexed row.
     *
     * Use it as a cache key and a `{% cache %}` never goes stale:
     * `{% cache using key "feed-" ~ entry.id ~ "-" ~ entry.commentary.seq %}`
     */
    public function getSeq(): int
    {
        return (int)($this->getPost()?->seq ?? 0);
    }

    public function getState(): string
    {
        return $this->getPost()?->state ?? LivePost::STATE_UPCOMING;
    }

    public function getStateLabel(): string
    {
        return $this->getPost()?->getStateLabel() ?? Craft::t('live', 'Upcoming');
    }

    public function getIsLive(): bool
    {
        return (bool)$this->getPost()?->getIsLive();
    }

    /** Whether a reader should still be watching. False once the post has ended. */
    public function getIsFollowable(): bool
    {
        return (bool)$this->getPost()?->getIsFollowable();
    }

    public function getIsEnded(): bool
    {
        return (bool)$this->getPost()?->getIsEnded();
    }

    public function getStartedAt(): ?\DateTime
    {
        return $this->getPost()?->startedAt;
    }

    public function getEndedAt(): ?\DateTime
    {
        return $this->getPost()?->endedAt;
    }

    public function count(): int
    {
        return (int)($this->getPost()?->updateCount ?? 0);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->getUpdates()->all());
    }

    /**
     * Where readers poll. Static file if snapshots are on, the action endpoint otherwise.
     */
    public function getHeadUrl(): ?string
    {
        $post = $this->getPost();

        if (!$post) {
            return null;
        }

        if (Plugin::getInstance()->snapshots->isEnabled()) {
            return Plugin::getInstance()->snapshots->getHeadUrl($post);
        }

        return \craft\helpers\UrlHelper::actionUrl('live/feed/head', [
            'post' => $post->postId,
            'field' => $post->fieldId,
            'site' => $post->siteId,
        ]);
    }

    /**
     * Everything `<live-feed>` needs, ready to be printed as a JSON attribute.
     */
    public function getClientConfig(): array
    {
        $post = $this->getPost();
        $settings = Plugin::getInstance()->getSettings();

        return [
            'post' => (int)($this->owner?->id ?? 0),
            'field' => (int)($this->field?->id ?? 0),
            'site' => (int)($this->owner?->siteId ?? 0),
            'seq' => $this->getSeq(),
            'state' => $this->getState(),
            'head' => $this->getHeadUrl(),
            'base' => $post && Plugin::getInstance()->snapshots->isEnabled()
                ? Plugin::getInstance()->snapshots->getPostUrl($post)
                : null,
            'fetch' => \craft\helpers\UrlHelper::actionUrl('live/feed/since'),
            'interval' => (int)$settings->pollInterval,
            'order' => $this->field?->order ?? LiveField::ORDER_NEWEST,
            'sse' => $settings->sseEnabled && Plugin::getInstance()->isPro()
                ? \craft\helpers\UrlHelper::actionUrl('live/feed/stream')
                : null,
        ];
    }
}
