<?php

namespace Doctrine\ORM;

interface EntityManagerInterface
{
    public function find($entityName, $id);
    public function persist($entity);
    public function remove($entity);
    public function flush($entity = null);
    public function getRepository($entityName);
    public function clear($entityName = null);
    public function getConnection();
    public function createQueryBuilder();
}
