<?php

namespace justinholtweb\live\records;

use craft\db\ActiveRecord;
use craft\db\SoftDeleteTrait;
use justinholtweb\live\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $description
 * @property bool $hasTitleField
 * @property string|null $titleFormat
 * @property bool $showsInComposer
 * @property int|null $fieldLayoutId
 * @property int|null $sortOrder
 * @property string $uid
 */
class UpdateTypeRecord extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return Table::UPDATETYPES;
    }
}
