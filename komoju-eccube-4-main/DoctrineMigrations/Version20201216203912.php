<?php declare(strict_types=1);

namespace  Plugin\komoju\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201216203912 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');
        $table = $schema->getTable('plg_komoju_order');

        if(!$table->hasColumn('canceled_at')){
            $this->addSql('ALTER TABLE plg_komoju_order ADD canceled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetimetz)\'');
        }
    }

    public function down(Schema $schema) : void
    {
        $table = $schema->getTable('plg_komoju_order');

        if($table->hasColumn('canceled_at')){
            $this->addSql('ALTER TABLE plg_komoju_order DROP canceled_at');
        }
    }
}
