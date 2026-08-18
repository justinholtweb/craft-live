<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use justinholtweb\live\elements\Update;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\Plugin;
use Throwable;

/**
 * The static half of the delivery story.
 *
 * Every publish writes two files into the web root: an immutable one for the update itself, and a
 * tiny mutable `head.json`. Readers poll head — which nginx or the CDN serves without ever waking
 * PHP — and fetch only the updates they are missing, at URLs that change whenever the content does.
 *
 * This is why the page cache never has to be broken. The HTML page can be cached for a day; the
 * live feed rides over the top of it at whatever interval the site can afford.
 */
class Snapshots extends Component
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function isEnabled(): bool
    {
        return Plugin::getInstance()->getSettings()->snapshotsEnabled;
    }

    // Locations
    // -------------------------------------------------------------------------

    public function getPostDir(LivePost $post): string
    {
        $root = Plugin::getInstance()->getSettings()->getResolvedSnapshotPath();

        return "$root/$post->siteId/$post->postId-$post->fieldId";
    }

    public function getPostUrl(LivePost $post): string
    {
        $root = Plugin::getInstance()->getSettings()->getResolvedSnapshotUrl();

        return "$root/$post->siteId/$post->postId-$post->fieldId";
    }

    public function getHeadUrl(LivePost $post): string
    {
        return $this->getPostUrl($post) . '/head.json';
    }

    // Writing
    // -------------------------------------------------------------------------

    /**
     * Write one update's file and refresh the head. Called on publish, on edit, and on pin.
     */
    public function publish(LivePost $post, Update $update): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $this->writeUpdate($post, $update);
            $this->writeHead($post);
            Plugin::getInstance()->posts->setSnapshotSeq($post, max($post->snapshotSeq, (int)$update->seq));
        } catch (Throwable $e) {
            // A failed snapshot must not fail the publish. The update is in the database; the poll
            // endpoint can still serve it, and `live/snapshots/rebuild` puts the files right.
            Craft::error("Live could not write a snapshot for post $post->postId: {$e->getMessage()}", Plugin::LOG_CATEGORY);
        }
    }

    public function writeUpdate(LivePost $post, Update $update): void
    {
        $payload = Plugin::getInstance()->feeds->updatePayload(
            $update,
            Plugin::getInstance()->getSettings()->prerenderHtml,
        );

        $this->write($this->getPostDir($post) . "/u-$update->seq.json", $payload);
    }

    /**
     * Refresh `head.json`.
     *
     * Removals are carried forward from whatever the previous head said, so a reader that missed
     * the poll in which an update disappeared still learns about it on the next one.
     */
    public function writeHead(LivePost $post, array $newRemovals = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $existing = $this->readHead($post);
        $removals = array_values(array_unique(array_merge($existing['removed'] ?? [], $newRemovals)));
        sort($removals);

        $payload = Plugin::getInstance()->feeds->headPayload($post, $removals);

        $this->write($this->getPostDir($post) . '/head.json', $payload);
    }

    /**
     * Take an update back down: delete its file and put its sequence number on the tombstone list.
     */
    public function remove(LivePost $post, int $seq): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $file = $this->getPostDir($post) . "/u-$seq.json";

            if (is_file($file)) {
                FileHelper::unlink($file);
            }

            $this->writeHead($post, [$seq]);
        } catch (Throwable $e) {
            Craft::error("Live could not remove snapshot $seq for post $post->postId: {$e->getMessage()}", Plugin::LOG_CATEGORY);
        }
    }

    /**
     * Rewrite every file for a post from the database. The repair tool for a lost web root, a moved
     * snapshot path, or a template change that should apply to updates already published.
     */
    public function rebuild(LivePost $post): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $dir = $this->getPostDir($post);
        $written = 0;
        $keep = [];

        $query = Update::find()
            ->postId($post->postId)
            ->fieldId($post->fieldId)
            ->siteId($post->siteId)
            ->status(Update::STATUS_PUBLISHED)
            ->chronological();

        // Overwrite in place rather than emptying the directory first. A rebuild during a running
        // match would otherwise take every file away for a second or two, and every reader would
        // see a hole where the feed was.
        foreach ($query->each(100) as $update) {
            /** @var Update $update */
            $this->writeUpdate($post, $update);
            $keep["u-$update->seq.json"] = true;
            $written++;
        }

        foreach (glob("$dir/u-*.json") ?: [] as $file) {
            if (!isset($keep[basename($file)])) {
                FileHelper::unlink($file);
            }
        }

        $this->writeHead($post);
        Plugin::getInstance()->posts->setSnapshotSeq($post, (int)$post->seq);

        return $written;
    }

    /**
     * Remove a post's snapshot directory entirely — the entry has gone, or live posting is off.
     */
    public function deletePost(LivePost $post): void
    {
        $dir = $this->getPostDir($post);

        if (is_dir($dir)) {
            try {
                FileHelper::removeDirectory($dir);
            } catch (Throwable $e) {
                Craft::warning("Live could not remove $dir: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            }
        }
    }

    /**
     * Delete snapshot directories whose post no longer exists.
     *
     * Runs from Craft's garbage collection. An entry hard-deleted while the plugin was disabled,
     * or a snapshot path that has moved, leaves a directory quietly serving updates to anyone who
     * still has the URL — which is exactly the sort of thing nobody discovers for a year.
     */
    public function collectGarbage(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $root = Plugin::getInstance()->getSettings()->getResolvedSnapshotPath();

        if (!is_dir($root)) {
            return;
        }

        $live = [];

        foreach (Plugin::getInstance()->posts->getAllPosts() as $post) {
            $live["$post->siteId/$post->postId-$post->fieldId"] = true;
        }

        foreach (glob("$root/*/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $key = basename(dirname($dir)) . '/' . basename($dir);

            if (isset($live[$key])) {
                continue;
            }

            try {
                FileHelper::removeDirectory($dir);
                Craft::info("Live removed an orphaned snapshot directory: $dir", Plugin::LOG_CATEGORY);
            } catch (Throwable $e) {
                Craft::warning("Live could not remove $dir: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            }
        }
    }

    public function readHead(LivePost $post): array
    {
        $file = $this->getPostDir($post) . '/head.json';

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write JSON atomically.
     *
     * A reader polling head.json at 5Hz will eventually hit the instant it is being rewritten, and
     * a half-written head is a parse error in every browser tab at once. Rename is atomic within a
     * filesystem, so nobody ever sees one.
     */
    private function write(string $path, array $payload): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            FileHelper::createDirectory($dir);
        }

        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        FileHelper::writeToFile($temp, json_encode($payload, self::JSON_FLAGS));

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException("Could not move a snapshot into place at $path.");
        }
    }
}
