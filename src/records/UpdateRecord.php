<?php

namespace justinholtweb\live\records;

use craft\db\ActiveRecord;
use justinholtweb\live\db\Table;

/**
 * @property int $id
 * @property int $postId
 * @property int $fieldId
 * @property int $siteId
 * @property int $typeId
 * @property int $seq
 * @property string $postedAt
 * @property bool $pinned
 * @property bool $highlight
 * @property int|null $authorId
 * @property string|null $clientId
 * @property string|null $meta
 * @property string $uid
 */
class UpdateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::UPDATES;
    }
}
