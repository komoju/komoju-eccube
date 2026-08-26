<?php

namespace Eccube\Service\Payment;

use Symfony\Component\Form\FormInterface;
use Eccube\Entity\Order;

interface PaymentMethodInterface
{
    public function verify();
    public function apply();
    public function checkout();
    public function setFormType(FormInterface $form);
    public function setOrder(Order $Order);
}
