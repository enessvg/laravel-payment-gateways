<?php

namespace App\Interfaces;

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCallbackResultDto;
use App\DTOs\PaymentPayDto;
use App\DTOs\PaymentPayResultDto;

interface PaymentInterface
{
    public function requiresServerSideCard(): bool;

    public function pay(PaymentPayDto $payment): PaymentPayResultDto;

    public function handleCallback(PaymentCallbackDto $callback): PaymentCallbackResultDto;
}
