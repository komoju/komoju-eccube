<?php

namespace Doctrine\DBAL;

/**
 * Test stub: minimal Connection surface used by KOMOJU plugin code.
 * The real DBAL Connection isn't available in the test environment because
 * EC-CUBE plugin tests run without a full ec-cube/composer install.
 */
class Connection
{
    public function fetchOne(string $sql, array $params = [])
    {
        return false;
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        return [];
    }

    public function fetchAssociative(string $sql, array $params = [])
    {
        return false;
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        return 0;
    }

    public function insert(string $table, array $data): int
    {
        return 1;
    }

    public function update(string $table, array $data, array $criteria): int
    {
        return 1;
    }

    public function createSchemaManager()
    {
        return null;
    }

    public function getSchemaManager()
    {
        return null;
    }

    public function getDatabasePlatform()
    {
        return null;
    }
}
