<?php declare(strict_types=1);

namespace  Plugin\Komoju\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');
        $table = $schema->getTable('plg_komoju_multi_pays');

        if(!$table->hasColumn('payment_id')){
            $this->addSql('ALTER TABLE plg_komoju_multi_pays ADD payment_id INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema) : void
    {
        $table = $schema->getTable('plg_komoju_multi_pays');

        if($table->hasColumn('payment_id')){
            $this->addSql('ALTER TABLE plg_komoju_multi_pays DROP payment_id');
        }
    }
}
