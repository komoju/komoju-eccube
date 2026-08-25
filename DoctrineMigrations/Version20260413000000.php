<?php declare(strict_types=1);

namespace  Plugin\Komoju42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413000000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_config')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_config');

        if (!$table->hasColumn('order_number_format')) {
            $table->addColumn('order_number_format', 'string', ['notnull' => false, 'length' => 255, 'default' => null]);
        }
    }

    public function down(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_config')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_config');

        if ($table->hasColumn('order_number_format')) {
            $table->dropColumn('order_number_format');
        }
    }
}
