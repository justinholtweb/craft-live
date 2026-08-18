<?php

namespace justinholtweb\live\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\live\elements\Update;
use Generator;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\models\Settings;
use justinholtweb\live\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * What readers talk to.
 *
 * With snapshots on, almost nobody gets here: `head.json` is a static file and the deltas are static
 * files, so the whole read path is nginx. These actions are the fallback for hosts that can't write
 * to the web root, the safety net when a snapshot write has failed, and — for Pro — the SSE stream.
 */
class FeedController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireSiteRequest();

        return true;
    }

    /**
     * GET live/feed/head — the same payload `head.json` holds, generated live.
     */
    public function actionHead(): Response
    {
        $post = $this->resolvePost();
        $payload = Plugin::getInstance()->feeds->headPayload(
            $post,
            Plugin::getInstance()->snapshots->readHead($post)['removed'] ?? [],
        );

        return $this->cacheable($this->asJson($payload), $post);
    }

    /**
     * GET live/feed/since — every update after a sequence number, rendered.
     *
     * Capped, because “since 0” on a post with two thousand updates is a denial-of-service request
     * wearing a reader's clothes. A client that far behind should reload the page.
     */
    public function actionSince(): Response
    {
        $post = $this->resolvePost();

        $request = Craft::$app->getRequest();
        $since = (int)$request->getParam('since', 0);
        $limit = min(50, max(1, (int)$request->getParam('limit', 25)));

        $query = Update::find()
            ->postId($post->postId)
            ->fieldId($post->fieldId)
            ->siteId($post->siteId)
            ->status(Update::STATUS_PUBLISHED)
            ->chronological()
            ->limit($limit);

        if ($since > 0) {
            $query->since($since);
        }

        $feeds = Plugin::getInstance()->feeds;

        $updates = array_map(fn(Update $u) => $feeds->updatePayload($u), $query->all());

        return $this->cacheable($this->asJson([
            'seq' => (int)$post->seq,
            'state' => $post->state,
            'updates' => $updates,
            'more' => count($updates) === $limit,
        ]), $post);
    }

    /**
     * GET live/feed/stream — server-sent events (Pro).
     *
     * One PHP process per connected reader, for as long as they are connected. That is a real cost
     * and it is why this is off by default: on FPM the ceiling is `pm.max_children`, and a live blog
     * that goes well is exactly when you find out where that ceiling is. Polling static JSON has no
     * such ceiling, which is why it is the default.
     */
    public function actionStream(): Response
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->sseEnabled || !Plugin::getInstance()->isPro()) {
            throw new NotFoundHttpException('Streaming isn’t enabled.');
        }

        $post = $this->resolvePost();
        $cache = Craft::$app->getCache();
        $countKey = 'live:sse:clients';
        $clients = (int)$cache->get($countKey);

        if ($clients >= $settings->sseMaxClients) {
            // Told plainly, so the client falls back to polling rather than hammering the door.
            Craft::$app->getResponse()->setStatusCode(503);

            return $this->asJson(['error' => 'busy', 'retry' => $settings->pollInterval]);
        }

        $cache->set($countKey, $clients + 1, $settings->sseMaxDuration + 5);

        $response = Craft::$app->getResponse();
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
        $headers->set('Cache-Control', 'no-cache, no-transform');
        $headers->set('Connection', 'keep-alive');
        // nginx buffers proxied responses by default, which turns a live stream into a thirty-second
        // silence followed by everything at once.
        $headers->set('X-Accel-Buffering', 'no');

        $since = (int)Craft::$app->getRequest()->getParam('since', $post->seq);

        // Yii echoes and flushes each chunk a stream callback yields, which is the whole of what an
        // event stream needs. Reaching for the response's own header sending instead just breaks:
        // `sendHeaders()` is protected.
        $response->stream = fn() => $this->streamEvents($post, $since, $settings, $countKey);

        return $response;
    }

    /**
     * The event loop behind {@see actionStream()}.
     *
     * @return \Generator<string>
     */
    private function streamEvents(LivePost $post, int $since, Settings $settings, string $countKey): Generator
    {
        $feeds = Plugin::getInstance()->feeds;
        $posts = Plugin::getInstance()->posts;
        $cache = Craft::$app->getCache();

        $deadline = time() + $settings->sseMaxDuration;
        $interval = max(100, $settings->ssePollInterval) * 1000;

        // Yii flushes after each chunk, but `flush()` only pushes PHP's SAPI buffer — any userland
        // output buffer in between holds every event until the connection closes, which looks
        // exactly like a stream that doesn't work. Same for zlib compression.
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        try {
            yield 'retry: ' . ($settings->pollInterval * 1000) . "\n\n";

            while (time() < $deadline) {
                if (connection_aborted()) {
                    break;
                }

                $posts->flushCacheFor($post);
                $current = $posts->getPost($post->postId, $post->fieldId, $post->siteId);

                if ($current && $current->seq > $since) {
                    $updates = Update::find()
                        ->postId($post->postId)
                        ->fieldId($post->fieldId)
                        ->siteId($post->siteId)
                        ->status(Update::STATUS_PUBLISHED)
                        ->since($since)
                        ->chronological()
                        ->limit(25)
                        ->all();

                    foreach ($updates as $update) {
                        /** @var Update $update */
                        yield "event: update\n" . 'data: ' . json_encode($feeds->updatePayload($update), JSON_UNESCAPED_SLASHES) . "\n\n";
                        $since = max($since, (int)$update->seq);
                    }
                } else {
                    // A comment line keeps proxies from closing an idle connection.
                    yield ": ping\n\n";
                }

                usleep($interval);
            }

            yield "event: reconnect\ndata: {}\n\n";
        } finally {
            $cache->set($countKey, max(0, (int)$cache->get($countKey) - 1), $settings->sseMaxDuration + 5);
        }
    }

    // Internals
    // -------------------------------------------------------------------------

    private function resolvePost(): LivePost
    {
        $request = Craft::$app->getRequest();

        $postId = (int)$request->getRequiredParam('post');
        $fieldId = (int)$request->getRequiredParam('field');
        $siteId = (int)($request->getParam('site') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $post = Plugin::getInstance()->posts->getPost($postId, $fieldId, $siteId);

        if (!$post) {
            throw new NotFoundHttpException('No live feed there.');
        }

        // The feed is only as public as the entry it belongs to. A disabled or trashed owner takes
        // its updates off the site with it.
        $owner = $post->getOwner();

        if (!$owner || !$owner->enabled || $owner->trashed) {
            throw new NotFoundHttpException('No live feed there.');
        }

        return $post;
    }

    /**
     * Let the CDN hold this for one poll interval. Two thousand readers polling in the same second
     * then cost the origin one request, not two thousand.
     */
    private function cacheable(Response $response, LivePost $post): Response
    {
        $interval = Plugin::getInstance()->getSettings()->pollInterval;

        $response->getHeaders()
            ->set('Cache-Control', "public, max-age=$interval, stale-while-revalidate=$interval")
            ->set('X-Live-Seq', (string)$post->seq);

        return $response;
    }
}
