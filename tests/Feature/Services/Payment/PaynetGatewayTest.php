<?php

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentPayDto;
use App\Models\Customer;
use App\Services\Payment\PaynetGateway;
use Paynet\DTOs\PaymentParams;
use Paynet\DTOs\PaymentResponse;
use Paynet\DTOs\TdsChargeParams;
use Paynet\DTOs\TdsChargeResponse;
use Paynet\DTOs\TdsInitialParams;
use Paynet\DTOs\TdsInitialResponse;
use Paynet\Facades\Paynet;

it('maps a successful Paynet response to the application result', function () {
    config(['payment.gateways.paynet.domain' => 'payments.test']);

    Paynet::shouldReceive('payment')
        ->once()
        ->withArgs(fn (PaymentParams $params): bool => $params->amount === '100,00'
            && $params->domain === 'payments.test'
            && $params->referenceNo === 'SIP-100')
        ->andReturn(PaymentResponse::fromArray([
            'result_code' => 0,
            'is_succeed' => true,
            'reference_no' => 'SIP-100',
            'xact_id' => 'PAYNET-123',
        ]));

    $result = app(PaynetGateway::class)->pay(paynetPaymentDto());

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => 'PAYNET-123',
        'clientAction' => null,
    ]);
});

it('maps a declined Paynet response to the application result', function () {
    Paynet::shouldReceive('payment')
        ->once()
        ->andReturn(PaymentResponse::fromArray([
            'result_code' => 1,
            'is_succeed' => false,
            'reference_no' => 'SIP-100',
            'bank_error_message' => 'Card was declined.',
        ]));

    $result = app(PaynetGateway::class)->pay(paynetPaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Card was declined.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('starts 3D Secure authentication and returns the redirect URL', function () {
    config([
        'payment.gateways.paynet.domain' => 'payments.test',
        'payment.gateways.paynet.return_url' => 'https://merchant.test/payment/callback',
    ]);

    Paynet::shouldReceive('tdsInitial')
        ->once()
        ->withArgs(fn (TdsInitialParams $params): bool => $params->amount === '100,00'
            && $params->domain === 'payments.test'
            && $params->returnUrl === 'https://merchant.test/payment/callback'
            && $params->referenceNo === 'SIP-100')
        ->andReturn(TdsInitialResponse::fromArray([
            'result_code' => 0,
            'post_url' => 'https://paynet.test/3d-secure',
        ]));

    $result = app(PaynetGateway::class)->pay(paynetPaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => [
            'type' => 'redirect',
            'url' => 'https://paynet.test/3d-secure',
            'html' => null,
            'fields' => null,
        ],
    ]);
});

it('returns a failure when 3D Secure authentication cannot be started', function () {
    Paynet::shouldReceive('tdsInitial')
        ->once()
        ->andReturn(TdsInitialResponse::fromArray([
            'result_code' => 1,
            'message' => '3D Secure could not be started.',
        ]));

    $result = app(PaynetGateway::class)->pay(paynetPaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => '3D Secure could not be started.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('returns a failure when Paynet omits the 3D Secure redirect URL', function () {
    Paynet::shouldReceive('tdsInitial')
        ->once()
        ->andReturn(TdsInitialResponse::fromArray([
            'result_code' => 0,
        ]));

    $result = app(PaynetGateway::class)->pay(paynetPaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => '3D Secure payment could not be initiated.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('completes a successful 3D Secure payment from Paynet callback data', function () {
    Paynet::shouldReceive('tdsCharge')
        ->once()
        ->withArgs(fn (TdsChargeParams $params): bool => $params->sessionId === 'SESSION-123'
            && $params->tokenId === 'TOKEN-123')
        ->andReturn(TdsChargeResponse::fromArray([
            'result_code' => 0,
            'is_succeed' => true,
            'reference_no' => 'SIP-100',
            'xact_id' => 'PAYNET-123',
        ]));

    $result = app(PaynetGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray([
            'session_id' => 'SESSION-123',
            'token_id' => 'TOKEN-123',
        ]),
    );

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => 'PAYNET-123',
        'clientAction' => null,
    ]);
});

it('maps a declined 3D Secure charge to the application result', function () {
    Paynet::shouldReceive('tdsCharge')
        ->once()
        ->andReturn(TdsChargeResponse::fromArray([
            'result_code' => 1,
            'is_succeed' => false,
            'reference_no' => 'SIP-100',
            'bank_error_message' => '3D Secure payment was declined.',
        ]));

    $result = app(PaynetGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray([
            'session_id' => 'SESSION-123',
            'token_id' => 'TOKEN-123',
        ]),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => '3D Secure payment was declined.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects incomplete Paynet callback data before charging', function () {
    Paynet::expects('tdsCharge')->never();

    $result = app(PaynetGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray([
            'session_id' => 'SESSION-123',
        ]),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Paynet 3D Secure callback data is incomplete.',
        'referenceNo' => null,
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

function paynetPaymentDto(bool $threeDSecure = false): PaymentPayDto
{
    $customer = new Customer([
        'name' => 'John',
        'surname' => 'Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);
    $customer->id = 42;

    return new PaymentPayDto(
        customer: $customer,
        amountInMinorUnits: 10_000,
        referenceNo: 'SIP-100',
        clientIp: '203.0.113.10',
        customerAddress: 'Test Address',
        card: new PaymentCardDto(
            pan: '5400617004770430',
            month: '12',
            year: '2030',
            cvv: '123',
            cardHolder: 'John Doe',
        ),
        threeDSecure: $threeDSecure,
    );
}
