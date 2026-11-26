<?php

namespace Eccube\Entity;

class Page
{
    public const EDIT_TYPE_DEFAULT = 2;
    private $editType;
    private $metaRobots;
    public function setEditType($value) { $this->editType = $value; return $this; }
    public function setMetaRobots($value) { $this->metaRobots = $value; return $this; }
}
