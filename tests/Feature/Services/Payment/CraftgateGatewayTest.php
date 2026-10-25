<?php

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentPayDto;
use App\Models\Customer;
use App\Services\Payment\CraftgateGateway;
use Craftgate\Adapter\PaymentAdapter;
use Craftgate\Craftgate;
use Craftgate\Model\Currency;
use Craftgate\Model\PaymentGroup;
use Craftgate\Model\PaymentStatus;
use Mockery\MockInterface;

beforeEach(function () {
    config([
        'payment.gateways.craftgate.api_key' => 'test-api-key',
        'payment.gateways.craftgate.secret_key' => 'test-secret-key',
        'payment.gateways.craftgate.base_url' => 'https://sandbox-api.craftgate.test',
        'payment.gateways.craftgate.return_url' => 'https://merchant.test/api/payment/callback',
        'payment.gateways.craftgate.callback_key' => 'test-callback-key',
    ]);
});

it('maps a successful Craftgate payment and sends the shared payment request', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $paymentAdapter->shouldReceive('createPayment')
        ->once()
        ->with(craftgatePaymentRequest('payment'))
        ->andReturn(craftgateJsonResponse([
            'id' => 123,
            'conversationId' => 'SIP-100',
            'paymentStatus' => PaymentStatus::SUCCESS,
        ]));

    $result = $gateway->pay(craftgatePaymentDto());

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => '123',
        'clientAction' => null,
    ]);
});

it('maps a Craftgate API error to the application result', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $paymentAdapter->shouldReceive('createPayment')
        ->once()
        ->andReturn(craftgateJsonResponse(errors: [
            'errorCode' => '10051',
            'errorGroup' => 'PAYMENT_FAILURE',
            'errorDescription' => 'Card was declined.',
        ]));

    $result = $gateway->pay(craftgatePaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Card was declined.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('maps a failed Craftgate payment status to the application result', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $paymentAdapter->shouldReceive('createPayment')
        ->once()
        ->andReturn(craftgateJsonResponse([
            'conversationId' => 'SIP-100',
            'paymentStatus' => PaymentStatus::FAILURE,
            'paymentError' => [
                'errorDescription' => 'Insufficient funds.',
            ],
        ]));

    $result = $gateway->pay(craftgatePaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Insufficient funds.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('starts Craftgate 3D Secure authentication and returns decoded HTML', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $html = '<form action="https://bank.test/3d" method="post"></form>';
    $paymentAdapter->shouldReceive('init3DSPayment')
        ->once()
        ->with([
            ...craftgatePaymentRequest('3ds-init'),
            'callbackUrl' => 'https://merchant.test/api/payment/callback',
        ])
        ->andReturn(craftgateJsonResponse([
            'paymentId' => 123,
            'paymentStatus' => PaymentStatus::INIT_THREEDS,
            'htmlContent' => base64_encode($html),
        ]));

    $result = $gateway->pay(craftgatePaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => [
            'type' => 'html',
            'url' => null,
            'html' => $html,
            'fields' => null,
        ],
    ]);
});

it('returns Craftgate 3D Secure redirect URLs when HTML is not provided', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $paymentAdapter->shouldReceive('init3DSPayment')
        ->once()
        ->andReturn(craftgateJsonResponse([
            'paymentId' => 123,
            'paymentStatus' => PaymentStatus::INIT_THREEDS,
            'redirectUrl' => 'https://bank.test/3d-secure',
        ]));

    $result = $gateway->pay(craftgatePaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => [
            'type' => 'redirect',
            'url' => 'https://bank.test/3d-secure',
            'html' => null,
            'fields' => null,
        ],
    ]);
});

it('returns a failure when Craftgate omits the 3D Secure client action', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $paymentAdapter->shouldReceive('init3DSPayment')
        ->once()
        ->andReturn(craftgateJsonResponse([
            'paymentId' => 123,
            'paymentStatus' => PaymentStatus::INIT_THREEDS,
        ]));

    $result = $gateway->pay(craftgatePaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Craftgate returned no usable 3D Secure client action.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('does not call Craftgate when card details are missing', function () {
    $craftgate = Mockery::mock(Craftgate::class);
    $craftgate->shouldNotReceive('payment');

    $result = (new CraftgateGateway($craftgate))->pay(
        craftgatePaymentDto(withCard: false),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Card details are required for Craftgate payments.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('does not call Craftgate when credentials are missing', function () {
    config(['payment.gateways.craftgate.secret_key' => null]);
    $craftgate = Mockery::mock(Craftgate::class);
    $craftgate->shouldNotReceive('payment');

    $result = (new CraftgateGateway($craftgate))->pay(craftgatePaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Craftgate credentials are not configured.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('does not start 3D Secure when callback settings are missing', function () {
    config(['payment.gateways.craftgate.callback_key' => null]);
    $craftgate = Mockery::mock(Craftgate::class);
    $craftgate->shouldNotReceive('payment');

    $result = (new CraftgateGateway($craftgate))->pay(
        craftgatePaymentDto(threeDSecure: true),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Craftgate 3D Secure settings are not configured.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('normalizes an invalid Craftgate response without exposing the parser error', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $paymentAdapter->shouldReceive('createPayment')
        ->once()
        ->andReturn('not-json');

    $result = $gateway->pay(craftgatePaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Craftgate payment service could not be reached.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('verifies and completes a waiting Craftgate 3D Secure payment', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $payload = craftgateCallbackPayload();
    $paymentAdapter->shouldReceive('is3DSecureCallbackVerified')
        ->once()
        ->with('test-callback-key', $payload)
        ->andReturnTrue();
    $paymentAdapter->shouldReceive('complete3DSPayment')
        ->once()
        ->with([
            'paymentId' => 123,
            'headerOptions' => [
                'idempotencyKey' => hash('sha256', 'craftgate:3ds-complete:123'),
            ],
        ])
        ->andReturn(craftgateJsonResponse([
            'id' => 123,
            'conversationId' => 'SIP-100',
            'paymentStatus' => PaymentStatus::SUCCESS,
        ]));

    $result = $gateway->handleCallback(PaymentCallbackDto::fromArray($payload));

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => '123',
        'clientAction' => null,
    ]);
});

it('accepts an already completed Craftgate callback without completing it again', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $payload = craftgateCallbackPayload(['completeStatus' => 'COMPLETED']);
    $paymentAdapter->shouldReceive('is3DSecureCallbackVerified')
        ->once()
        ->with('test-callback-key', $payload)
        ->andReturnTrue();
    $paymentAdapter->shouldNotReceive('complete3DSPayment');

    $result = $gateway->handleCallback(PaymentCallbackDto::fromArray($payload));

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => '123',
        'clientAction' => null,
    ]);
});

it('rejects an invalid Craftgate callback signature before completing the payment', function () {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $payload = craftgateCallbackPayload();
    $paymentAdapter->shouldReceive('is3DSecureCallbackVerified')
        ->once()
        ->with('test-callback-key', $payload)
        ->andReturnFalse();
    $paymentAdapter->shouldNotReceive('complete3DSPayment');

    $result = $gateway->handleCallback(PaymentCallbackDto::fromArray($payload));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Craftgate 3D Secure callback signature is invalid.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects verified Craftgate callbacks that cannot be completed', function (
    string $status,
    string $completeStatus,
    string $message,
) {
    [$gateway, $paymentAdapter] = craftgateGateway();
    $payload = craftgateCallbackPayload([
        'status' => $status,
        'completeStatus' => $completeStatus,
    ]);
    $paymentAdapter->shouldReceive('is3DSecureCallbackVerified')
        ->once()
        ->with('test-callback-key', $payload)
        ->andReturnTrue();
    $paymentAdapter->shouldNotReceive('complete3DSPayment');

    $result = $gateway->handleCallback(PaymentCallbackDto::fromArray($payload));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => $message,
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
})->with([
    'failed authentication' => [
        'FAILURE',
        'WAITING',
        'Craftgate 3D Secure authentication failed.',
    ],
    'unknown completion status' => [
        'SUCCESS',
        'UNKNOWN',
        'Craftgate 3D Secure callback status is invalid.',
    ],
]);

it('rejects incomplete Craftgate callback data before verification', function () {
    $craftgate = Mockery::mock(Craftgate::class);
    $craftgate->shouldNotReceive('payment');
    $gateway = new CraftgateGateway($craftgate);

    $result = $gateway->handleCallback(PaymentCallbackDto::fromArray([
        'conversationId' => 'SIP-100',
        'paymentId' => '123',
    ]));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Craftgate 3D Secure callback data is incomplete.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects callbacks when the Craftgate callback key is missing', function () {
    config(['payment.gateways.craftgate.callback_key' => null]);
    $craftgate = Mockery::mock(Craftgate::class);
    $craftgate->shouldNotReceive('payment');
    $gateway = new CraftgateGateway($craftgate);

    $result = $gateway->handleCallback(
        PaymentCallbackDto::fromArray(craftgateCallbackPayload()),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Craftgate 3D Secure callback key is not configured.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

/**
 * @return array{CraftgateGateway, MockInterface&PaymentAdapter}
 */
function craftgateGateway(): array
{
    $paymentAdapter = Mockery::mock(PaymentAdapter::class);
    $craftgate = Mockery::mock(Craftgate::class);
    $craftgate->shouldReceive('payment')->andReturn($paymentAdapter);

    return [new CraftgateGateway($craftgate), $paymentAdapter];
}

function craftgatePaymentDto(
    bool $threeDSecure = false,
    bool $withCard = true,
): PaymentPayDto {
    $customer = new Customer([
        'name' => 'John',
        'surname' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '+905350000000',
        'password' => 'password',
    ]);
    $customer->id = 42;

    return new PaymentPayDto(
        customer: $customer,
        amountInMinorUnits: 10_000,
        referenceNo: 'SIP-100',
        clientIp: '203.0.113.10',
        customerAddress: 'Test Address',
        card: $withCard ? new PaymentCardDto(
            pan: '5258640000000001',
            month: '07',
            year: '2044',
            cvv: '000',
            cardHolder: 'John Doe',
        ) : null,
        threeDSecure: $threeDSecure,
    );
}

/**
 * @return array<string, mixed>
 */
function craftgatePaymentRequest(string $operation): array
{
    return [
        'price' => 100.0,
        'paidPrice' => 100.0,
        'walletPrice' => 0.0,
        'installment' => 1,
        'currency' => Currency::TL,
        'paymentGroup' => PaymentGroup::PRODUCT,
        'conversationId' => 'SIP-100',
        'externalId' => 'SIP-100',
        'clientIp' => '203.0.113.10',
        'card' => [
            'cardHolderName' => 'John Doe',
            'cardNumber' => '5258640000000001',
            'expireYear' => '2044',
            'expireMonth' => '07',
            'cvc' => '000',
        ],
        'items' => [[
            'externalId' => 'SIP-100',
            'name' => 'Payment',
            'price' => 100.0,
        ]],
        'headerOptions' => [
            'idempotencyKey' => hash('sha256', "craftgate:{$operation}:SIP-100"),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>|null  $errors
 */
function craftgateJsonResponse(array $data = [], ?array $errors = null): string
{
    return json_encode(
        $errors === null ? ['data' => $data] : ['errors' => $errors],
        JSON_THROW_ON_ERROR,
    );
}

/**
 * @param  array<string, int|string|null>  $overrides
 * @return array{hash: string, status: string, completeStatus: string, paymentId: string, conversationData: string, conversationId: string, callbackStatus: string}
 */
function craftgateCallbackPayload(array $overrides = []): array
{
    return array_replace([
        'hash' => 'signed-hash',
        'status' => 'SUCCESS',
        'completeStatus' => 'WAITING',
        'paymentId' => '123',
        'conversationData' => '',
        'conversationId' => 'SIP-100',
        'callbackStatus' => '',
    ], $overrides);
}
