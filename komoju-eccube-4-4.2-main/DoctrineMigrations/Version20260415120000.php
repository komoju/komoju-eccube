<?php declare(strict_types=1);

namespace Plugin\Komoju42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        if ($schema->hasTable('plg_komoju_multi_pays') && !$schema->hasTable('plg_komoju_payments')) {
            $this->addSql('ALTER TABLE plg_komoju_multi_pays RENAME TO plg_komoju_payments');
        }
    }

    public function down(Schema $schema) : void
    {
        if ($schema->hasTable('plg_komoju_payments') && !$schema->hasTable('plg_komoju_multi_pays')) {
            $this->addSql('ALTER TABLE plg_komoju_payments RENAME TO plg_komoju_multi_pays');
        }
    }
}
