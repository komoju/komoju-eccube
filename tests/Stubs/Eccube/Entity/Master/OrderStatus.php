<?php

namespace Eccube\Entity\Master;

class OrderStatus
{
    const NEW = 1;
    const PENDING = 7;
    const CANCEL = 3;
    const IN_PROGRESS = 4;
    const DELIVERED = 5;
    const PAID = 6;
    const PROCESSING = 8;
    const RETURNED = 9;

    private $id;
    private $name;

    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; return $this; }
    public function getName() { return $this->name; }
    public function setName($name) { $this->name = $name; return $this; }
}
