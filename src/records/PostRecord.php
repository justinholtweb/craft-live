<?php

namespace justinholtweb\live\records;

use craft\db\ActiveRecord;
use justinholtweb\live\db\Table;

/**
 * The head row for one live post on one site.
 *
 * @property int $id
 * @property int $postId
 * @property int $fieldId
 * @property int $siteId
 * @property string $state
 * @property int $seq
 * @property int $updateCount
 * @property int|null $pinnedUpdateId
 * @property int $snapshotSeq
 * @property string|null $startedAt
 * @property string|null $endedAt
 * @property string|null $lastPublishedAt
 * @property string $uid
 */
class PostRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::POSTS;
    }
}
