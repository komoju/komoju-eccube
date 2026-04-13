<?php

namespace Eccube\Service\Payment;

class PaymentResult
{
    private $success;
    private $errors = [];

    public function setSuccess($success) { $this->success = $success; }
    public function getSuccess() { return $this->success; }
    public function setErrors($errors) { $this->errors = $errors; }
    public function getErrors() { return $this->errors; }
}
