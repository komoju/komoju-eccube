<?php

namespace Eccube\Service\Payment;

interface PaymentMethodInterface
{
    public function verify();
    public function apply();
    public function checkout();
    public function setFormType($formType);
    public function setOrder($Order);
}
