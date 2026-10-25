<?php

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentPayDto;
use App\Models\Customer;
use App\Services\Payment\PaytrGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'payment.gateways.paytr.merchant_id' => '123456',
        'payment.gateways.paytr.merchant_key' => 'test-key',
        'payment.gateways.paytr.merchant_salt' => 'test-salt',
        'payment.gateways.paytr.transport' => 'browser_post',
        'payment.gateways.paytr.payment_url' => 'https://www.paytr.test/odeme',
        'payment.gateways.paytr.success_url' => 'https://merchant.test/payment/success',
        'payment.gateways.paytr.fail_url' => 'https://merchant.test/payment/fail',
        'payment.gateways.paytr.user_ip' => null,
        'payment.gateways.paytr.test_mode' => true,
        'payment.gateways.paytr.debug' => true,
        'payment.gateways.paytr.allow_non_3d' => false,
        'payment.gateways.paytr.connect_timeout' => 1,
        'payment.gateways.paytr.timeout' => 2,
    ]);
});

it('prepares a signed browser form without handling card details on the server', function () {
    $gateway = app(PaytrGateway::class);

    expect($gateway->requiresServerSideCard())->toBeFalse();

    $result = $gateway->pay(paytrPaymentDto());

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => [
            'type' => 'form_post',
            'url' => 'https://www.paytr.test/odeme',
            'html' => null,
            'fields' => [
                'merchant_id' => '123456',
                'paytr_token' => 'viSrMRnNYXsBIO2J6yAepPxtD/3rgmWvS+TbATqOt3U=',
                'user_ip' => '203.0.113.10',
                'merchant_oid' => 'SIP100',
                'email' => 'john@example.com',
                'payment_type' => 'card',
                'payment_amount' => '100.00',
                'installment_count' => '0',
                'currency' => 'TL',
                'test_mode' => '1',
                'non_3d' => '0',
                'merchant_ok_url' => 'https://merchant.test/payment/success',
                'merchant_fail_url' => 'https://merchant.test/payment/fail',
                'user_name' => 'John Doe',
                'user_address' => 'Test Address',
                'user_phone' => '+905350000000',
                'user_basket' => '[["Demo Payment","100.00",1]]',
                'debug_on' => '1',
                'client_lang' => 'tr',
                'non3d_test_failed' => '0',
            ],
        ],
    ]);

    expect($result->clientAction?->fields)
        ->not->toHaveKeys(['cc_owner', 'card_number', 'expiry_month', 'expiry_year', 'cvv']);
});

it('posts card details to PayTR and returns its HTML in server transport mode', function () {
    config(['payment.gateways.paytr.transport' => 'server_html']);
    Http::preventStrayRequests();
    Http::fake([
        'https://www.paytr.test/odeme' => Http::response(
            '<html><body><form id="three-d-secure"></form></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);
    $gateway = app(PaytrGateway::class);

    expect($gateway->requiresServerSideCard())->toBeTrue();

    $result = $gateway->pay(paytrPaymentDto(withCard: true));

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => [
            'type' => 'html',
            'url' => null,
            'html' => '<html><body><form id="three-d-secure"></form></body></html>',
            'fields' => null,
        ],
    ]);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://www.paytr.test/odeme'
            && $request->isForm()
            && $data['merchant_oid'] === 'SIP100'
            && $data['paytr_token'] === 'viSrMRnNYXsBIO2J6yAepPxtD/3rgmWvS+TbATqOt3U='
            && $data['cc_owner'] === 'John Doe'
            && $data['card_number'] === '5400617004770430'
            && $data['expiry_month'] === '12'
            && $data['expiry_year'] === '30'
            && $data['cvv'] === '123';
    });
});

it('does not call PayTR without card details in server transport mode', function () {
    config(['payment.gateways.paytr.transport' => 'server_html']);
    Http::preventStrayRequests();

    $result = app(PaytrGateway::class)->pay(paytrPaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Card details are required for PayTR server-side payments.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    Http::assertNothingSent();
});

it('normalizes an unsuccessful PayTR payment-page response', function () {
    config(['payment.gateways.paytr.transport' => 'server_html']);
    Http::preventStrayRequests();
    Http::fake([
        'https://www.paytr.test/odeme' => Http::response('Service unavailable', 503),
    ]);

    $result = app(PaytrGateway::class)->pay(paytrPaymentDto(withCard: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR payment page request failed.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    Http::assertSentCount(1);
});

it('maps a successful HTTP response containing a PayTR failure payload', function () {
    config(['payment.gateways.paytr.transport' => 'server_html']);
    Http::preventStrayRequests();
    Http::fake([
        'https://www.paytr.test/odeme' => Http::response([
            'status' => 'failed',
            'reason' => 'Card was declined.',
        ]),
    ]);

    $result = app(PaytrGateway::class)->pay(paytrPaymentDto(withCard: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR payment page request failed. Reason: Card was declined.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    Http::assertSentCount(1);
});

it('normalizes a PayTR connection failure without retrying the payment', function () {
    config(['payment.gateways.paytr.transport' => 'server_html']);
    Http::preventStrayRequests();
    Http::fake([
        'https://www.paytr.test/odeme' => Http::failedConnection(),
    ]);

    $result = app(PaytrGateway::class)->pay(paytrPaymentDto(withCard: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR payment page could not be reached.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    Http::assertSentCount(1);
});

it('rejects an empty PayTR payment page', function () {
    config(['payment.gateways.paytr.transport' => 'server_html']);
    Http::preventStrayRequests();
    Http::fake([
        'https://www.paytr.test/odeme' => Http::response('', 200),
    ]);

    $result = app(PaytrGateway::class)->pay(paytrPaymentDto(withCard: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR returned an empty payment page.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    Http::assertSentCount(1);
});

it('rejects an unsupported PayTR transport', function () {
    config(['payment.gateways.paytr.transport' => 'unsupported']);

    $result = app(PaytrGateway::class)->pay(paytrPaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR transport is not configured correctly.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects payment initialization when credentials are missing', function () {
    config(['payment.gateways.paytr.merchant_key' => null]);

    $result = app(PaytrGateway::class)->pay(paytrPaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR credentials are not configured.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects a reference number that PayTR cannot use as merchant_oid', function () {
    $result = app(PaytrGateway::class)->pay(
        paytrPaymentDto(referenceNo: 'SIP-100'),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR requires an alphanumeric reference number up to 64 characters.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects non-3D payments unless they are explicitly enabled', function () {
    $result = app(PaytrGateway::class)->pay(paytrPaymentDto(threeDSecure: false));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR Non-3D payments are not enabled.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('accepts a valid successful notification and returns the exact acknowledgement', function () {
    $result = app(PaytrGateway::class)->handleCallback(PaymentCallbackDto::fromArray([
        'merchant_oid' => 'SIP100',
        'status' => 'success',
        'total_amount' => '10000',
        'hash' => 'voIFV5kuBZE9+gezzI93hS1pt0D6KqrfnQlsNe47E0M=',
    ]));

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    expect($result->response?->body)->toBe('OK');
    expect($result->response?->statusCode)->toBe(200);
    expect($result->response?->headers)->toBe([
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
});

it('acknowledges a valid failed notification after normalizing the failure', function () {
    $result = app(PaytrGateway::class)->handleCallback(PaymentCallbackDto::fromArray([
        'merchant_oid' => 'SIP100',
        'status' => 'failed',
        'total_amount' => '0',
        'hash' => 'A+xNMxVANiX17vZJdFty5Ta0i3lZdkLI0OO0IiAQNwU=',
        'failed_reason_msg' => 'Card was declined.',
    ]));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Card was declined.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    expect($result->response?->body)->toBe('OK');
});

it('does not acknowledge a notification with an invalid hash', function () {
    $result = app(PaytrGateway::class)->handleCallback(PaymentCallbackDto::fromArray([
        'merchant_oid' => 'SIP100',
        'status' => 'success',
        'total_amount' => '10000',
        'hash' => 'invalid-hash',
    ]));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'PayTR callback hash is invalid.',
        'referenceNo' => 'SIP100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    expect($result->response)->toBeNull();
});

function paytrPaymentDto(
    bool $threeDSecure = true,
    string $referenceNo = 'SIP100',
    bool $withCard = false,
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
        referenceNo: $referenceNo,
        clientIp: '203.0.113.10',
        customerAddress: 'Test Address',
        card: $withCard ? new PaymentCardDto(
            pan: '5400617004770430',
            month: '12',
            year: '2030',
            cvv: '123',
            cardHolder: 'John Doe',
        ) : null,
        threeDSecure: $threeDSecure,
    );
}
