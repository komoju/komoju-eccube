<?php

namespace Doctrine\DBAL\Schema;

/**
 * Test stub: minimal SchemaManager surface used by KOMOJU plugin code.
 */
abstract class AbstractSchemaManager
{
    abstract public function tablesExist($names): bool;
    abstract public function listTableColumns(string $table): array;
}
