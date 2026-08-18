<?php

declare(strict_types=1);

namespace justinholtweb\live\tests\unit;

use justinholtweb\live\models\LivePost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The post life cycle.
 *
 * `isFollowable` is the one that matters: it is what tells a reader's browser whether to keep
 * polling. Get it wrong in one direction and a finished match keeps a hundred thousand tabs asking
 * for a file that will never change; get it wrong in the other and coverage silently stops updating
 * before it has begun.
 */
final class LivePostTest extends TestCase
{
    #[DataProvider('stateProvider')]
    public function testStatePredicates(string $state, bool $isLive, bool $followable, bool $ended): void
    {
        $post = new LivePost(['state' => $state]);

        self::assertSame($isLive, $post->getIsLive());
        self::assertSame($followable, $post->getIsFollowable());
        self::assertSame($ended, $post->getIsEnded());
    }

    public static function stateProvider(): array
    {
        return [
            //              state,       live,  followable, ended
            'upcoming' => [LivePost::STATE_UPCOMING, false, true, false],
            'live' => [LivePost::STATE_LIVE, true, true, false],
            'paused' => [LivePost::STATE_PAUSED, false, true, false],
            'ended' => [LivePost::STATE_ENDED, false, false, true],
        ];
    }

    public function testEveryStateIsListed(): void
    {
        // The console command, the composer's buttons and the validation rule all read this list.
        self::assertSame(
            ['upcoming', 'live', 'paused', 'ended'],
            LivePost::STATES,
        );
    }

    public function testDefaultsToUpcoming(): void
    {
        $post = new LivePost();

        self::assertSame(LivePost::STATE_UPCOMING, $post->state);
        self::assertSame(0, $post->seq);
        self::assertSame(0, $post->updateCount);
        self::assertNull($post->pinnedUpdateId);
    }

    public function testRejectsUnknownState(): void
    {
        $post = new LivePost([
            'postId' => 1,
            'fieldId' => 2,
            'siteId' => 1,
            'state' => 'halftime',
        ]);

        self::assertFalse($post->validate());
        self::assertArrayHasKey('state', $post->getErrors());
    }

    public function testRequiresItsIdentity(): void
    {
        $post = new LivePost(['state' => LivePost::STATE_LIVE]);

        self::assertFalse($post->validate());

        foreach (['postId', 'fieldId', 'siteId'] as $attribute) {
            self::assertArrayHasKey($attribute, $post->getErrors());
        }
    }

    public function testStateLabelsAreTranslated(): void
    {
        self::assertSame('Live', (new LivePost(['state' => LivePost::STATE_LIVE]))->getStateLabel());
        self::assertSame('Ended', (new LivePost(['state' => LivePost::STATE_ENDED]))->getStateLabel());
    }
}
