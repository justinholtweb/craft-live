<?php

namespace justinholtweb\live\jobs;

use Craft;
use craft\queue\BaseJob;
use GuzzleHttp\Exception\GuzzleException;
use justinholtweb\live\models\Settings;
use justinholtweb\live\Plugin;

/**
 * Ask the CDN to forget a handful of URLs.
 */
class PurgeJob extends BaseJob
{
    /** @var string[] */
    public array $urls = [];

    public ?string $cacheKey = null;

    public function execute($queue): void
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            match ($settings->purgeDriver) {
                Settings::PURGE_CLOUDFLARE => $this->cloudflare($settings),
                Settings::PURGE_FASTLY => $this->fastly($settings),
                Settings::PURGE_WEBHOOK => $this->webhook($settings),
                default => null,
            };
        } catch (GuzzleException $e) {
            Craft::warning('Live could not purge the CDN: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        } finally {
            if ($this->cacheKey) {
                Craft::$app->getCache()->delete($this->cacheKey);
            }
        }
    }

    protected function defaultDescription(): string
    {
        return Craft::t('live', 'Purging live post URLs');
    }

    private function cloudflare(Settings $settings): void
    {
        $zone = $settings->purgeZoneId;
        $token = $settings->getResolvedPurgeToken();

        if (!$zone || !$token) {
            return;
        }

        Craft::createGuzzleClient()->post("https://api.cloudflare.com/client/v4/zones/$zone/purge_cache", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => ['files' => $this->urls],
            'timeout' => 10,
        ]);
    }

    private function fastly(Settings $settings): void
    {
        $token = $settings->getResolvedPurgeToken();

        if (!$token) {
            return;
        }

        $client = Craft::createGuzzleClient();

        foreach ($this->urls as $url) {
            $client->request('PURGE', $url, [
                'headers' => ['Fastly-Key' => $token],
                'timeout' => 10,
            ]);
        }
    }

    private function webhook(Settings $settings): void
    {
        if (!$settings->purgeUrl) {
            return;
        }

        Craft::createGuzzleClient()->post($settings->purgeUrl, [
            'json' => ['urls' => $this->urls],
            'timeout' => 10,
        ]);
    }
}
