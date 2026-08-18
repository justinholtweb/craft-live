<?php

namespace justinholtweb\live\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\live\Plugin;
use yii\console\ExitCode;

/**
 * Updates written ahead of time (Pro).
 */
class ScheduledController extends Controller
{
    public ?int $siteId = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['siteId']);
    }

    /**
     * Put any update whose time has come onto the site.
     *
     * A scheduled update is already in the database and already has its sequence number — this only
     * writes its snapshot, so running it every minute costs nothing when there is nothing due.
     */
    public function actionRelease(): int
    {
        if (!Plugin::getInstance()->isPro()) {
            $this->stderr("Scheduled updates are a Live Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $count = Plugin::getInstance()->publisher->releaseScheduled($this->siteId);

        $this->stdout("Released $count update" . ($count === 1 ? '' : 's') . ".\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
