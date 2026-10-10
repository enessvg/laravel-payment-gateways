<?php

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCallbackResponseDto;
use App\DTOs\PaymentCallbackResultDto;
use App\DTOs\PaymentClientActionDto;
use App\DTOs\PaymentPayDto;
use App\DTOs\PaymentPayResultDto;
use App\Interfaces\PaymentInterface;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

it('returns a normalized response when the payment succeeds', function () {
    $customer = createPaymentCustomer();

    $this->mock(PaymentInterface::class, function (MockInterface $mock) use ($customer): void {
        $mock->shouldReceive('requiresServerSideCard')->andReturnTrue();
        $mock->shouldReceive('pay')
            ->once()
            ->withArgs(fn (PaymentPayDto $payment): bool => $payment->customer->is($customer)
                && $payment->amountInMinorUnits === 10_000
                && preg_match('/^SIP[0-9A-HJKMNP-TV-Z]{26}$/', $payment->referenceNo) === 1
                && $payment->clientIp === '127.0.0.1'
                && $payment->customerAddress === 'Demo Address'
                && $payment->threeDSecure === false
                && $payment->card?->pan === '5400617004770430')
            ->andReturn(PaymentPayResultDto::succeeded('SIP-TEST', 'PAY-123'));
    });

    $response = $this->postJson(route('payment.pay'), validPaymentPayload());

    $response
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => null,
            'referenceNo' => 'SIP-TEST',
            'transactionId' => 'PAY-123',
            'clientAction' => null,
        ]);
});

it('returns the client action required by the gateway', function () {
    createPaymentCustomer();

    $this->mock(PaymentInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('requiresServerSideCard')->andReturnTrue();
        $mock->shouldReceive('pay')
            ->once()
            ->withArgs(fn (PaymentPayDto $payment): bool => $payment->threeDSecure)
            ->andReturn(PaymentPayResultDto::requiresClientAction(
                referenceNo: 'SIP-TEST',
                action: PaymentClientActionDto::redirect('https://payments.test/3d-secure'),
            ));
    });

    $payload = validPaymentPayload();
    $payload['3d_secure'] = true;

    $response = $this->postJson(route('payment.pay'), $payload);

    $response
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => null,
            'referenceNo' => 'SIP-TEST',
            'transactionId' => null,
            'clientAction' => [
                'type' => 'redirect',
                'url' => 'https://payments.test/3d-secure',
                'html' => null,
                'fields' => null,
            ],
        ]);
});

it('returns 422 when card details are missing for a server-side card gateway', function () {
    $response = $this->postJson(route('payment.pay'));

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'card',
            'card.pan',
            'card.month',
            'card.year',
            'card.cvv',
            'card.cardHolder',
        ]);
});

it('returns 422 when a card field is invalid', function (string $field, mixed $value, string $errorKey) {
    $payload = validPaymentPayload();
    data_set($payload, $field, $value);

    $response = $this->postJson(route('payment.pay'), $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'card number' => ['card.pan', 'not-a-card-number', 'card.pan'],
    'expiry month' => ['card.month', '13', 'card.month'],
    'expiry year' => ['card.year', '30', 'card.year'],
    'security code' => ['card.cvv', '12', 'card.cvv'],
    'card holder' => ['card.cardHolder', str_repeat('x', 101), 'card.cardHolder'],
    'unexpected card property' => ['card.nickname', 'Personal card', 'card'],
]);

it('returns 422 with the gateway failure', function () {
    createPaymentCustomer();

    $this->mock(PaymentInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('requiresServerSideCard')->andReturnTrue();
        $mock->shouldReceive('pay')
            ->once()
            ->andReturn(PaymentPayResultDto::failed('Card was declined.', 'SIP-TEST'));
    });

    $response = $this->postJson(route('payment.pay'), validPaymentPayload());

    $response
        ->assertUnprocessable()
        ->assertExactJson([
            'success' => false,
            'message' => 'Card was declined.',
            'referenceNo' => 'SIP-TEST',
            'transactionId' => null,
            'clientAction' => null,
        ]);
});

it('allows a browser-post gateway to initialize without receiving card details', function () {
    createPaymentCustomer();

    $this->mock(PaymentInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('requiresServerSideCard')->andReturnFalse();
        $mock->shouldReceive('pay')
            ->once()
            ->withArgs(fn (PaymentPayDto $payment): bool => $payment->card === null
                && $payment->threeDSecure)
            ->andReturn(PaymentPayResultDto::requiresClientAction(
                referenceNo: 'SIP-TEST',
                action: PaymentClientActionDto::formPost(
                    url: 'https://payments.test/pay',
                    fields: ['merchant_oid' => 'SIP-TEST'],
                ),
            ));
    });

    $response = $this->postJson(route('payment.pay'), ['3d_secure' => true]);

    $response
        ->assertOk()
        ->assertJsonPath('clientAction.type', 'form_post')
        ->assertJsonPath('clientAction.fields.merchant_oid', 'SIP-TEST');
});

it('prohibits sending card details to a browser-post gateway', function () {
    $this->mock(PaymentInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('requiresServerSideCard')->andReturnFalse();
        $mock->shouldReceive('pay')->never();
    });

    $response = $this->postJson(route('payment.pay'), validPaymentPayload());

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('card');
});

it('passes provider callback data to the configured gateway', function () {
    $this->mock(PaymentInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handleCallback')
            ->once()
            ->withArgs(fn (PaymentCallbackDto $callback): bool => $callback->payload === [
                'session_id' => 'SESSION-123',
                'token_id' => 'TOKEN-123',
                'provider_field' => 'provider-value',
            ])
            ->andReturn(PaymentCallbackResultDto::payment(
                PaymentPayResultDto::succeeded('SIP-TEST', 'PAY-123'),
            ));
    });

    $response = $this->postJson(route('payment.callback'), [
        'session_id' => 'SESSION-123',
        'token_id' => 'TOKEN-123',
        'provider_field' => 'provider-value',
    ]);

    $response
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => null,
            'referenceNo' => 'SIP-TEST',
            'transactionId' => 'PAY-123',
            'clientAction' => null,
        ]);
});

it('returns the provider-specific callback acknowledgement', function () {
    $this->mock(PaymentInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handleCallback')
            ->once()
            ->andReturn(PaymentCallbackResultDto::respond(
                payment: PaymentPayResultDto::succeeded('SIP-TEST'),
                response: PaymentCallbackResponseDto::plainText('OK'),
            ));
    });

    $response = $this->post(route('payment.callback'), ['status' => 'success']);

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent('OK');
});

it('does not treat the browser return page as an authoritative payment result', function () {
    $this->getJson(route('payment.return.success'))
        ->assertOk()
        ->assertExactJson([
            'status' => 'pending',
            'message' => 'Payment result is being verified.',
        ]);

    $this->postJson(route('payment.return.success'))
        ->assertOk()
        ->assertJsonPath('status', 'pending');
});

it('shows the PayTR browser failure message', function () {
    $this->postJson(route('payment.return.fail'), ['fail_message' => '3D verification failed.'])
        ->assertOk()
        ->assertExactJson([
            'status' => 'failed',
            'message' => '3D verification failed.',
        ]);

    $this->getJson(route('payment.return.fail'))
        ->assertOk()
        ->assertJsonPath('status', 'failed');
});

/**
 * @return array{3d_secure: bool, card: array{pan: string, month: string, year: string, cvv: string, cardHolder: string}}
 */
function validPaymentPayload(): array
{
    return [
        '3d_secure' => false,
        'card' => [
            'pan' => '5400617004770430',
            'month' => '12',
            'year' => '2030',
            'cvv' => '123',
            'cardHolder' => 'John Doe',
        ],
    ];
}

function createPaymentCustomer(): Customer
{
    return Customer::query()->create([
        'name' => 'John',
        'surname' => 'Doe',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '5551234567',
        'password' => 'password',
    ]);
}
