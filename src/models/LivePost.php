<?php

namespace justinholtweb\live\models;

use craft\base\ElementInterface;
use craft\base\Model;
use Craft;
use DateTime;

/**
 * The head row for one live post: where it is in its life cycle, and how far its sequence has run.
 *
 * Everything the poll endpoint and the composer need to answer “what's the latest?” lives here, so
 * that question costs one indexed row read rather than a query over the updates table.
 */
class LivePost extends Model
{
    /** Published, but not accepting updates yet. */
    public const STATE_UPCOMING = 'upcoming';

    /** Running. Readers should be polling. */
    public const STATE_LIVE = 'live';

    /** Temporarily halted — half time, a delay. Readers keep polling. */
    public const STATE_PAUSED = 'paused';

    /** Over. Readers stop polling and the feed becomes an archive. */
    public const STATE_ENDED = 'ended';

    public const STATES = [self::STATE_UPCOMING, self::STATE_LIVE, self::STATE_PAUSED, self::STATE_ENDED];

    public ?int $id = null;
    public ?int $postId = null;
    public ?int $fieldId = null;
    public ?int $siteId = null;
    public string $state = self::STATE_UPCOMING;
    public int $seq = 0;
    public int $updateCount = 0;
    public ?int $pinnedUpdateId = null;
    public int $snapshotSeq = 0;
    public ?DateTime $startedAt = null;
    public ?DateTime $endedAt = null;
    public ?DateTime $lastPublishedAt = null;
    public ?string $uid = null;

    /** Whether updates are being taken right now. */
    public function getIsLive(): bool
    {
        return $this->state === self::STATE_LIVE;
    }

    /** Whether a reader should still be watching for new updates. */
    public function getIsFollowable(): bool
    {
        return in_array($this->state, [self::STATE_UPCOMING, self::STATE_LIVE, self::STATE_PAUSED], true);
    }

    public function getIsEnded(): bool
    {
        return $this->state === self::STATE_ENDED;
    }

    public function getStateLabel(): string
    {
        return match ($this->state) {
            self::STATE_UPCOMING => Craft::t('live', 'Upcoming'),
            self::STATE_LIVE => Craft::t('live', 'Live'),
            self::STATE_PAUSED => Craft::t('live', 'Paused'),
            self::STATE_ENDED => Craft::t('live', 'Ended'),
            default => $this->state,
        };
    }

    public function getOwner(): ?ElementInterface
    {
        return $this->postId ? Craft::$app->getElements()->getElementById($this->postId, null, $this->siteId) : null;
    }

    protected function defineRules(): array
    {
        return [
            [['postId', 'fieldId', 'siteId'], 'required'],
            [['postId', 'fieldId', 'siteId', 'seq', 'updateCount', 'snapshotSeq', 'pinnedUpdateId'], 'integer'],
            [['state'], 'in', 'range' => self::STATES],
            [['startedAt', 'endedAt', 'lastPublishedAt'], \craft\validators\DateTimeValidator::class],
        ];
    }
}
