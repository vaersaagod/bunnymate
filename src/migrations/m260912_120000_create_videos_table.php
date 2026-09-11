<?php

namespace vaersaagod\bunnymate\migrations;

use craft\db\Migration;

use vaersaagod\bunnymate\db\Table;

/**
 * Creates the videos table on installs that predate Bunny Stream support.
 *
 * @author Værsågod
 * @since 2.1.0
 */
class m260912_120000_create_videos_table extends Migration
{

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        (new Install())->createVideosTable();
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

}
