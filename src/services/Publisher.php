<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\Db;
use DateTime;
use justinholtweb\live\db\Table;
use justinholtweb\live\elements\Update;
use justinholtweb\live\events\UpdateEvent;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\Plugin;
use Throwable;
use yii\base\InvalidArgumentException;

/**
 * The write path — everything that happens between an editor hitting ⌘↵ and the update being
 * readable on the site.
 *
 * The order matters. The sequence number is allocated first, under a row lock, so the update's
 * place in the feed is decided before anything slow happens. The element is then saved with the
 * search index skipped and propagation off, because an update lives on exactly one site and nobody
 * searches a live blog by keyword. Snapshots are written last, and a failure there is logged rather
 * than thrown: the update is already in the database, and the poll endpoint can serve it regardless.
 */
class Publisher extends Component
{
    /** @event UpdateEvent Raised before an update is published. Cancellable. */
    public const EVENT_BEFORE_PUBLISH = 'beforePublish';

    /** @event UpdateEvent Raised after an update is published and its snapshot written. */
    public const EVENT_AFTER_PUBLISH = 'afterPublish';

    /** @event UpdateEvent Raised after an existing update is edited. */
    public const EVENT_AFTER_EDIT = 'afterEdit';

    /** @event UpdateEvent Raised after an update is deleted. */
    public const EVENT_AFTER_DELETE = 'afterDelete';

    /**
     * Publish a new update.
     */
    public function publish(Update $update, bool $runValidation = true): bool
    {
        if ($update->id) {
            return $this->edit($update, $runValidation);
        }

        $this->prepare($update);

        $post = $this->resolvePost($update);

        $event = new UpdateEvent(['update' => $update, 'post' => $post, 'isNew' => true]);
        $this->trigger(self::EVENT_BEFORE_PUBLISH, $event);

        if (!$event->isValid) {
            return false;
        }

        // Allocated before the save, so ordering is settled even if the save then takes its time
        // over a large asset field.
        $update->seq = Plugin::getInstance()->posts->allocateSeq(
            $post->postId,
            $post->fieldId,
            $post->siteId,
        );

        $update->setMeta(['rev' => 0] + $update->getMeta());

        try {
            $saved = Craft::$app->getElements()->saveElement($update, $runValidation, false, false);
        } catch (Throwable $e) {
            Plugin::getInstance()->posts->decrementCount($post);
            throw $e;
        }

        if (!$saved) {
            // Give the counter back. The sequence number itself is spent — gaps are harmless, and
            // reusing one would hand two different updates the same address.
            Plugin::getInstance()->posts->decrementCount($post);

            return false;
        }

        $post = Plugin::getInstance()->posts->getPost($post->postId, $post->fieldId, $post->siteId) ?? $post;

        if ($update->pinned) {
            $this->pin($update, true);
        }

        Plugin::getInstance()->snapshots->publish($post, $update);
        $this->afterChange($post, $update);

        $this->trigger(self::EVENT_AFTER_PUBLISH, new UpdateEvent([
            'update' => $update,
            'post' => $post,
            'isNew' => true,
        ]));

        return true;
    }

    /**
     * Save changes to an update that is already out there.
     *
     * The sequence number never changes — a correction stays where it was in the feed, because
     * readers have already seen it there. What changes is `rev`, which is how a reader that is
     * holding the old copy knows to come back for the new one.
     */
    public function edit(Update $update, bool $runValidation = true): bool
    {
        if (!$update->id) {
            return $this->publish($update, $runValidation);
        }

        $this->prepare($update);

        $post = $this->resolvePost($update);

        $meta = $update->getMeta();
        $meta['rev'] = (int)($meta['rev'] ?? 0) + 1;
        $meta['editedAt'] = (new DateTime())->format(DATE_ATOM);
        $update->setMeta($meta);

        if (!Craft::$app->getElements()->saveElement($update, $runValidation, false, false)) {
            return false;
        }

        if ($update->pinned) {
            $this->pin($update, true);
        } elseif ($post->pinnedUpdateId === (int)$update->id) {
            Plugin::getInstance()->posts->setPinnedUpdateId($post, null);
        }

        $post = Plugin::getInstance()->posts->getPost($post->postId, $post->fieldId, $post->siteId) ?? $post;

        // A held or scheduled update has to come *off* the site, not be rewritten onto it.
        if ($update->getIsPublished()) {
            Plugin::getInstance()->snapshots->publish($post, $update);
        } else {
            Plugin::getInstance()->snapshots->remove($post, (int)$update->seq);
        }

        $this->afterChange($post, $update);

        $this->trigger(self::EVENT_AFTER_EDIT, new UpdateEvent([
            'update' => $update,
            'post' => $post,
            'isNew' => false,
        ]));

        return true;
    }

    /**
     * Delete an update. Soft — it goes to the trash like any other element, and can be restored.
     */
    public function delete(Update $update): bool
    {
        $post = $this->resolvePost($update);
        $seq = (int)$update->seq;

        if (!Craft::$app->getElements()->deleteElement($update, false)) {
            return false;
        }

        if ($post->pinnedUpdateId === (int)$update->id) {
            Plugin::getInstance()->posts->setPinnedUpdateId($post, null);
        }

        Plugin::getInstance()->posts->decrementCount($post);

        $post = Plugin::getInstance()->posts->getPost($post->postId, $post->fieldId, $post->siteId) ?? $post;

        Plugin::getInstance()->snapshots->remove($post, $seq);
        $this->afterChange($post, $update);

        $this->trigger(self::EVENT_AFTER_DELETE, new UpdateEvent([
            'update' => $update,
            'post' => $post,
            'isNew' => false,
        ]));

        return true;
    }

    /**
     * Pin an update to the top of the feed, or unpin it.
     *
     * Only one update can be pinned at a time, and the unpinning of the previous one is done in a
     * single UPDATE rather than by loading and re-saving each sibling — the Counterpress version
     * re-saved every block in the post to unpin one, which on a busy match is hundreds of writes.
     */
    public function pin(Update $update, bool $pinned = true): bool
    {
        $post = $this->resolvePost($update);
        $db = Craft::$app->getDb();

        if ($pinned) {
            $db->createCommand()
                ->update(Table::UPDATES, ['pinned' => false], [
                    'and',
                    ['postId' => $post->postId, 'fieldId' => $post->fieldId, 'siteId' => $post->siteId, 'pinned' => true],
                    ['not', ['id' => $update->id]],
                ])
                ->execute();
        }

        $db->createCommand()
            ->update(Table::UPDATES, ['pinned' => $pinned], ['id' => $update->id])
            ->execute();

        $update->pinned = $pinned;

        Plugin::getInstance()->posts->setPinnedUpdateId($post, $pinned ? (int)$update->id : null);

        $post = Plugin::getInstance()->posts->getPost($post->postId, $post->fieldId, $post->siteId) ?? $post;

        // Both the pinned and the formerly pinned update need rewriting, and the head has to learn
        // which one it is now — a full rewrite of the window is cheaper than working out which.
        Plugin::getInstance()->snapshots->writeHead($post);
        Plugin::getInstance()->snapshots->publish($post, $update);
        $this->afterChange($post, $update);

        return true;
    }

    /**
     * Move a post between upcoming, live, paused and ended.
     */
    public function setState(LivePost $post, string $state): LivePost
    {
        $post = Plugin::getInstance()->posts->setState($post, $state);

        Plugin::getInstance()->snapshots->writeHead($post);
        $this->afterChange($post, null);

        return $post;
    }

    /**
     * An update already published under this client ID, if there is one.
     *
     * The composer keeps a local queue so a journalist on hotel wifi can keep typing through a
     * dropout, and a queue that retries will eventually retry something that did in fact land.
     */
    public function findByClientId(int $postId, int $fieldId, int $siteId, string $clientId): ?Update
    {
        return Update::find()
            ->postId($postId)
            ->fieldId($fieldId)
            ->siteId($siteId)
            ->clientId($clientId)
            ->status(null)
            ->one();
    }

    // Internals
    // -------------------------------------------------------------------------

    private function prepare(Update $update): void
    {
        $update->body = Plugin::getInstance()->feeds->purifyBody($update->body);
        $update->postedAt ??= new DateTime();
        $update->authorId ??= Craft::$app->getUser()->getIdentity()?->id;
    }

    private function resolvePost(Update $update): LivePost
    {
        if (!$update->postId || !$update->fieldId || !$update->siteId) {
            throw new InvalidArgumentException('An update needs a post, a field and a site.');
        }

        return Plugin::getInstance()->posts->ensurePost($update->postId, $update->fieldId, $update->siteId);
    }

    /**
     * Everything that has to happen after any change, whatever the change was.
     */
    private function afterChange(LivePost $post, ?Update $update): void
    {
        $settings = Plugin::getInstance()->getSettings();

        Craft::$app->getElements()->invalidateCachesForElementType(Update::class);

        if ($settings->invalidateOwnerCaches) {
            $owner = $post->getOwner();

            if ($owner instanceof ElementInterface) {
                Craft::$app->getElements()->invalidateCachesForElement($owner);
            }
        }

        Plugin::getInstance()->purger->postChanged($post);
    }

    /**
     * Publish everything whose time has come. Run from `live/publish-scheduled` on a schedule.
     */
    public function releaseScheduled(?int $siteId = null): int
    {
        $query = Update::find()
            ->status(Update::STATUS_SCHEDULED)
            ->postedAt('< ' . Db::prepareDateForDb(new DateTime()))
            ->siteId($siteId ?? '*');

        $released = 0;

        foreach ($query->all() as $update) {
            /** @var Update $update */
            $post = $this->resolvePost($update);
            Plugin::getInstance()->snapshots->publish($post, $update);
            $this->afterChange($post, $update);
            $released++;
        }

        return $released;
    }
}
