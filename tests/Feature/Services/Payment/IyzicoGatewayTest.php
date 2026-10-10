<?php

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentPayDto;
use App\Models\Customer;
use App\Services\Payment\IyzicoGateway;
use Iyzipay\ApiResource;
use Iyzipay\HttpClient;

beforeEach(function () {
    config([
        'payment.gateways.iyzico.api_key' => 'test-api-key',
        'payment.gateways.iyzico.secret_key' => 'test-secret-key',
        'payment.gateways.iyzico.base_url' => 'https://sandbox-api.iyzico.test',
        'payment.gateways.iyzico.return_url' => 'https://merchant.test/api/tds/callback',
    ]);
});

afterEach(function () {
    ApiResource::setHttpClient(null);
});

it('maps a successful Iyzico payment and sends the shared payment request', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoPaymentResponse([
            'status' => 'success',
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->pay(iyzicoPaymentDto());

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => 'IYZICO-123',
        'clientAction' => null,
    ]);
    expect($httpClient->requests)->toHaveCount(1);
    expect($httpClient->requests[0]['url'])->toBe('https://sandbox-api.iyzico.test/payment/auth');

    $requestBody = iyzicoRequestBody($httpClient);

    expect($requestBody['conversationId'])->toBe('SIP-100');
    expect($requestBody['price'])->toBe('100.0');
    expect($requestBody['paymentCard']['cardNumber'])->toBe('5400617004770430');
    expect($requestBody['buyer']['id'])->toBe('42');
    expect($requestBody)->not->toHaveKey('callbackUrl');
});

it('maps a declined Iyzico payment to the application result', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoResponse([
            'status' => 'failure',
            'conversationId' => 'SIP-100',
            'errorMessage' => 'Card was declined.',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->pay(iyzicoPaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Card was declined.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects a successful Iyzico payment with an invalid response signature', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoPaymentResponse([
            'status' => 'success',
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
            'signature' => 'invalid-signature',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->pay(iyzicoPaymentDto());

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Iyzico payment response signature is invalid.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('starts 3D Secure authentication and returns an HTML action', function () {
    $html = '<form action="https://bank.test/3d" method="post"></form>';
    $httpClient = new IyzicoHttpClientFake([
        iyzicoThreeDSecureInitializationResponse([
            'status' => 'success',
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
            'threeDSHtmlContent' => base64_encode($html),
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->pay(iyzicoPaymentDto(threeDSecure: true));

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
    expect($httpClient->requests)->toHaveCount(1);
    expect($httpClient->requests[0]['url'])->toBe('https://sandbox-api.iyzico.test/payment/3dsecure/initialize');

    $requestBody = iyzicoRequestBody($httpClient);

    expect($requestBody['callbackUrl'])->toBe('https://merchant.test/api/tds/callback');
    expect($requestBody['conversationId'])->toBe('SIP-100');
    expect($requestBody['paymentCard']['cardNumber'])->toBe('5400617004770430');
});

it('rejects a successful 3D Secure initialization with an invalid response signature', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoThreeDSecureInitializationResponse([
            'status' => 'success',
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
            'threeDSHtmlContent' => base64_encode('<form></form>'),
            'signature' => 'invalid-signature',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->pay(iyzicoPaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Iyzico 3D Secure initialization signature is invalid.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('returns a failure when Iyzico omits the 3D Secure HTML', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoThreeDSecureInitializationResponse([
            'status' => 'success',
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->pay(iyzicoPaymentDto(threeDSecure: true));

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => '3D Secure payment could not be initiated.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('completes a successful 3D Secure payment from a signed callback', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoPaymentResponse([
            'status' => 'success',
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray(iyzicoCallbackPayload()),
    );

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => 'SIP-100',
        'transactionId' => 'IYZICO-123',
        'clientAction' => null,
    ]);
    expect($httpClient->requests)->toHaveCount(1);
    expect($httpClient->requests[0]['url'])->toBe('https://sandbox-api.iyzico.test/payment/3dsecure/auth');

    $requestBody = iyzicoRequestBody($httpClient);

    expect($requestBody)->toBe([
        'locale' => 'tr',
        'conversationId' => 'SIP-100',
        'paymentId' => 'IYZICO-123',
        'conversationData' => '',
    ]);
});

it('maps a declined 3D Secure payment to the application result', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoResponse([
            'status' => 'failure',
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
            'errorMessage' => '3D Secure payment was declined.',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray(iyzicoCallbackPayload()),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => '3D Secure payment was declined.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
});

it('rejects an invalid Iyzico callback signature before completing the payment', function () {
    $httpClient = new IyzicoHttpClientFake([]);
    ApiResource::setHttpClient($httpClient);
    $payload = iyzicoCallbackPayload();
    $payload['signature'] = 'invalid-signature';

    $result = app(IyzicoGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray($payload),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Iyzico 3D Secure callback signature is invalid.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    expect($httpClient->requests)->toBe([]);
});

it('rejects a failed Iyzico authentication before completing the payment', function () {
    $httpClient = new IyzicoHttpClientFake([]);
    ApiResource::setHttpClient($httpClient);
    $payload = iyzicoCallbackPayload(['status' => 'failure']);

    $result = app(IyzicoGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray($payload),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Iyzico 3D Secure authentication failed.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    expect($httpClient->requests)->toBe([]);
});

it('rejects incomplete Iyzico callback data before completing the payment', function () {
    $httpClient = new IyzicoHttpClientFake([]);
    ApiResource::setHttpClient($httpClient);

    $result = app(IyzicoGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray([
            'conversationId' => 'SIP-100',
            'paymentId' => 'IYZICO-123',
        ]),
    );

    expect($result->toArray())->toBe([
        'success' => false,
        'message' => 'Iyzico 3D Secure callback data is incomplete.',
        'referenceNo' => 'SIP-100',
        'transactionId' => null,
        'clientAction' => null,
    ]);
    expect($httpClient->requests)->toBe([]);
});

it('normalizes a numeric Iyzico conversation ID before completing the payment', function () {
    $httpClient = new IyzicoHttpClientFake([
        iyzicoPaymentResponse([
            'status' => 'success',
            'conversationId' => '123456789',
            'paymentId' => 'IYZICO-123',
        ]),
    ]);
    ApiResource::setHttpClient($httpClient);
    $payload = iyzicoCallbackPayload(['conversationId' => 123456789]);

    $result = app(IyzicoGateway::class)->handleCallback(
        PaymentCallbackDto::fromArray($payload),
    );

    expect($result->toArray())->toBe([
        'success' => true,
        'message' => null,
        'referenceNo' => '123456789',
        'transactionId' => 'IYZICO-123',
        'clientAction' => null,
    ]);

    $requestBody = iyzicoRequestBody($httpClient);

    expect($requestBody['conversationId'])->toBe('123456789');
    expect($requestBody['conversationData'])->toBe('');
});

function iyzicoPaymentDto(bool $threeDSecure = false): PaymentPayDto
{
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

/**
 * @param  array<string, int|string|null>  $overrides
 * @return array{status: string, paymentId: string, conversationData: string|null, conversationId: int|string, mdStatus: string, signature: string}
 */
function iyzicoCallbackPayload(array $overrides = []): array
{
    $payload = array_replace([
        'status' => 'success',
        'paymentId' => 'IYZICO-123',
        'conversationData' => null,
        'conversationId' => 'SIP-100',
        'mdStatus' => '1',
    ], $overrides);

    $payload['signature'] = hash_hmac('sha256', implode(':', [
        $payload['conversationData'],
        $payload['conversationId'],
        $payload['mdStatus'],
        $payload['paymentId'],
        $payload['status'],
    ]), 'test-secret-key');

    return $payload;
}

/**
 * @param  array<string, mixed>  $data
 */
function iyzicoResponse(array $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $data
 */
function iyzicoPaymentResponse(array $data): string
{
    $response = array_replace([
        'currency' => 'TRY',
        'basketId' => 'B67832',
        'paidPrice' => '100.0',
        'price' => '100.0',
    ], $data);

    $response['signature'] = $data['signature'] ?? hash_hmac('sha256', implode(':', [
        $response['paymentId'],
        $response['currency'],
        $response['basketId'],
        $response['conversationId'],
        $response['paidPrice'],
        $response['price'],
    ]), 'test-secret-key');

    return iyzicoResponse($response);
}

/**
 * @param  array<string, mixed>  $data
 */
function iyzicoThreeDSecureInitializationResponse(array $data): string
{
    $response = $data;
    $response['signature'] = $data['signature'] ?? hash_hmac('sha256', implode(':', [
        $response['paymentId'],
        $response['conversationId'],
    ]), 'test-secret-key');

    return iyzicoResponse($response);
}

/**
 * @return array<string, mixed>
 */
function iyzicoRequestBody(IyzicoHttpClientFake $httpClient): array
{
    return json_decode(
        $httpClient->requests[0]['content'],
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

final class IyzicoHttpClientFake implements HttpClient
{
    /**
     * @var list<array{url: string, headers: array<mixed>, content: string}>
     */
    public array $requests = [];

    /**
     * @param  list<string>  $responses
     */
    public function __construct(
        private array $responses,
    ) {}

    public function get($url): never
    {
        throw new LogicException('Unexpected GET request.');
    }

    public function getV2($url, $header): never
    {
        throw new LogicException('Unexpected GET request.');
    }

    public function post($url, $header, $content): string
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $header,
            'content' => $content,
        ];

        return array_shift($this->responses)
            ?? throw new LogicException('No fake Iyzico response was configured.');
    }

    public function put($url, $header, $content): never
    {
        throw new LogicException('Unexpected PUT request.');
    }

    public function delete($url, $header, $content = null): never
    {
        throw new LogicException('Unexpected DELETE request.');
    }
}
