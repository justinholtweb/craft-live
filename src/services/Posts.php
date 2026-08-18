<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\live\db\Table;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\Plugin;
use Throwable;
use yii\db\Expression;

/**
 * The head row per live post — created on demand, updated on every publish.
 */
class Posts extends Component
{
    /** @var array<string,LivePost|false> */
    private array $_cache = [];

    /**
     * The head row, or null if this post has never had one.
     */
    public function getPost(int $postId, int $fieldId, int $siteId): ?LivePost
    {
        $key = "$postId:$fieldId:$siteId";

        if (isset($this->_cache[$key])) {
            return $this->_cache[$key] ?: null;
        }

        $row = $this->createQuery()
            ->where(['postId' => $postId, 'fieldId' => $fieldId, 'siteId' => $siteId])
            ->one();

        $post = $row ? $this->rowToModel($row) : false;
        $this->_cache[$key] = $post;

        return $post ?: null;
    }

    /**
     * The head row, creating it if this post has not been used yet.
     */
    public function ensurePost(int $postId, int $fieldId, int $siteId): LivePost
    {
        $post = $this->getPost($postId, $fieldId, $siteId);

        if ($post) {
            return $post;
        }

        $now = Db::prepareDateForDb(new DateTime());

        Db::upsert(Table::POSTS, [
            'postId' => $postId,
            'fieldId' => $fieldId,
            'siteId' => $siteId,
        ], [
            'dateUpdated' => $now,
        ]);

        unset($this->_cache["$postId:$fieldId:$siteId"]);

        // Read it back rather than trusting a constructed model: a concurrent request may have
        // created it first, and its seq is then already non-zero.
        return $this->getPost($postId, $fieldId, $siteId)
            ?? new LivePost(['postId' => $postId, 'fieldId' => $fieldId, 'siteId' => $siteId]);
    }

    /**
     * Reserve the next sequence number for a post.
     *
     * The increment and the read happen inside one transaction, so the row lock the UPDATE takes is
     * still held when the value is read back: two editors hitting publish in the same millisecond
     * get 412 and 413, never 412 twice. Nothing else in the publish path needs to be serialised.
     */
    public function allocateSeq(int $postId, int $fieldId, int $siteId): int
    {
        $this->ensurePost($postId, $fieldId, $siteId);

        $db = Craft::$app->getDb();
        $condition = ['postId' => $postId, 'fieldId' => $fieldId, 'siteId' => $siteId];

        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()
                ->update(Table::POSTS, [
                    'seq' => new Expression('[[seq]] + 1'),
                    'updateCount' => new Expression('[[updateCount]] + 1'),
                    'lastPublishedAt' => Db::prepareDateForDb(new DateTime()),
                    'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                ], $condition)
                ->execute();

            $seq = (int)(new Query())
                ->select(['seq'])
                ->from([Table::POSTS])
                ->where($condition)
                ->scalar($db);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        unset($this->_cache["$postId:$fieldId:$siteId"]);

        return $seq;
    }

    /**
     * Move a post through its life cycle. Returns the updated head row.
     */
    public function setState(LivePost $post, string $state): LivePost
    {
        if (!in_array($state, LivePost::STATES, true)) {
            throw new \InvalidArgumentException("Invalid live post state: $state");
        }

        $values = ['state' => $state, 'dateUpdated' => Db::prepareDateForDb(new DateTime())];

        if ($state === LivePost::STATE_LIVE && !$post->startedAt) {
            $values['startedAt'] = Db::prepareDateForDb(new DateTime());
        }

        if ($state === LivePost::STATE_ENDED) {
            $values['endedAt'] = Db::prepareDateForDb(new DateTime());
        } elseif ($post->endedAt) {
            // Reopening a post that was called too early.
            $values['endedAt'] = null;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::POSTS, $values, ['id' => $post->id])
            ->execute();

        $this->flushCacheFor($post);

        return $this->getPost($post->postId, $post->fieldId, $post->siteId) ?? $post;
    }

    public function setPinnedUpdateId(LivePost $post, ?int $updateId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::POSTS, [
                'pinnedUpdateId' => $updateId,
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $post->id])
            ->execute();

        $this->flushCacheFor($post);
    }

    public function setSnapshotSeq(LivePost $post, int $seq): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::POSTS, ['snapshotSeq' => $seq], ['id' => $post->id])
            ->execute();

        $this->flushCacheFor($post);
    }

    /**
     * Decrement the counter when an update is deleted. The sequence itself never rewinds — gaps are
     * fine, and reusing a number would confuse every reader that has already seen it.
     */
    public function decrementCount(LivePost $post): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::POSTS, [
                'updateCount' => new Expression('GREATEST([[updateCount]] - 1, 0)'),
            ], ['id' => $post->id])
            ->execute();

        $this->flushCacheFor($post);
    }

    /**
     * Every live post on a site, newest activity first — the CP index.
     *
     * @return LivePost[]
     */
    public function getAllPosts(?int $siteId = null, ?string $state = null): array
    {
        $query = $this->createQuery()->orderBy(['lastPublishedAt' => SORT_DESC, 'id' => SORT_DESC]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($state !== null) {
            $query->andWhere(['state' => $state]);
        }

        return array_map(fn(array $row) => $this->rowToModel($row), $query->all());
    }

    /**
     * The head row for an owner element, resolved through whichever Live field it carries.
     */
    public function getPostForElement(ElementInterface $owner, ?int $fieldId = null): ?LivePost
    {
        $fieldId ??= Plugin::getInstance()->fields->getFieldIdForElement($owner);

        if (!$fieldId || !$owner->id || !$owner->siteId) {
            return null;
        }

        return $this->getPost($owner->id, $fieldId, $owner->siteId);
    }

    public function flushCacheFor(LivePost $post): void
    {
        unset($this->_cache["$post->postId:$post->fieldId:$post->siteId"]);
    }

    private function createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'postId', 'fieldId', 'siteId', 'state', 'seq', 'updateCount', 'pinnedUpdateId',
                'snapshotSeq', 'startedAt', 'endedAt', 'lastPublishedAt', 'uid',
            ])
            ->from([Table::POSTS]);
    }

    private function rowToModel(array $row): LivePost
    {
        foreach (['startedAt', 'endedAt', 'lastPublishedAt'] as $attribute) {
            if (!empty($row[$attribute])) {
                $row[$attribute] = DateTimeHelper::toDateTime($row[$attribute]) ?: null;
            } else {
                $row[$attribute] = null;
            }
        }

        return new LivePost($row);
    }
}
