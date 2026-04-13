<?php declare(strict_types=1);

namespace Plugin\Komoju\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413100000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        $table = $schema->getTable('plg_komoju_log');

        if (!$table->hasColumn('is_protected')) {
            $table->addColumn('is_protected', 'smallint', ['notnull' => false, 'default' => 0]);
        }
    }

    public function down(Schema $schema) : void
    {
        $table = $schema->getTable('plg_komoju_log');

        if ($table->hasColumn('is_protected')) {
            $table->dropColumn('is_protected');
        }
    }
}
