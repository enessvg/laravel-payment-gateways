<?php

namespace App\Services\Payment;

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCallbackResponseDto;
use App\DTOs\PaymentCallbackResultDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentClientActionDto;
use App\DTOs\PaymentPayDto;
use App\DTOs\PaymentPayResultDto;
use App\Interfaces\PaymentInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaytrGateway implements PaymentInterface
{
    private const string BrowserPostTransport = 'browser_post';

    private const string ServerHtmlTransport = 'server_html';

    public function requiresServerSideCard(): bool
    {
        return $this->transport() === self::ServerHtmlTransport;
    }

    public function pay(PaymentPayDto $payment): PaymentPayResultDto
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return PaymentPayResultDto::failed(
                message: 'PayTR credentials are not configured.',
                referenceNo: $payment->referenceNo,
            );
        }

        $transport = $this->transport();

        if (! in_array($transport, [self::BrowserPostTransport, self::ServerHtmlTransport], true)) {
            return PaymentPayResultDto::failed(
                message: 'PayTR transport is not configured correctly.',
                referenceNo: $payment->referenceNo,
            );
        }

        if ($transport === self::ServerHtmlTransport && $payment->card === null) {
            return PaymentPayResultDto::failed(
                message: 'Card details are required for PayTR server-side payments.',
                referenceNo: $payment->referenceNo,
            );
        }

        if (preg_match('/^[A-Za-z0-9]{1,64}$/D', $payment->referenceNo) !== 1) {
            return PaymentPayResultDto::failed(
                message: 'PayTR requires an alphanumeric reference number up to 64 characters.',
                referenceNo: $payment->referenceNo,
            );
        }

        if (! $payment->threeDSecure && ! config('payment.gateways.paytr.allow_non_3d', false)) {
            return PaymentPayResultDto::failed(
                message: 'PayTR Non-3D payments are not enabled.',
                referenceNo: $payment->referenceNo,
            );
        }

        $clientIp = trim((string) config('payment.gateways.paytr.user_ip', '')) ?: $payment->clientIp;

        if ($clientIp === '') {
            return PaymentPayResultDto::failed(
                message: 'PayTR requires the customer IP address.',
                referenceNo: $payment->referenceNo,
            );
        }

        $fields = $this->paymentFields($payment, $clientIp, $credentials);

        Log::info('PayTR payment form prepared', [
            'referenceNo' => $payment->referenceNo,
            'transport' => $transport,
        ]);

        if ($transport === self::ServerHtmlTransport) {
            return $this->payThroughServer($payment, $fields);
        }

        return PaymentPayResultDto::requiresClientAction(
            referenceNo: $payment->referenceNo,
            action: PaymentClientActionDto::formPost(
                url: (string) config('payment.gateways.paytr.payment_url'),
                fields: $fields,
            ),
        );
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function payThroughServer(
        PaymentPayDto $payment,
        array $fields,
    ): PaymentPayResultDto {
        if ($payment->card === null) {
            return PaymentPayResultDto::failed(
                message: 'Card details are required for PayTR server-side payments.',
                referenceNo: $payment->referenceNo,
            );
        }

        try {
            $response = Http::asForm()
                ->connectTimeout((int) config('payment.gateways.paytr.connect_timeout', 5))
                ->timeout((int) config('payment.gateways.paytr.timeout', 30))
                ->post(
                    (string) config('payment.gateways.paytr.payment_url'),
                    [...$fields, ...$this->cardFields($payment->card)],
                );
        } catch (ConnectionException $exception) {
            Log::warning('PayTR payment page could not be reached', [
                'referenceNo' => $payment->referenceNo,
                'exception' => $exception->getMessage(),
            ]);

            return PaymentPayResultDto::failed(
                message: 'PayTR payment page could not be reached.',
                referenceNo: $payment->referenceNo,
            );
        }

        if (! $response->successful()) {
            Log::warning('PayTR payment page request failed', [
                'referenceNo' => $payment->referenceNo,
                'status' => $response->status(),
            ]);

            return PaymentPayResultDto::failed(
                message: 'PayTR payment page request failed.',
                referenceNo: $payment->referenceNo,
            );
        }

        $html = $response->body();

        if (trim($html) === '') {
            return PaymentPayResultDto::failed(
                message: 'PayTR returned an empty payment page.',
                referenceNo: $payment->referenceNo,
            );
        }

        $responsePayload = $response->json();
        $responseStatus = is_array($responsePayload)
            ? ($responsePayload['status'] ?? null)
            : null;

        if (is_string($responseStatus) && $responseStatus !== 'success') {
            $reason = $responsePayload['reason'] ?? null;

            Log::warning('PayTR payment page request failed', [
                'referenceNo' => $payment->referenceNo,
                'status' => $responseStatus,
                'reason' => $reason,
            ]);

            return PaymentPayResultDto::failed(
                message: is_string($reason) && $reason !== ''
                    ? "PayTR payment page request failed. Reason: {$reason}"
                    : 'PayTR payment page request failed.',
                referenceNo: $payment->referenceNo,
            );
        }

        return PaymentPayResultDto::requiresClientAction(
            referenceNo: $payment->referenceNo,
            action: PaymentClientActionDto::html($html),
        );
    }

    /**
     * @return array{cc_owner: string, card_number: string, expiry_month: string, expiry_year: string, cvv: string}
     */
    private function cardFields(PaymentCardDto $card): array
    {
        return [
            'cc_owner' => $card->cardHolder,
            'card_number' => $card->pan,
            'expiry_month' => $card->month,
            'expiry_year' => substr($card->year, -2),
            'cvv' => $card->cvv,
        ];
    }

    public function handleCallback(PaymentCallbackDto $callback): PaymentCallbackResultDto
    {
        $payload = $this->callbackPayload($callback);

        if ($payload === null) {
            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'PayTR callback data is incomplete.',
                    referenceNo: $callback->string('merchant_oid'),
                ),
            );
        }

        if (! $this->hasValidCallbackHash($payload)) {
            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'PayTR callback hash is invalid.',
                    referenceNo: $payload['merchant_oid'],
                ),
            );
        }

        $paymentResult = $payload['status'] === 'success'
            ? PaymentPayResultDto::succeeded($payload['merchant_oid'])
            : PaymentPayResultDto::failed(
                message: $payload['failed_reason_msg'] ?: 'PayTR payment failed.',
                referenceNo: $payload['merchant_oid'],
            );

        Log::info('PayTR payment notification received', [
            'referenceNo' => $payload['merchant_oid'],
            'status' => $payload['status'],
            'totalAmount' => $payload['total_amount'],
        ]);

        return PaymentCallbackResultDto::respond(
            payment: $paymentResult,
            response: PaymentCallbackResponseDto::plainText('OK'),
        );
    }

    /**
     * @param  array{merchant_id: string, merchant_key: string, merchant_salt: string}  $credentials
     * @return array<string, string>
     */
    private function paymentFields(
        PaymentPayDto $payment,
        string $clientIp,
        array $credentials,
    ): array {
        $amount = number_format($payment->amountInMinorUnits / 100, 2, '.', '');
        $installmentCount = '0';
        $currency = 'TL';
        $testMode = config('payment.gateways.paytr.test_mode', true) ? '1' : '0';
        $nonThreeDSecure = $payment->threeDSecure ? '0' : '1';

        $hashData = implode('', [
            $credentials['merchant_id'],
            $clientIp,
            $payment->referenceNo,
            (string) $payment->customer->email,
            $amount,
            'card',
            $installmentCount,
            $currency,
            $testMode,
            $nonThreeDSecure,
        ]);

        $token = base64_encode(hash_hmac(
            'sha256',
            $hashData.$credentials['merchant_salt'],
            $credentials['merchant_key'],
            true,
        ));

        return [
            'merchant_id' => $credentials['merchant_id'],
            'paytr_token' => $token,
            'user_ip' => $clientIp,
            'merchant_oid' => $payment->referenceNo,
            'email' => (string) $payment->customer->email,
            'payment_type' => 'card',
            'payment_amount' => $amount,
            'installment_count' => $installmentCount,
            'currency' => $currency,
            'test_mode' => $testMode,
            'non_3d' => $nonThreeDSecure,
            'merchant_ok_url' => (string) config('payment.gateways.paytr.success_url'),
            'merchant_fail_url' => (string) config('payment.gateways.paytr.fail_url'),
            'user_name' => trim($payment->customer->name.' '.$payment->customer->surname),
            'user_address' => $payment->customerAddress,
            'user_phone' => (string) $payment->customer->phone,
            'user_basket' => json_encode(
                [['Demo Payment', $amount, 1]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'debug_on' => config('payment.gateways.paytr.debug', false) ? '1' : '0',
            'client_lang' => 'tr',
            'non3d_test_failed' => '0',
        ];
    }

    /**
     * @return array{merchant_id: string, merchant_key: string, merchant_salt: string}|null
     */
    private function credentials(): ?array
    {
        $credentials = [
            'merchant_id' => trim((string) config('payment.gateways.paytr.merchant_id')),
            'merchant_key' => trim((string) config('payment.gateways.paytr.merchant_key')),
            'merchant_salt' => trim((string) config('payment.gateways.paytr.merchant_salt')),
        ];

        if (in_array('', $credentials, true)) {
            return null;
        }

        return $credentials;
    }

    private function transport(): string
    {
        return (string) config('payment.gateways.paytr.transport', self::BrowserPostTransport);
    }

    /**
     * @return array{merchant_oid: string, status: string, total_amount: string, hash: string, failed_reason_msg: string}|null
     */
    private function callbackPayload(PaymentCallbackDto $callback): ?array
    {
        $payload = [];

        foreach (['merchant_oid', 'status', 'total_amount', 'hash'] as $field) {
            $value = $callback->payload[$field] ?? null;

            if (! is_scalar($value) || (string) $value === '') {
                return null;
            }

            $payload[$field] = (string) $value;
        }

        if (! in_array($payload['status'], ['success', 'failed'], true)) {
            return null;
        }

        $failedReason = $callback->payload['failed_reason_msg'] ?? '';
        $payload['failed_reason_msg'] = is_scalar($failedReason) ? (string) $failedReason : '';

        return $payload;
    }

    /**
     * @param  array{merchant_oid: string, status: string, total_amount: string, hash: string, failed_reason_msg: string}  $payload
     */
    private function hasValidCallbackHash(array $payload): bool
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return false;
        }

        $expectedHash = base64_encode(hash_hmac(
            'sha256',
            $payload['merchant_oid']
                .$credentials['merchant_salt']
                .$payload['status']
                .$payload['total_amount'],
            $credentials['merchant_key'],
            true,
        ));

        return hash_equals($expectedHash, $payload['hash']);
    }
}
