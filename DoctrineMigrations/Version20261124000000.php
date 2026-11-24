<?php declare(strict_types=1);

namespace Plugin\Komoju42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('plg_komoju_order');
        if (!$table->hasColumn('confirmation_token')) {
            $table->addColumn('confirmation_token', 'string', ['length' => 32, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('plg_komoju_order');
        if ($table->hasColumn('confirmation_token')) {
            $table->dropColumn('confirmation_token');
        }
    }
}
