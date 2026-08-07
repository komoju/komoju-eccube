<?php

namespace Eccube\Repository;

/**
 * Minimal stub of Eccube\Repository\AbstractRepository so plugin repositories
 * that extend it (e.g. KomojuOrderRepository) can be loaded and mocked in
 * unit tests without a full EC-CUBE / Doctrine install.
 */
class AbstractRepository
{
    public function find($id) { return null; }
    public function findOneBy(array $criteria, ?array $orderBy = null) { return null; }
    public function findBy(array $criteria, ?array $orderBy = null) { return []; }
    public function count(array $criteria) { return 0; }
}
