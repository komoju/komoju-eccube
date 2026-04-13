<?php

namespace Eccube\Entity;

class Payment
{
    private $id;
    private $method_class;
    private $method;
    private $rule_min;
    private $rule_max;
    private $visible;
    private $sort_no;
    private $charge;

    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; return $this; }
    public function getMethodClass() { return $this->method_class; }
    public function setMethodClass($class) { $this->method_class = $class; return $this; }
    public function getMethod() { return $this->method; }
    public function setMethod($method) { $this->method = $method; return $this; }
    public function getRuleMin() { return $this->rule_min; }
    public function setRuleMin($min) { $this->rule_min = $min; return $this; }
    public function getRuleMax() { return $this->rule_max; }
    public function setRuleMax($max) { $this->rule_max = $max; return $this; }
    public function getVisible() { return $this->visible; }
    public function setVisible($visible) { $this->visible = $visible; return $this; }
    public function getSortNo() { return $this->sort_no; }
    public function setSortNo($no) { $this->sort_no = $no; return $this; }
    public function getCharge() { return $this->charge; }
    public function setCharge($charge) { $this->charge = $charge; return $this; }
}
