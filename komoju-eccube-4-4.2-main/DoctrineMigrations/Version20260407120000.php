<?php declare(strict_types=1);

namespace  Plugin\Komoju42\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');
        $table = $schema->getTable('plg_komoju_order');

        if(!$table->hasColumn('komoju_session_id')){
            $this->addSql('ALTER TABLE plg_komoju_order ADD komoju_session_id VARCHAR(255) DEFAULT NULL');
        }

        // Make payment_token and komoju_payment_id nullable for sessions flow
        $this->addSql('ALTER TABLE plg_komoju_order MODIFY payment_token VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE plg_komoju_order MODIFY komoju_payment_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema) : void
    {
        $table = $schema->getTable('plg_komoju_order');

        if($table->hasColumn('komoju_session_id')){
            $this->addSql('ALTER TABLE plg_komoju_order DROP komoju_session_id');
        }
    }
}
