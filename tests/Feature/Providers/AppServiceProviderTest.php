<?php

use App\Interfaces\PaymentInterface;
use App\Services\Payment\IyzicoGateway;
use App\Services\Payment\PaynetGateway;
use App\Services\Payment\PaytrGateway;

it('resolves the configured payment gateway', function (string $driver, string $gateway) {
    config(['payment.default' => $driver]);

    expect(app(PaymentInterface::class))->toBeInstanceOf($gateway);
})->with([
    'Paynet' => ['paynet', PaynetGateway::class],
    'Iyzico' => ['iyzico', IyzicoGateway::class],
    'PayTR' => ['paytr', PaytrGateway::class],
]);

it('rejects an unknown payment gateway', function () {
    config(['payment.default' => 'unknown']);

    app(PaymentInterface::class);
})->throws(LogicException::class, 'Payment gateway [unknown] is not configured correctly.');
