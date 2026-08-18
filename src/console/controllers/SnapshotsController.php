<?php

namespace justinholtweb\live\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\live\Plugin;
use yii\console\ExitCode;

/**
 * Rebuild the static files readers poll.
 */
class SnapshotsController extends Controller
{
    /** Rebuild every post on this site, not just one. */
    public bool $all = false;

    /** Limit to one site. */
    public ?int $siteId = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all', 'siteId']);
    }

    /**
     * Rewrite snapshot files from the database.
     *
     * Run it after moving the snapshot path, restoring a web root, or changing the update template —
     * updates already published keep their old markup until something rewrites them.
     *
     * @param int|null $postId The entry ID to rebuild. Omit with --all for everything.
     */
    public function actionRebuild(?int $postId = null): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->snapshots->isEnabled()) {
            $this->stderr("Snapshots are turned off in Live's settings.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $posts = $plugin->posts->getAllPosts($this->siteId);

        if ($postId) {
            $posts = array_values(array_filter($posts, fn($post) => $post->postId === $postId));
        } elseif (!$this->all) {
            $this->stderr("Pass an entry ID, or --all.\n", Console::FG_YELLOW);

            return ExitCode::USAGE;
        }

        if (!$posts) {
            $this->stdout("Nothing to rebuild.\n");

            return ExitCode::OK;
        }

        $total = 0;

        foreach ($posts as $post) {
            $this->stdout("Rebuilding post $post->postId (site $post->siteId) … ");
            $written = $plugin->snapshots->rebuild($post);
            $total += $written;
            $this->stdout("$written updates\n", Console::FG_GREEN);
        }

        $this->stdout("Wrote $total updates across " . count($posts) . " posts.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete snapshot directories whose post no longer exists.
     */
    public function actionCollectGarbage(): int
    {
        Plugin::getInstance()->snapshots->collectGarbage();
        $this->stdout("Done.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
