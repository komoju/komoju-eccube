<?php

namespace Eccube\Service\Payment;

class PaymentDispatcher
{
    private $response;

    public function setResponse($response) { $this->response = $response; }
    public function getResponse() { return $this->response; }
}
