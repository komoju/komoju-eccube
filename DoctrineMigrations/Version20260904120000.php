<?php declare(strict_types=1);

namespace Plugin\Komoju42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }

        $table = $schema->getTable('plg_komoju_order');
        if (!$table->hasColumn('expected_amount')) {
            $table->addColumn('expected_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'notnull' => false]);
        }
        if (!$table->hasColumn('expected_currency')) {
            $table->addColumn('expected_currency', 'string', ['length' => 3, 'notnull' => false]);
        }
        if (!$table->hasColumn('callback_token_hash')) {
            $table->addColumn('callback_token_hash', 'string', ['length' => 64, 'notnull' => false]);
        }
    }

    public function down(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }

        $table = $schema->getTable('plg_komoju_order');
        foreach (['expected_amount', 'expected_currency', 'callback_token_hash'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
}
