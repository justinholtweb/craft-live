<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use justinholtweb\live\Plugin;

/**
 * Who else is in this composer right now (Pro).
 *
 * Two journalists on the same match is the normal case, not the edge case, and the failure it
 * causes is silent: both write the same goal, or one overwrites the other's correction. Presence
 * lives in the cache rather than the database — it is worth nothing after a minute, and a live blog
 * does not need another write per editor per twenty seconds.
 */
class Presence extends Component
{
    /**
     * Record that someone is here, and return everyone else who is.
     *
     * @return array<int,array{userId:int,name:string,lastSeen:int}>
     */
    public function heartbeat(int $postId, int $fieldId, int $siteId, User $user): array
    {
        $now = time();
        $presence = $this->read($postId, $fieldId, $siteId);

        $presence[(int)$user->id] = [
            'name' => $user->friendlyName ?: $user->username,
            'lastSeen' => $now,
        ];

        $presence = $this->write($postId, $fieldId, $siteId, $presence);

        $others = [];

        foreach ($presence as $userId => $info) {
            if ((int)$userId === (int)$user->id) {
                continue;
            }

            $others[] = [
                'userId' => (int)$userId,
                'name' => $info['name'] ?? Craft::t('live', 'Someone'),
                'lastSeen' => (int)($info['lastSeen'] ?? 0),
            ];
        }

        return $others;
    }

    public function leave(int $postId, int $fieldId, int $siteId, int $userId): void
    {
        $presence = $this->read($postId, $fieldId, $siteId);
        unset($presence[$userId]);
        $this->write($postId, $fieldId, $siteId, $presence);
    }

    /**
     * @return array<int,array{name:string,lastSeen:int}>
     */
    public function read(int $postId, int $fieldId, int $siteId): array
    {
        $presence = Craft::$app->getCache()->get($this->key($postId, $fieldId, $siteId));

        return is_array($presence) ? $presence : [];
    }

    /**
     * Reap the departed in the same write that records the living, so nothing has to sweep.
     *
     * @return array<int,array{name:string,lastSeen:int}>
     */
    private function write(int $postId, int $fieldId, int $siteId, array $presence): array
    {
        $ttl = Plugin::getInstance()->getSettings()->presenceTtl;
        $cutoff = time() - $ttl;

        foreach ($presence as $userId => $info) {
            if (!is_array($info) || ($info['lastSeen'] ?? 0) < $cutoff) {
                unset($presence[$userId]);
            }
        }

        Craft::$app->getCache()->set($this->key($postId, $fieldId, $siteId), $presence, $ttl * 5);

        return $presence;
    }

    private function key(int $postId, int $fieldId, int $siteId): string
    {
        return "live:presence:$postId:$fieldId:$siteId";
    }
}
