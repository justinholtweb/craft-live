<?php

namespace justinholtweb\live\elements\db;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use DateTime;
use justinholtweb\live\db\Table;
use justinholtweb\live\elements\Update;
use justinholtweb\live\Plugin;

/**
 * @method Update[]|array all($db = null)
 * @method Update|array|null one($db = null)
 * @method Update|array|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class UpdateQuery extends ElementQuery
{
    public mixed $postId = null;
    public mixed $fieldId = null;
    public mixed $typeId = null;
    public mixed $authorId = null;
    public mixed $clientId = null;
    public mixed $seq = null;
    public ?bool $pinned = null;
    public ?bool $highlight = null;
    public mixed $postedAt = null;

    /** Everything published after this sequence number — the delta query the poll endpoint runs. */
    public ?int $since = null;

    /** Everything at or below this sequence number — “load older”. */
    public ?int $before = null;

    protected array $defaultOrderBy = ['live_updates.seq' => SORT_DESC];

    /**
     * Scope to a live post. Accepts the owner element or its ID.
     */
    public function post(ElementInterface|int|array|null $value): static
    {
        if ($value instanceof ElementInterface) {
            $this->postId = $value->id;
            // An update only ever exists on one site, and it is the owner's.
            $this->siteId ??= $value->siteId;
        } else {
            $this->postId = $value;
        }

        return $this;
    }

    public function postId(mixed $value): static
    {
        $this->postId = $value;
        return $this;
    }

    public function fieldId(mixed $value): static
    {
        $this->fieldId = $value;
        return $this;
    }

    /**
     * Filter by update type. Takes handles, IDs, models, or an array of any of those.
     */
    public function type(mixed $value): static
    {
        if ($value === null) {
            $this->typeId = null;
            return $this;
        }

        $ids = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if ($item instanceof \justinholtweb\live\models\UpdateType) {
                $ids[] = $item->id;
            } elseif (is_numeric($item)) {
                $ids[] = (int)$item;
            } elseif (is_string($item)) {
                $type = Plugin::getInstance()->updateTypes->getTypeByHandle($item);
                // A handle nobody has defined should match nothing, not everything.
                $ids[] = $type?->id ?? -1;
            }
        }

        $this->typeId = $ids ?: null;

        return $this;
    }

    public function typeId(mixed $value): static
    {
        $this->typeId = $value;
        return $this;
    }

    public function authorId(mixed $value): static
    {
        $this->authorId = $value;
        return $this;
    }

    public function clientId(mixed $value): static
    {
        $this->clientId = $value;
        return $this;
    }

    public function seq(mixed $value): static
    {
        $this->seq = $value;
        return $this;
    }

    public function since(?int $value): static
    {
        $this->since = $value;
        return $this;
    }

    public function before(?int $value): static
    {
        $this->before = $value;
        return $this;
    }

    public function pinned(?bool $value = true): static
    {
        $this->pinned = $value;
        return $this;
    }

    public function highlight(?bool $value = true): static
    {
        $this->highlight = $value;
        return $this;
    }

    public function postedAt(mixed $value): static
    {
        $this->postedAt = $value;
        return $this;
    }

    /**
     * Oldest first — a running commentary rather than a news feed.
     */
    public function chronological(bool $value = true): static
    {
        $this->orderBy(['live_updates.seq' => $value ? SORT_ASC : SORT_DESC]);
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('live_updates');

        $this->query->select([
            'live_updates.postId',
            'live_updates.fieldId',
            'live_updates.typeId',
            'live_updates.seq',
            'live_updates.body',
            'live_updates.postedAt',
            'live_updates.pinned',
            'live_updates.highlight',
            'live_updates.authorId',
            'live_updates.clientId',
            'live_updates.meta',
        ]);

        if ($this->postId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('live_updates.postId', $this->postId));
        }

        if ($this->fieldId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('live_updates.fieldId', $this->fieldId));
        }

        if ($this->typeId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('live_updates.typeId', $this->typeId));
        }

        if ($this->authorId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('live_updates.authorId', $this->authorId));
        }

        if ($this->clientId !== null) {
            $this->subQuery->andWhere(Db::parseParam('live_updates.clientId', $this->clientId));
        }

        if ($this->seq !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('live_updates.seq', $this->seq));
        }

        if ($this->since !== null) {
            $this->subQuery->andWhere(['>', 'live_updates.seq', $this->since]);
        }

        if ($this->before !== null) {
            $this->subQuery->andWhere(['<', 'live_updates.seq', $this->before]);
        }

        if ($this->pinned !== null) {
            $this->subQuery->andWhere(['live_updates.pinned' => $this->pinned]);
        }

        if ($this->highlight !== null) {
            $this->subQuery->andWhere(['live_updates.highlight' => $this->highlight]);
        }

        if ($this->postedAt !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('live_updates.postedAt', $this->postedAt));
        }

        return true;
    }

    protected function statusCondition(string $status): mixed
    {
        $now = Db::prepareDateForDb(new DateTime());

        return match ($status) {
            Update::STATUS_PUBLISHED => [
                'and',
                ['elements.enabled' => true, 'elements_sites.enabled' => true],
                ['<=', 'live_updates.postedAt', $now],
            ],
            Update::STATUS_SCHEDULED => [
                'and',
                ['elements.enabled' => true, 'elements_sites.enabled' => true],
                ['>', 'live_updates.postedAt', $now],
            ],
            default => parent::statusCondition($status),
        };
    }

    /**
     * The highest sequence number this post has reached — the cheapest possible “has anything
     * changed?”, answered without hydrating a single element.
     */
    public function maxSeq(): int
    {
        if ($this->postId === null) {
            return 0;
        }

        $query = (new Query())
            ->from(['u' => Table::UPDATES])
            ->where(Db::parseNumericParam('u.postId', $this->postId));

        if ($this->fieldId !== null) {
            $query->andWhere(Db::parseNumericParam('u.fieldId', $this->fieldId));
        }

        if ($this->siteId !== null && !is_array($this->siteId)) {
            $query->andWhere(Db::parseNumericParam('u.siteId', $this->siteId));
        }

        return (int)$query->max('[[u.seq]]');
    }
}
