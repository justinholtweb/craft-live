<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use justinholtweb\live\jobs\PurgeJob;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\models\Settings;
use justinholtweb\live\Plugin;

/**
 * CDN purging (Pro).
 *
 * Mostly this plugin exists so you *don't* have to purge anything — the page stays cached and the
 * feed arrives around it. Purging still matters for the readers who never run the JavaScript: search
 * crawlers, feed readers, people on a locked-down browser. So it happens, but on a throttle: a busy
 * match is three hundred publishes, and three hundred purges of the same URL is three hundred
 * cache-fills of the same page.
 */
class Purger extends Component
{
    public function postChanged(LivePost $post): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!Plugin::getInstance()->isPro() || $settings->purgeDriver === Settings::PURGE_NONE) {
            return;
        }

        $cache = Craft::$app->getCache();
        $key = "live:purge:$post->postId:$post->siteId";

        // Already scheduled — the job that is coming will pick up whatever has happened since.
        if ($cache->get($key)) {
            return;
        }

        $urls = $this->urlsFor($post);

        if (!$urls) {
            return;
        }

        $cache->set($key, true, $settings->purgeThrottle);

        Craft::$app->getQueue()
            ->delay($settings->purgeThrottle)
            ->push(new PurgeJob([
                'urls' => $urls,
                'cacheKey' => $key,
            ]));
    }

    /**
     * @return string[]
     */
    public function urlsFor(LivePost $post): array
    {
        $urls = [];

        $owner = $post->getOwner();

        if ($owner && $owner->getUrl()) {
            $urls[] = $owner->getUrl();
        }

        if (Plugin::getInstance()->snapshots->isEnabled()) {
            $head = Plugin::getInstance()->snapshots->getHeadUrl($post);

            // A root-relative snapshot URL can't be purged — the CDN needs an absolute one.
            if (str_starts_with($head, 'http')) {
                $urls[] = $head;
            }
        }

        return array_values(array_unique($urls));
    }
}
