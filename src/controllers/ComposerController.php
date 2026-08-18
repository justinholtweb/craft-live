<?php

namespace justinholtweb\live\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\live\elements\Update;
use justinholtweb\live\fields\LiveField;
use justinholtweb\live\models\LivePost;
use justinholtweb\live\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Everything the composer talks to.
 *
 * Each action does one small thing and returns JSON. Nothing here ever saves the owner entry — that
 * is the whole point. An editor covering a match never has to wait for an entry save, and two
 * editors on the same match never overwrite each other's work, because they are not writing to the
 * same row.
 */
class ComposerController extends Controller
{
    /** Namespace the composer renders custom fields under. */
    public const FIELD_NAMESPACE = 'liveFields';

    /**
     * …and the namespace it reads them back from.
     *
     * `CustomField::formHtml()` wraps every custom field in its own `fields` prefix, so an input
     * rendered under `liveFields` posts as `liveFields[fields][handle]`. Reading `liveFields` alone
     * finds nothing and drops every custom field value without a word of complaint.
     */
    public const FIELD_PARAM_NAMESPACE = self::FIELD_NAMESPACE . '.fields';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    /**
     * POST live/composer/publish
     */
    public function actionPublish(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_PUBLISH);

        [$owner, $field, $post] = $this->resolveContext();

        $request = Craft::$app->getRequest();
        $clientId = $request->getBodyParam('clientId');

        // A retried publish from the composer's offline queue must not post twice.
        if ($clientId) {
            $existing = Plugin::getInstance()->publisher->findByClientId(
                $post->postId,
                $post->fieldId,
                $post->siteId,
                $clientId,
            );

            if ($existing) {
                return $this->asJson([
                    'success' => true,
                    'duplicate' => true,
                    'update' => $this->updateJson($existing, $field),
                    'seq' => Plugin::getInstance()->posts->getPost($post->postId, $post->fieldId, $post->siteId)?->seq,
                ]);
            }
        }

        $update = new Update([
            'postId' => $post->postId,
            'fieldId' => $post->fieldId,
            'siteId' => $post->siteId,
            'clientId' => $clientId,
        ]);

        $this->populate($update, $field);

        if (!Plugin::getInstance()->publisher->publish($update)) {
            return $this->asJson([
                'success' => false,
                'errors' => $update->getErrors(),
                'message' => $this->firstError($update) ?? Craft::t('live', 'Couldn’t publish that update.'),
            ])->setStatusCode(422);
        }

        $post = Plugin::getInstance()->posts->getPost($post->postId, $post->fieldId, $post->siteId) ?? $post;

        return $this->asJson([
            'success' => true,
            'update' => $this->updateJson($update, $field),
            'seq' => (int)$post->seq,
            'count' => (int)$post->updateCount,
        ]);
    }

    /**
     * POST live/composer/save — edit an update that is already out there.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_PUBLISH);

        [$owner, $field, $post] = $this->resolveContext();

        $update = $this->findUpdate($post);

        if ($update->authorId && $update->authorId !== Craft::$app->getUser()->getId()) {
            $this->requirePermission(Plugin::PERMISSION_EDIT_OTHERS);
        }

        $this->populate($update, $field);

        if (!Plugin::getInstance()->publisher->edit($update)) {
            return $this->asJson([
                'success' => false,
                'errors' => $update->getErrors(),
                'message' => $this->firstError($update) ?? Craft::t('live', 'Couldn’t save that update.'),
            ])->setStatusCode(422);
        }

        return $this->asJson([
            'success' => true,
            'update' => $this->updateJson($update, $field),
        ]);
    }

    /**
     * POST live/composer/delete
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        [, , $post] = $this->resolveContext();

        $update = $this->findUpdate($post);

        if (!Plugin::getInstance()->publisher->delete($update)) {
            return $this->asJson(['success' => false, 'message' => Craft::t('live', 'Couldn’t delete that update.')])
                ->setStatusCode(422);
        }

        return $this->asJson(['success' => true, 'id' => (int)$update->id]);
    }

    /**
     * POST live/composer/pin
     */
    public function actionPin(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_PUBLISH);

        [, , $post] = $this->resolveContext();

        $update = $this->findUpdate($post);
        $pinned = (bool)Craft::$app->getRequest()->getBodyParam('pinned', true);

        Plugin::getInstance()->publisher->pin($update, $pinned);

        return $this->asJson(['success' => true, 'pinnedId' => $pinned ? (int)$update->id : null]);
    }

    /**
     * POST live/composer/state — start, pause, resume or end a post.
     */
    public function actionState(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_CONTROL);

        [, , $post] = $this->resolveContext();

        $state = (string)Craft::$app->getRequest()->getRequiredBodyParam('state');

        if (!in_array($state, LivePost::STATES, true)) {
            throw new BadRequestHttpException("Unknown live post state: $state");
        }

        $post = Plugin::getInstance()->publisher->setState($post, $state);

        return $this->asJson([
            'success' => true,
            'state' => $post->state,
            'label' => $post->getStateLabel(),
        ]);
    }

    /**
     * GET live/composer/feed — what the composer polls, so a second editor's updates appear.
     */
    public function actionFeed(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        [, $field, $post] = $this->resolveContext();

        $request = Craft::$app->getRequest();
        $since = (int)$request->getParam('since', 0);

        $query = Update::find()
            ->postId($post->postId)
            ->fieldId($post->fieldId)
            ->siteId($post->siteId)
            ->status(null)
            ->limit($field->pageSize);

        if ($since > 0) {
            $query->since($since);
        }

        if ($before = (int)$request->getParam('before', 0)) {
            $query->before($before);
        }

        $updates = array_map(fn(Update $u) => $this->updateJson($u, $field), $query->all());

        return $this->asJson([
            'success' => true,
            'seq' => (int)$post->seq,
            'count' => (int)$post->updateCount,
            'state' => $post->state,
            'pinnedId' => $post->pinnedUpdateId,
            'updates' => $updates,
            // Everything currently on the post, so the composer can drop cards for updates someone
            // else deleted without needing a tombstone list.
            'ids' => array_map('intval', Update::find()
                ->postId($post->postId)
                ->fieldId($post->fieldId)
                ->siteId($post->siteId)
                ->status(null)
                ->limit($field->pageSize)
                ->ids()),
        ]);
    }

    /**
     * GET live/composer/fields — the custom-field HTML for a type, or for an existing update.
     */
    public function actionFields(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_PUBLISH);

        [, $field, $post] = $this->resolveContext();

        $request = Craft::$app->getRequest();
        $updateId = (int)$request->getParam('updateId');

        if ($updateId) {
            $update = $this->findUpdate($post, $updateId);
        } else {
            $type = Plugin::getInstance()->updateTypes->getTypeById((int)$request->getRequiredParam('typeId'));

            if (!$type) {
                throw new NotFoundHttpException('Update type not found.');
            }

            $update = new Update([
                'postId' => $post->postId,
                'fieldId' => $post->fieldId,
                'siteId' => $post->siteId,
                'typeId' => $type->id,
            ]);
        }

        $layout = $update->getFieldLayout();
        $view = Craft::$app->getView();

        $html = '';

        if ($layout && $layout->getCustomFields()) {
            $html = $view->namespaceInputs(
                fn() => $layout->createForm($update, false, ['registerDeltas' => false])->render(),
                self::FIELD_NAMESPACE,
            );
        }

        return $this->asJson([
            'success' => true,
            'html' => $html,
            'title' => $update->title,
            'body' => $update->getSource(),
            'typeId' => (int)$update->typeId,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    /**
     * POST live/composer/heartbeat — presence (Pro).
     */
    public function actionHeartbeat(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        [, , $post] = $this->resolveContext();

        $user = Craft::$app->getUser()->getIdentity();
        $settings = Plugin::getInstance()->getSettings();

        if (!$user || !$settings->presenceEnabled || !Plugin::getInstance()->isPro()) {
            return $this->asJson(['success' => true, 'others' => []]);
        }

        return $this->asJson([
            'success' => true,
            'others' => Plugin::getInstance()->presence->heartbeat($post->postId, $post->fieldId, $post->siteId, $user),
            'seq' => (int)$post->seq,
        ]);
    }

    /**
     * POST live/composer/leave
     */
    public function actionLeave(): Response
    {
        $this->requirePostRequest();

        [, , $post] = $this->resolveContext();

        $userId = Craft::$app->getUser()->getId();

        if ($userId) {
            Plugin::getInstance()->presence->leave($post->postId, $post->fieldId, $post->siteId, (int)$userId);
        }

        return $this->asJson(['success' => true]);
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array{0:ElementInterface,1:LiveField,2:LivePost}
     */
    private function resolveContext(): array
    {
        $request = Craft::$app->getRequest();

        $postId = (int)$request->getRequiredParam('postId');
        $fieldId = (int)$request->getRequiredParam('fieldId');
        $siteId = (int)($request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $field = Plugin::getInstance()->fields->getFieldById($fieldId);

        if (!$field) {
            throw new NotFoundHttpException('Live field not found.');
        }

        $owner = Craft::$app->getElements()->getElementById($postId, null, $siteId);

        if (!$owner) {
            throw new NotFoundHttpException('Live post not found.');
        }

        // The permission to post live updates is not a permission to post them onto any entry in
        // the install: whoever it is has to be able to edit this one.
        if (!Craft::$app->getElements()->canView($owner)) {
            throw new ForbiddenHttpException('You don’t have permission to edit this entry.');
        }

        // And the field has to actually be on it, or the ID pair is made up.
        $onElement = false;

        foreach (Plugin::getInstance()->fields->getFieldsForElement($owner) as $candidate) {
            if ((int)$candidate->id === $fieldId) {
                $onElement = true;
                break;
            }
        }

        if (!$onElement) {
            throw new BadRequestHttpException('That field isn’t on that entry.');
        }

        $post = Plugin::getInstance()->posts->ensurePost($postId, $fieldId, $siteId);

        return [$owner, $field, $post];
    }

    private function findUpdate(LivePost $post, ?int $id = null): Update
    {
        $id ??= (int)Craft::$app->getRequest()->getRequiredBodyParam('updateId');

        /** @var Update|null $update */
        $update = Update::find()
            ->id($id)
            ->postId($post->postId)
            ->fieldId($post->fieldId)
            ->siteId($post->siteId)
            ->status(null)
            ->one();

        if (!$update) {
            throw new NotFoundHttpException("Update $id not found on this post.");
        }

        return $update;
    }

    /**
     * Read an update off the request. Only the attributes the composer is allowed to set.
     */
    private function populate(Update $update, LiveField $field): void
    {
        $request = Craft::$app->getRequest();

        if ($typeId = (int)$request->getBodyParam('typeId')) {
            $allowed = array_map(fn($type) => (int)$type->id, $field->getAllowedTypes());

            if (!in_array($typeId, $allowed, true)) {
                throw new BadRequestHttpException('That update type isn’t available on this field.');
            }

            $update->typeId = $typeId;
        }

        $update->typeId ??= (int)($field->getAllowedTypes()[0]->id ?? 0);

        // The composer posts what the editor typed; an API client can post HTML directly.
        if ($request->getBodyParam('body') !== null) {
            $update->setSource((string)$request->getBodyParam('body'));
        } elseif ($request->getBodyParam('html') !== null) {
            $update->body = (string)$request->getBodyParam('html');
        }

        if ($request->getBodyParam('title') !== null) {
            $update->title = $request->getBodyParam('title') ?: null;
        }

        $update->highlight = (bool)$request->getBodyParam('highlight', $update->highlight);
        $update->pinned = (bool)$request->getBodyParam('pinned', $update->pinned);

        $postedAt = $request->getBodyParam('postedAt');

        if ($postedAt) {
            $date = DateTimeHelper::toDateTime($postedAt);

            if ($date) {
                $scheduled = $date->getTimestamp() > time();

                // Scheduling is a Pro feature and a per-field switch; without both, a future time is
                // just a wrong time, and silently publishing it now is the wrong answer too.
                if ($scheduled && !($field->allowScheduled && Plugin::getInstance()->isPro())) {
                    throw new BadRequestHttpException('Scheduled updates aren’t enabled on this field.');
                }

                $update->postedAt = $date;
            }
        }

        $update->setFieldValuesFromRequest(self::FIELD_PARAM_NAMESPACE);
    }

    /**
     * One update, as the composer wants it: data plus its rendered card.
     */
    private function updateJson(Update $update, LiveField $field): array
    {
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $card = $view->renderTemplate('live/_update-card', [
                'update' => $update,
                'field' => $field,
            ]);
        } finally {
            $view->setTemplateMode($oldMode);
        }

        return [
            'id' => (int)$update->id,
            'seq' => (int)$update->seq,
            'rev' => (int)$update->metaValue('rev', 0),
            'typeId' => (int)$update->typeId,
            'type' => $update->getType()->handle,
            'title' => $update->title,
            'body' => $update->body,
            'source' => $update->getSource(),
            'status' => $update->getStatus(),
            'pinned' => (bool)$update->pinned,
            'highlight' => (bool)$update->highlight,
            'postedAt' => $update->postedAt?->format(DATE_ATOM),
            'authorId' => (int)$update->authorId,
            'card' => $card,
        ];
    }

    private function firstError(Update $update): ?string
    {
        foreach ($update->getErrors() as $errors) {
            if ($errors) {
                return reset($errors);
            }
        }

        return null;
    }
}
