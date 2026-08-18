<?php

namespace justinholtweb\live\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\live\elements\Update;
use justinholtweb\live\models\UpdateType;
use justinholtweb\live\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Update types — the buttons on the composer's toolbar.
 */
class UpdateTypesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_TYPES);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('live/types/index', [
            'types' => Plugin::getInstance()->updateTypes->getAllTypes(),
        ]);
    }

    public function actionEdit(?int $typeId = null, ?UpdateType $type = null): Response
    {
        if (!$type) {
            if ($typeId) {
                $type = Plugin::getInstance()->updateTypes->getTypeById($typeId);

                if (!$type) {
                    throw new NotFoundHttpException('Update type not found.');
                }
            } else {
                $type = new UpdateType();
            }
        }

        return $this->renderTemplate('live/types/edit', [
            'type' => $type,
            'isNew' => !$type->id,
            'title' => $type->id
                ? $type->name
                : Craft::t('live', 'Create a new update type'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $typeId = (int)$request->getBodyParam('typeId');

        $type = $typeId
            ? Plugin::getInstance()->updateTypes->getTypeById($typeId)
            : new UpdateType();

        if (!$type) {
            throw new NotFoundHttpException('Update type not found.');
        }

        $type->name = $request->getBodyParam('name');
        $type->handle = $request->getBodyParam('handle');
        $type->icon = $request->getBodyParam('icon') ?: null;
        $type->color = $request->getBodyParam('color') ?: null;
        $type->description = $request->getBodyParam('description') ?: null;
        $type->hasTitleField = (bool)$request->getBodyParam('hasTitleField');
        $type->titleFormat = $request->getBodyParam('titleFormat') ?: null;
        $type->showsInComposer = (bool)$request->getBodyParam('showsInComposer', true);

        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        $layout->type = Update::class;
        $layout->id = $type->getFieldLayout()->id;
        $layout->uid = $type->getFieldLayout()->uid;
        $type->setFieldLayout($layout);

        if (!Plugin::getInstance()->updateTypes->saveType($type)) {
            Craft::$app->getSession()->setError(Craft::t('live', 'Couldn’t save that update type.'));

            Craft::$app->getUrlManager()->setRouteParams(['type' => $type]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('live', 'Update type saved.'));

        return $this->redirectToPostedUrl($type);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $typeId = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');

        try {
            $success = Plugin::getInstance()->updateTypes->deleteTypeById($typeId);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $success
            ? $this->asSuccess(Craft::t('live', 'Update type deleted.'))
            : $this->asFailure(Craft::t('live', 'Couldn’t delete that update type.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uids = Json::decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'));

        // The sortable widget posts IDs; project config is keyed on UIDs.
        $byId = [];

        foreach (Plugin::getInstance()->updateTypes->getAllTypes() as $type) {
            $byId[(int)$type->id] = $type->uid;
        }

        $ordered = [];

        foreach ($uids as $id) {
            if (isset($byId[(int)$id])) {
                $ordered[] = $byId[(int)$id];
            }
        }

        Plugin::getInstance()->updateTypes->reorderTypes($ordered);

        return $this->asSuccess();
    }
}
