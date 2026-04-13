<?php declare(strict_types=1);

namespace  Plugin\Komoju\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_order');

        if(!$table->hasColumn('komoju_session_id')){
            $table->addColumn('komoju_session_id', 'string', ['notnull' => false, 'length' => 255, 'default' => null]);
        }

        // Make payment_token and komoju_payment_id nullable for sessions flow
        if($table->hasColumn('payment_token')){
            $table->getColumn('payment_token')->setNotnull(false);
        }
        if($table->hasColumn('komoju_payment_id')){
            $table->getColumn('komoju_payment_id')->setNotnull(false);
        }
    }

    public function down(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_order');

        if($table->hasColumn('komoju_session_id')){
            $table->dropColumn('komoju_session_id');
        }
    }
}
