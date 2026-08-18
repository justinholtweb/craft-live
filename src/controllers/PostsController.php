<?php

namespace justinholtweb\live\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\Plugin;
use justinholtweb\live\web\assets\composer\ComposerAsset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control panel's own screens: what's live, and the full-screen composer.
 */
class PostsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /**
     * Everything that has ever been live on this site, running posts first.
     */
    public function actionIndex(?string $state = null): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $posts = Plugin::getInstance()->posts->getAllPosts($site->id, $state);

        // Sort running posts to the top: an editor arriving at this screen mid-match is looking for
        // the one that is on right now, and it is not necessarily the one published most recently.
        usort($posts, function(LivePost $a, LivePost $b) {
            $rank = fn(LivePost $p) => match ($p->state) {
                LivePost::STATE_LIVE => 0,
                LivePost::STATE_PAUSED => 1,
                LivePost::STATE_UPCOMING => 2,
                default => 3,
            };

            return [$rank($a), -($a->lastPublishedAt?->getTimestamp() ?? 0)]
                <=> [$rank($b), -($b->lastPublishedAt?->getTimestamp() ?? 0)];
        });

        return $this->renderTemplate('live/posts/index', [
            'posts' => $posts,
            'state' => $state,
            'states' => LivePost::STATES,
            'site' => $site,
        ]);
    }

    /**
     * The full-screen composer — the same thing the field shows, with nothing else on the page.
     *
     * This is the screen a journalist actually works in for ninety minutes, which is why it exists
     * separately: the entry edit screen autosaves, warns about unsaved changes and re-renders its
     * form, and none of that is welcome when the job is to type and press ⌘↵.
     */
    public function actionStudio(int $postId): Response
    {
        $request = Craft::$app->getRequest();
        $siteHandle = $request->getParam('site');
        $site = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)
            : Craft::$app->getSites()->getCurrentSite();

        if (!$site) {
            throw new NotFoundHttpException('Site not found.');
        }

        $owner = Craft::$app->getElements()->getElementById($postId, null, $site->id);

        if (!$owner) {
            throw new NotFoundHttpException('Entry not found.');
        }

        $fields = Plugin::getInstance()->fields->getFieldsForElement($owner);

        if (!$fields) {
            throw new NotFoundHttpException('That entry has no live feed.');
        }

        $fieldId = (int)$request->getParam('fieldId');
        $field = $fieldId ? Plugin::getInstance()->fields->getFieldById($fieldId) : $fields[0];

        if (!$field) {
            throw new NotFoundHttpException('Live field not found.');
        }

        $post = Plugin::getInstance()->posts->ensurePost((int)$owner->id, (int)$field->id, (int)$site->id);

        $this->getView()->registerAssetBundle(ComposerAsset::class);

        return $this->renderTemplate('live/posts/studio', [
            'owner' => $owner,
            'field' => $field,
            'fields' => $fields,
            'post' => $post,
            'site' => $site,
            'types' => $field->getAllowedTypes(),
            'config' => $field->composerConfig($owner, $post),
            'updates' => $field->recentUpdates($owner),
        ]);
    }
}
