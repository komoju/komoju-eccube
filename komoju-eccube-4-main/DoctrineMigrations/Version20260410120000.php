<?php declare(strict_types=1);

namespace  Plugin\Komoju\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        $table = $schema->getTable('plg_komoju_config');

        if (!$table->hasColumn('log_retention_days')) {
            $table->addColumn('log_retention_days', 'integer', ['notnull' => false, 'default' => null]);
        }

        if (!$table->hasColumn('logging_enabled')) {
            $table->addColumn('logging_enabled', 'smallint', ['notnull' => false, 'default' => 1]);
        }
    }

    public function down(Schema $schema) : void
    {
        $table = $schema->getTable('plg_komoju_config');

        if ($table->hasColumn('log_retention_days')) {
            $table->dropColumn('log_retention_days');
        }

        if ($table->hasColumn('logging_enabled')) {
            $table->dropColumn('logging_enabled');
        }
    }
}
