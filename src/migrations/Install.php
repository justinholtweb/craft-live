<?php

namespace justinholtweb\live\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\live\db\Table;

/**
 * Live's schema.
 *
 * Three tables: the update sub-table (one row per update element), the head row per live post
 * (state, sequence, counters — the hot row every publish touches), and update types.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::UPDATES);
        $this->dropTableIfExists(Table::POSTS);
        $this->dropTableIfExists(Table::UPDATETYPES);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::UPDATETYPES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'icon' => $this->string(),
            'color' => $this->string(32),
            'description' => $this->text(),
            'hasTitleField' => $this->boolean()->defaultValue(false)->notNull(),
            'titleFormat' => $this->string(),
            'showsInComposer' => $this->boolean()->defaultValue(true)->notNull(),
            'fieldLayoutId' => $this->integer(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);

        // One row per live post, per site. Everything the composer and the poll endpoint need to
        // answer "what's the latest?" without touching the updates table.
        $this->createTable(Table::POSTS, [
            'id' => $this->primaryKey(),
            'postId' => $this->integer()->notNull(),
            'fieldId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'state' => $this->string(16)->defaultValue('upcoming')->notNull(),
            'seq' => $this->integer()->defaultValue(0)->notNull(),
            'updateCount' => $this->integer()->defaultValue(0)->notNull(),
            'pinnedUpdateId' => $this->integer(),
            'snapshotSeq' => $this->integer()->defaultValue(0)->notNull(),
            'startedAt' => $this->dateTime(),
            'endedAt' => $this->dateTime(),
            'lastPublishedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::UPDATES, [
            'id' => $this->integer()->notNull(),
            'postId' => $this->integer()->notNull(),
            'fieldId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'typeId' => $this->integer()->notNull(),
            'seq' => $this->integer()->notNull(),
            // The one thing every live update has. Custom fields on the update type sit on top of
            // it; this is what the composer's main box writes, so the plugin works with no setup.
            'body' => $this->text(),
            'postedAt' => $this->dateTime()->notNull(),
            'pinned' => $this->boolean()->defaultValue(false)->notNull(),
            'highlight' => $this->boolean()->defaultValue(false)->notNull(),
            'authorId' => $this->integer(),
            // Idempotency key from the composer's offline queue: a retried POST must not publish
            // the same update twice.
            'clientId' => $this->string(64),
            'meta' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::UPDATETYPES, ['handle'], false);
        $this->createIndex(null, Table::UPDATETYPES, ['fieldLayoutId'], false);
        $this->createIndex(null, Table::UPDATETYPES, ['dateDeleted'], false);

        $this->createIndex(null, Table::POSTS, ['postId', 'fieldId', 'siteId'], true);
        $this->createIndex(null, Table::POSTS, ['siteId'], false);
        $this->createIndex(null, Table::POSTS, ['state'], false);

        // The feed query: everything for one post, newest first.
        $this->createIndex(null, Table::UPDATES, ['postId', 'fieldId', 'siteId', 'seq'], true);
        $this->createIndex(null, Table::UPDATES, ['postId', 'postedAt'], false);
        $this->createIndex(null, Table::UPDATES, ['postId', 'clientId'], true);
        $this->createIndex(null, Table::UPDATES, ['typeId'], false);
        $this->createIndex(null, Table::UPDATES, ['authorId'], false);
        $this->createIndex(null, Table::UPDATES, ['pinned'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::UPDATETYPES, ['fieldLayoutId'], CraftTable::FIELDLAYOUTS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::POSTS, ['postId'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::POSTS, ['fieldId'], CraftTable::FIELDS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::POSTS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::POSTS, ['pinnedUpdateId'], CraftTable::ELEMENTS, ['id'], 'SET NULL');

        $this->addForeignKey(null, Table::UPDATES, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::UPDATES, ['postId'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::UPDATES, ['fieldId'], CraftTable::FIELDS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::UPDATES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::UPDATES, ['typeId'], Table::UPDATETYPES, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::UPDATES, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL');
    }
}
