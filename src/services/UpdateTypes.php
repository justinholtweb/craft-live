<?php

namespace justinholtweb\live\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use justinholtweb\live\db\Table;
use justinholtweb\live\elements\Update;
use justinholtweb\live\models\UpdateType;
use justinholtweb\live\records\UpdateTypeRecord;
use Throwable;
use yii\base\Exception;

/**
 * Update types, stored in project config so a “Goal” type built on staging arrives in production
 * with its field layout intact.
 */
class UpdateTypes extends Component
{
    public const CONFIG_KEY = 'live.updateTypes';

    /** @var UpdateType[]|null */
    private ?array $_types = null;

    /**
     * @return UpdateType[]
     */
    public function getAllTypes(): array
    {
        if ($this->_types !== null) {
            return $this->_types;
        }

        $this->_types = [];

        $rows = (new Query())
            ->select([
                'id', 'name', 'handle', 'icon', 'color', 'description', 'hasTitleField',
                'titleFormat', 'showsInComposer', 'fieldLayoutId', 'sortOrder', 'uid',
            ])
            ->from([Table::UPDATETYPES])
            ->where(['dateDeleted' => null])
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            $row['hasTitleField'] = (bool)$row['hasTitleField'];
            $row['showsInComposer'] = (bool)$row['showsInComposer'];
            $this->_types[] = new UpdateType($row);
        }

        return $this->_types;
    }

    /**
     * The types the composer offers, in the order they appear on its toolbar.
     *
     * @return UpdateType[]
     */
    public function getComposerTypes(): array
    {
        return array_values(array_filter($this->getAllTypes(), fn(UpdateType $t) => $t->showsInComposer));
    }

    public function getTypeById(int $id): ?UpdateType
    {
        foreach ($this->getAllTypes() as $type) {
            if ($type->id === $id) {
                return $type;
            }
        }

        return null;
    }

    public function getTypeByHandle(string $handle): ?UpdateType
    {
        foreach ($this->getAllTypes() as $type) {
            if ($type->handle === $handle) {
                return $type;
            }
        }

        return null;
    }

    public function getTypeByUid(string $uid): ?UpdateType
    {
        foreach ($this->getAllTypes() as $type) {
            if ($type->uid === $uid) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return UpdateType[]
     */
    public function getTypesByIds(array $ids): array
    {
        $ids = array_map('intval', $ids);

        return array_values(array_filter($this->getAllTypes(), fn(UpdateType $t) => in_array($t->id, $ids, true)));
    }

    // Writing
    // -------------------------------------------------------------------------

    public function saveType(UpdateType $type, bool $runValidation = true): bool
    {
        $isNew = !$type->id;

        if ($runValidation && !$type->validate()) {
            return false;
        }

        if ($isNew) {
            $type->uid = StringHelper::UUID();
        } elseif (!$type->uid) {
            $type->uid = Db::uidById(Table::UPDATETYPES, $type->id);
        }

        if ($type->sortOrder === null) {
            $type->sortOrder = count($this->getAllTypes()) + 1;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $type->uid,
            $type->getConfig(),
            "Save the “{$type->handle}” live update type",
        );

        if ($isNew) {
            $type->id = Db::idByUid(Table::UPDATETYPES, $type->uid);
        }

        $this->_types = null;

        return true;
    }

    public function deleteTypeById(int $id): bool
    {
        $type = $this->getTypeById($id);

        return $type && $this->deleteType($type);
    }

    public function deleteType(UpdateType $type): bool
    {
        // Deleting the last type would leave the composer with no button to press.
        if (count($this->getAllTypes()) < 2) {
            throw new Exception('A site needs at least one live update type.');
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $type->uid,
            "Delete the “{$type->handle}” live update type",
        );

        // Project config coalesces an add-and-remove within one request into nothing at all, so
        // teardown that must happen locally cannot rely on the handler alone.
        $this->_types = null;

        return true;
    }

    public function reorderTypes(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $order => $uid) {
            $projectConfig->set(self::CONFIG_KEY . ".$uid.sortOrder", $order + 1, 'Reorder live update types');
        }

        $this->_types = null;

        return true;
    }

    // Project config handlers
    // -------------------------------------------------------------------------

    public function handleChangedType(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        // Any fields the layout points at must exist before the layout is saved.
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = $this->getRecord($uid);

            $record->name = $data['name'];
            $record->handle = $data['handle'];
            $record->icon = $data['icon'] ?? null;
            $record->color = $data['color'] ?? null;
            $record->description = $data['description'] ?? null;
            $record->hasTitleField = (bool)($data['hasTitleField'] ?? false);
            $record->titleFormat = $data['titleFormat'] ?? null;
            $record->showsInComposer = (bool)($data['showsInComposer'] ?? true);
            $record->sortOrder = $data['sortOrder'] ?? 0;
            $record->uid = $uid;

            if (!empty($data['fieldLayouts'])) {
                $layoutConfig = reset($data['fieldLayouts']);
                $layoutUid = key($data['fieldLayouts']);

                $layout = FieldLayout::createFromConfig($layoutConfig);
                $layout->id = $record->fieldLayoutId;
                $layout->type = Update::class;
                $layout->uid = $layoutUid;

                Craft::$app->getFields()->saveLayout($layout, false);
                $record->fieldLayoutId = $layout->id;
            } elseif ($record->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($record->fieldLayoutId);
                $record->fieldLayoutId = null;
            }

            $record->save(false);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->_types = null;
    }

    public function handleDeletedType(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];

        $record = UpdateTypeRecord::findOne(['uid' => $uid]);

        if (!$record) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Updates of this type go with it — the FK is CASCADE on the sub-table, but the
            // elements themselves need soft-deleting so they leave the feed properly.
            $updateIds = Update::find()
                ->typeId($record->id)
                ->status(null)
                ->siteId('*')
                ->unique()
                ->ids();

            foreach ($updateIds as $updateId) {
                Craft::$app->getElements()->deleteElementById($updateId, Update::class, null, true);
            }

            if ($record->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($record->fieldLayoutId);
            }

            Craft::$app->getDb()->createCommand()
                ->delete(Table::UPDATETYPES, ['id' => $record->id])
                ->execute();

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->_types = null;
    }

    private function getRecord(string $uid): UpdateTypeRecord
    {
        return UpdateTypeRecord::findOne(['uid' => $uid])
            ?? UpdateTypeRecord::findWithTrashed()->where(['uid' => $uid])->one()
            ?? new UpdateTypeRecord();
    }
}
