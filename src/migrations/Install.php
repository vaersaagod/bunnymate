<?php

namespace vaersaagod\bunnymate\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\helpers\Db;

use vaersaagod\bunnymate\db\Table;

/**
 * BunnyMate install migration
 *
 * @author Værsågod
 * @since 2.1.0
 */
class Install extends Migration
{

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createVideosTable();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::VIDEOS);
        return true;
    }

    /**
     * Creates the videos table, its indexes and its foreign key.
     *
     * Written to be safely re-runnable: createIndex() and addForeignKey() are not
     * idempotent, and MySQL silently accepts duplicates until the table hits its
     * 64-key ceiling.
     *
     * @return void
     */
    public function createVideosTable(): void
    {
        if (!$this->db->tableExists(Table::VIDEOS)) {
            $this->createTable(Table::VIDEOS, [
                'id' => $this->primaryKey(),
                // Nullable on purpose: when Craft's garbage collection purges a trashed
                // asset it deletes elements with raw SQL and fires no events, so a cascade
                // would take the video's ID with it and leave the video stranded on Bunny.
                // Setting it null instead keeps the ID around to clean up from.
                'assetId' => $this->integer(),
                'library' => $this->string()->notNull(),
                'videoGuid' => $this->uid()->notNull(),
                'status' => $this->integer()->notNull()->defaultValue(0),
                'metadata' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        $this->createIndexIfMissing(Table::VIDEOS, ['assetId'], true);
        $this->createIndexIfMissing(Table::VIDEOS, ['videoGuid'], true);
        $this->createIndexIfMissing(Table::VIDEOS, ['library']);

        if (!Db::findForeignKey(Table::VIDEOS, 'assetId')) {
            $this->addForeignKey(null, Table::VIDEOS, ['assetId'], CraftTable::ASSETS, ['id'], 'SET NULL', null);
        }
    }

}
