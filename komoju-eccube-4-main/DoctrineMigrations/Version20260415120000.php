<?php declare(strict_types=1);

namespace Plugin\Komoju\DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migrate data from legacy plg_komoju_multi_pays to plg_komoju_payments.
 *
 * EC-CUBE's SchemaTool::updateSchema() runs before plugin migrations,
 * so plg_komoju_payments will already exist (created from the Entity annotation).
 * This migration copies existing rows from the old table and drops it.
 */
final class Version20260415120000 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        // The old table may not exist on fresh installs
        $tables = $this->connection->createSchemaManager()->listTableNames();
        if (!in_array('plg_komoju_multi_pays', $tables, true)) {
            return;
        }

        // Copy data from old table to new (created by SchemaUpdate).
        //
        // We use `WHERE NOT EXISTS` instead of platform-specific
        // upsert syntax (`INSERT OR IGNORE` is SQLite-only, `INSERT IGNORE`
        // is MySQL-only, `ON CONFLICT DO NOTHING` is PG ≥ 9.5 and requires
        // a unique constraint on the conflicting column). This portable form
        // works on SQLite, MySQL, and PostgreSQL without branching.
        $this->addSql(
            'INSERT INTO plg_komoju_payments '
            . 'SELECT * FROM plg_komoju_multi_pays src '
            . 'WHERE NOT EXISTS ('
            . '    SELECT 1 FROM plg_komoju_payments dst WHERE dst.id = src.id'
            . ')'
        );
        $this->addSql('DROP TABLE plg_komoju_multi_pays');

        // Update payment method_class from renamed KomojuMultiPay to KomojuPayment
        $this->addSql("UPDATE dtb_payment SET method_class = 'Plugin\\Komoju\\Service\\Method\\KomojuPayment' WHERE method_class = 'Plugin\\Komoju\\Service\\Method\\KomojuMultiPay'");
    }

    public function down(Schema $schema) : void
    {
        // No rollback — the old table name is fully retired
    }
}
