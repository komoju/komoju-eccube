<?php

namespace Eccube\Repository;

class OrderRepository
{
    public function find($id) { return null; }
    public function findOneBy(array $criteria, ?array $orderBy = null) { return null; }
    public function findBy(array $criteria, ?array $orderBy = null) { return []; }
    public function updateOrderSummary($customer) {}
}
