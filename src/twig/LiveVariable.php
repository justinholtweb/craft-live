<?php

namespace justinholtweb\live\twig;

use craft\base\ElementInterface;
use craft\helpers\Template;
use justinholtweb\live\elements\db\UpdateQuery;
use justinholtweb\live\elements\Update;
use justinholtweb\live\fields\LiveField;
use justinholtweb\live\models\LiveFeed;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\models\UpdateType;
use justinholtweb\live\Plugin;
use Twig\Markup;

/**
 * `craft.live` — everything a template needs, without having to know a field handle.
 */
class LiveVariable
{
    /**
     * The feed on an element.
     *
     * ```twig
     * {% set feed = craft.live.feed(entry) %}
     * ```
     *
     * Pass a handle when an entry carries more than one.
     */
    public function feed(ElementInterface $element, ?string $fieldHandle = null): ?LiveFeed
    {
        if ($fieldHandle !== null) {
            $value = $element->getFieldValue($fieldHandle);

            return $value instanceof LiveFeed ? $value : null;
        }

        $field = Plugin::getInstance()->fields->getFieldForElement($element);

        if (!$field) {
            return null;
        }

        $value = $element->getFieldValue($field->handle);

        return $value instanceof LiveFeed ? $value : null;
    }

    /**
     * A bare update query, for feeds assembled across posts — “every goal today”, a network ticker.
     */
    public function updates(array $criteria = []): UpdateQuery
    {
        /** @var UpdateQuery $query */
        $query = Update::find();
        $query->status(Update::STATUS_PUBLISHED);

        if ($criteria) {
            \Craft::configure($query, $criteria);
        }

        return $query;
    }

    /**
     * Live posts across the site — a “what's on right now” rail.
     *
     * @return LivePost[]
     */
    public function posts(?string $state = LivePost::STATE_LIVE, ?int $siteId = null): array
    {
        return Plugin::getInstance()->posts->getAllPosts($siteId ?? \Craft::$app->getSites()->getCurrentSite()->id, $state);
    }

    /**
     * @return UpdateType[]
     */
    public function types(): array
    {
        return Plugin::getInstance()->updateTypes->getAllTypes();
    }

    public function type(string $handle): ?UpdateType
    {
        return Plugin::getInstance()->updateTypes->getTypeByHandle($handle);
    }

    /**
     * The current sequence number for an element's feed — the cache key that makes a `{% cache %}`
     * around a live feed safe.
     */
    public function seq(ElementInterface $element, ?string $fieldHandle = null): int
    {
        return $this->feed($element, $fieldHandle)?->getSeq() ?? 0;
    }

    /**
     * Whether an element is live right now, cheaply enough to call in a listing template.
     */
    public function isLive(ElementInterface $element, ?string $fieldHandle = null): bool
    {
        return (bool)$this->feed($element, $fieldHandle)?->getIsLive();
    }

    /**
     * The `<live-feed>` element's attributes, ready to spread into a tag.
     *
     * ```twig
     * <live-feed {{ craft.live.attributes(entry)|raw }}>
     * ```
     */
    public function attributes(ElementInterface $element, ?string $fieldHandle = null): Markup
    {
        $feed = $this->feed($element, $fieldHandle);

        if (!$feed) {
            return Template::raw('');
        }

        $config = json_encode($feed->getClientConfig(), JSON_UNESCAPED_SLASHES);

        return Template::raw('data-live-config="' . htmlspecialchars($config, ENT_QUOTES, 'UTF-8') . '"');
    }

    /**
     * The plugin's own field type, so templates can test for it.
     */
    public function fieldClass(): string
    {
        return LiveField::class;
    }
}
