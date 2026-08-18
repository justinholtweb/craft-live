<?php

declare(strict_types=1);

namespace justinholtweb\live\tests\unit;

use justinholtweb\live\models\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The settings model.
 *
 * Two things matter here beyond ordinary validation. The first is that a fresh install can save its
 * settings without having filled in a CDN token — nothing is `required`, because a `required` rule
 * on one field makes *every* setting unsaveable until it is filled in. The second is the snapshot
 * path: it is resolved through an alias and an environment variable, and getting it wrong means
 * writing static files somewhere nobody serves.
 */
final class SettingsTest extends TestCase
{
    public function testDefaultsValidate(): void
    {
        $settings = new Settings();

        self::assertTrue($settings->validate(), json_encode($settings->getErrors()));
    }

    public function testNothingIsRequired(): void
    {
        // A brand-new install has no CDN credentials, and must still be able to save.
        $settings = new Settings();
        $settings->purgeToken = null;
        $settings->purgeZoneId = null;
        $settings->purgeUrl = null;

        self::assertTrue($settings->validate());
    }

    #[DataProvider('outOfRangeProvider')]
    public function testRejectsOutOfRangeValues(string $attribute, mixed $value): void
    {
        $settings = new Settings();
        $settings->$attribute = $value;

        self::assertFalse($settings->validate(), "$attribute accepted $value");
        self::assertArrayHasKey($attribute, $settings->getErrors());
    }

    public static function outOfRangeProvider(): array
    {
        return [
            'poll interval too low' => ['pollInterval', 0],
            'poll interval too high' => ['pollInterval', 301],
            'composer poll too low' => ['composerPollInterval', 1],
            'head window too low' => ['headWindow', 0],
            'head window too high' => ['headWindow', 201],
            'sse duration too low' => ['sseMaxDuration', 4],
            'sse duration too high' => ['sseMaxDuration', 301],
            'sse poll too fast' => ['ssePollInterval', 99],
            'sse clients too few' => ['sseMaxClients', 0],
            'presence ttl too low' => ['presenceTtl', 14],
            'purge throttle too low' => ['purgeThrottle', 4],
        ];
    }

    public function testRejectsUnknownPurgeDriver(): void
    {
        $settings = new Settings();
        $settings->purgeDriver = 'akamai';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('purgeDriver', $settings->getErrors());
    }

    /**
     * The rule that catches “snapshots on, nowhere to write them”.
     *
     * Yii skips inline validators when the attribute is empty, and empty is precisely the case this
     * rule exists to catch — so it only works with `skipOnEmpty` turned off. A regression here is
     * silent: snapshots quietly stop being written.
     */
    public function testSnapshotPathIsRequiredOnlyWhileSnapshotting(): void
    {
        $settings = new Settings();
        $settings->snapshotsEnabled = true;
        $settings->snapshotPath = '';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('snapshotPath', $settings->getErrors());

        $settings = new Settings();
        $settings->snapshotsEnabled = false;
        $settings->snapshotPath = '';
        $settings->snapshotUrl = '';

        self::assertTrue($settings->validate(), 'An empty path is fine when nothing is written.');
    }

    public function testResolvesAliases(): void
    {
        $settings = new Settings();

        self::assertSame('/tmp/live-tests/webroot/live-feed', $settings->getResolvedSnapshotPath());
        self::assertSame('https://live.test/live-feed', $settings->getResolvedSnapshotUrl());
    }

    public function testTrimsTrailingSeparators(): void
    {
        $settings = new Settings();
        $settings->snapshotPath = '@webroot/feeds/';
        $settings->snapshotUrl = '@web/feeds/';

        self::assertSame('/tmp/live-tests/webroot/feeds', $settings->getResolvedSnapshotPath());
        self::assertSame('https://live.test/feeds', $settings->getResolvedSnapshotUrl());
    }

    public function testDefaultsAreTheSafeOnes(): void
    {
        $settings = new Settings();

        // SSE holds a PHP process per reader; it must never be on unless somebody asked for it.
        self::assertFalse($settings->sseEnabled);
        // The entire point of the plugin is that publishing doesn't break the page cache.
        self::assertFalse($settings->invalidateOwnerCaches);
        self::assertSame(Settings::PURGE_NONE, $settings->purgeDriver);
        self::assertTrue($settings->snapshotsEnabled);
        self::assertTrue($settings->prerenderHtml);
    }
}
