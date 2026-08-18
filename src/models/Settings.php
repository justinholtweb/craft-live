<?php

namespace justinholtweb\live\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * Nothing here is `required` — a fresh install has to be able to save its first setting without
 * having filled in a CDN token first.
 */
class Settings extends Model
{
    public const PURGE_NONE = 'none';
    public const PURGE_CLOUDFLARE = 'cloudflare';
    public const PURGE_FASTLY = 'fastly';
    public const PURGE_WEBHOOK = 'webhook';

    // Snapshots
    // -------------------------------------------------------------------------

    /**
     * Whether each publish writes static JSON to disk. With this off, readers fall back to the
     * `live/feed/poll` action, which boots Craft on every poll.
     */
    public bool $snapshotsEnabled = true;

    /** Where snapshot files are written. Must be inside the web root to be served statically. */
    public string $snapshotPath = '@webroot/live-feed';

    /** The URL those files are reachable at. */
    public string $snapshotUrl = '@web/live-feed';

    /** How many of the most recent updates `head.json` lists inline. */
    public int $headWindow = 20;

    /**
     * Whether snapshots carry server-rendered HTML for each update. On, appended updates look
     * exactly like server-rendered ones because they *are* server-rendered ones.
     */
    public bool $prerenderHtml = true;

    /** Template used to render a single update, for both Twig and the snapshot writer. */
    public string $updateTemplate = '';

    // Reading
    // -------------------------------------------------------------------------

    /** Seconds between head polls on the front end. Also the CDN cache window for `head.json`. */
    public int $pollInterval = 5;

    /** Seconds between feed refreshes in the control panel. */
    public int $composerPollInterval = 8;

    /**
     * HTMLPurifier `HTML.Allowed` string for update bodies. Empty means the plugin's own list:
     * the small vocabulary the composer's toolbar can produce, plus links and images.
     */
    public string $bodyAllowedHtml = '';

    /**
     * Whether publishing invalidates the owner entry's caches too. Off, because the entire point is
     * that the page stays cached while updates arrive around it. Turn it on for a `{% cache %}`d
     * feed with no JavaScript.
     */
    public bool $invalidateOwnerCaches = false;

    /** Default time format for update timestamps in shipped templates. */
    public string $timestampFormat = 'H:i';

    // Server-sent events (Pro)
    // -------------------------------------------------------------------------

    /**
     * SSE holds a PHP process open per connected reader. Off by default, and documented as such:
     * on FPM, `pm.max_children` is the ceiling on concurrent readers.
     */
    public bool $sseEnabled = false;

    /** Seconds a single SSE connection stays open before asking the client to reconnect. */
    public int $sseMaxDuration = 30;

    /** Milliseconds between head checks inside an open SSE connection. */
    public int $ssePollInterval = 500;

    /** Refuse new SSE connections beyond this many concurrent readers. */
    public int $sseMaxClients = 50;

    // Editing (Pro)
    // -------------------------------------------------------------------------

    /** Show who else is in the composer, and warn before two editors collide. */
    public bool $presenceEnabled = true;

    /** Seconds without a heartbeat before an editor is considered gone. */
    public int $presenceTtl = 60;

    /** Allow updates to be queued for a future time. */
    public bool $allowScheduled = true;

    // Purging (Pro)
    // -------------------------------------------------------------------------

    public string $purgeDriver = self::PURGE_NONE;
    public ?string $purgeZoneId = null;
    public ?string $purgeToken = null;
    public ?string $purgeUrl = null;

    /** Seconds between purges of the same post, however many updates land in between. */
    public int $purgeThrottle = 60;

    protected function defineRules(): array
    {
        return [
            [['snapshotsEnabled', 'prerenderHtml', 'sseEnabled', 'presenceEnabled', 'allowScheduled'], 'boolean'],
            [['snapshotPath', 'snapshotUrl', 'updateTemplate', 'timestampFormat', 'bodyAllowedHtml'], 'string'],
            [['invalidateOwnerCaches'], 'boolean'],
            [['pollInterval'], 'integer', 'min' => 1, 'max' => 300],
            [['composerPollInterval'], 'integer', 'min' => 2, 'max' => 300],
            [['headWindow'], 'integer', 'min' => 1, 'max' => 200],
            [['sseMaxDuration'], 'integer', 'min' => 5, 'max' => 300],
            [['ssePollInterval'], 'integer', 'min' => 100, 'max' => 10000],
            [['sseMaxClients'], 'integer', 'min' => 1, 'max' => 10000],
            [['presenceTtl'], 'integer', 'min' => 15, 'max' => 3600],
            [['purgeDriver'], 'in', 'range' => [self::PURGE_NONE, self::PURGE_CLOUDFLARE, self::PURGE_FASTLY, self::PURGE_WEBHOOK]],
            [['purgeZoneId', 'purgeToken', 'purgeUrl'], 'string'],
            [['purgeThrottle'], 'integer', 'min' => 5, 'max' => 3600],
            // skipOnEmpty off, or Yii never runs this: an empty value is exactly what it checks for.
            [['snapshotPath', 'snapshotUrl'], 'validateNotEmptyWhenSnapshotting', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Snapshotting with nowhere to write to is a silent no-op, so catch it here instead.
     */
    public function validateNotEmptyWhenSnapshotting(string $attribute): void
    {
        if ($this->snapshotsEnabled && trim((string)$this->$attribute) === '') {
            $this->addError($attribute, Craft::t('live', 'Required while snapshots are enabled.'));
        }
    }

    /** Absolute filesystem path snapshots are written to. */
    public function getResolvedSnapshotPath(): string
    {
        return rtrim(Craft::getAlias(App::parseEnv($this->snapshotPath) ?: $this->snapshotPath), '/\\');
    }

    /** Absolute or root-relative URL those snapshots are served from. */
    public function getResolvedSnapshotUrl(): string
    {
        return rtrim(Craft::getAlias(App::parseEnv($this->snapshotUrl) ?: $this->snapshotUrl), '/');
    }

    public function getResolvedPurgeToken(): ?string
    {
        return $this->purgeToken ? App::parseEnv($this->purgeToken) : null;
    }
}
