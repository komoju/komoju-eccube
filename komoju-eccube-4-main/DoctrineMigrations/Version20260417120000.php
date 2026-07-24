<?php declare(strict_types=1);

namespace Plugin\Komoju42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_order');

        if (!$table->hasColumn('captured_amount')) {
            $table->addColumn('captured_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'notnull' => false, 'default' => null]);
        }
    }

    public function down(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_order');

        if ($table->hasColumn('captured_amount')) {
            $table->dropColumn('captured_amount');
        }
    }
}
