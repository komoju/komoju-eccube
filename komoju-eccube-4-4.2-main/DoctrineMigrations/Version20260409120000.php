<?php declare(strict_types=1);

namespace  Plugin\Komoju42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_multi_pays')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_multi_pays');

        if(!$table->hasColumn('payment_id')){
            $table->addColumn('payment_id', 'integer', ['notnull' => false, 'default' => null]);
        }
    }

    public function down(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_multi_pays')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_multi_pays');

        if($table->hasColumn('payment_id')){
            $table->dropColumn('payment_id');
        }
    }
}
