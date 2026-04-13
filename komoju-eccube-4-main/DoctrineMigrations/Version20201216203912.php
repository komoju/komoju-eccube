<?php declare(strict_types=1);

namespace  Plugin\Komoju\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201216203912 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_order');

        if(!$table->hasColumn('canceled_at')){
            $table->addColumn('canceled_at', 'datetimetz', ['notnull' => false, 'default' => null]);
        }
    }

    public function down(Schema $schema) : void
    {
        if (!$schema->hasTable('plg_komoju_order')) {
            return;
        }
        $table = $schema->getTable('plg_komoju_order');

        if($table->hasColumn('canceled_at')){
            $table->dropColumn('canceled_at');
        }
    }
}
