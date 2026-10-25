<?php

namespace App\Services\Payment;

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCallbackResultDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentClientActionDto;
use App\DTOs\PaymentPayDto;
use App\DTOs\PaymentPayResultDto;
use App\Interfaces\PaymentInterface;
use App\Support\CraftgateResponse;
use Craftgate\Craftgate;
use Craftgate\Model\Currency;
use Craftgate\Model\PaymentGroup;
use Craftgate\Model\PaymentStatus;
use Exception;
use Illuminate\Support\Facades\Log;

class CraftgateGateway implements PaymentInterface
{
    private const string CompleteStatusCompleted = 'COMPLETED';

    private const string CompleteStatusWaiting = 'WAITING';

    public function __construct(
        private readonly Craftgate $craftgate,
    ) {}

    public function requiresServerSideCard(): bool
    {
        return true;
    }

    public function pay(PaymentPayDto $payment): PaymentPayResultDto
    {
        if ($payment->card === null) {
            return PaymentPayResultDto::failed(
                message: 'Card details are required for Craftgate payments.',
                referenceNo: $payment->referenceNo,
            );
        }

        if (! $this->credentialsAreConfigured()) {
            return PaymentPayResultDto::failed(
                message: 'Craftgate credentials are not configured.',
                referenceNo: $payment->referenceNo,
            );
        }

        if ($payment->threeDSecure && ! $this->threeDSecureConfigurationIsComplete()) {
            return PaymentPayResultDto::failed(
                message: 'Craftgate 3D Secure settings are not configured.',
                referenceNo: $payment->referenceNo,
            );
        }

        try {
            return $payment->threeDSecure
                ? $this->startThreeDSecure($payment, $payment->card)
                : $this->payWithoutThreeDSecure($payment, $payment->card);
        } catch (Exception $exception) {
            Log::warning('Craftgate payment request failed', [
                'referenceNo' => $payment->referenceNo,
                'exception' => $exception->getMessage(),
            ]);

            return PaymentPayResultDto::failed(
                message: 'Craftgate payment service could not be reached.',
                referenceNo: $payment->referenceNo,
            );
        }
    }

    public function handleCallback(PaymentCallbackDto $callback): PaymentCallbackResultDto
    {
        $payload = $this->threeDSecureCallbackPayload($callback);

        if ($payload === null) {
            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'Craftgate 3D Secure callback data is incomplete.',
                    referenceNo: $callback->string('conversationId'),
                ),
            );
        }

        $callbackKey = trim((string) config('payment.gateways.craftgate.callback_key'));

        if ($callbackKey === '') {
            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'Craftgate 3D Secure callback key is not configured.',
                    referenceNo: $payload['conversationId'],
                ),
            );
        }

        try {
            $paymentAdapter = $this->craftgate->payment();

            if (! $paymentAdapter->is3DSecureCallbackVerified($callbackKey, $payload)) {
                return PaymentCallbackResultDto::payment(
                    PaymentPayResultDto::failed(
                        message: 'Craftgate 3D Secure callback signature is invalid.',
                        referenceNo: $payload['conversationId'],
                    ),
                );
            }

            if ($payload['status'] !== PaymentStatus::SUCCESS) {
                return PaymentCallbackResultDto::payment(
                    PaymentPayResultDto::failed(
                        message: 'Craftgate 3D Secure authentication failed.',
                        referenceNo: $payload['conversationId'],
                    ),
                );
            }

            if ($payload['completeStatus'] === self::CompleteStatusCompleted) {
                return PaymentCallbackResultDto::payment(
                    PaymentPayResultDto::succeeded(
                        referenceNo: $payload['conversationId'],
                        transactionId: $payload['paymentId'],
                    ),
                );
            }

            if ($payload['completeStatus'] !== self::CompleteStatusWaiting) {
                return PaymentCallbackResultDto::payment(
                    PaymentPayResultDto::failed(
                        message: 'Craftgate 3D Secure callback status is invalid.',
                        referenceNo: $payload['conversationId'],
                    ),
                );
            }

            $response = $paymentAdapter->complete3DSPayment([
                'paymentId' => (int) $payload['paymentId'],
                'headerOptions' => [
                    'idempotencyKey' => $this->idempotencyKey(
                        operation: '3ds-complete',
                        identifier: $payload['paymentId'],
                    ),
                ],
            ]);

            return PaymentCallbackResultDto::payment(
                $this->mapPaymentResponse(
                    response: $response,
                    fallbackReferenceNo: $payload['conversationId'],
                ),
            );
        } catch (Exception $exception) {
            Log::warning('Craftgate 3D Secure callback could not be processed', [
                'referenceNo' => $payload['conversationId'],
                'paymentId' => $payload['paymentId'],
                'exception' => $exception->getMessage(),
            ]);

            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'Craftgate payment service could not be reached.',
                    referenceNo: $payload['conversationId'],
                ),
            );
        }
    }

    private function startThreeDSecure(
        PaymentPayDto $payment,
        PaymentCardDto $card,
    ): PaymentPayResultDto {
        $request = $this->paymentRequest($payment, $card, '3ds-init');
        $request['callbackUrl'] = (string) config('payment.gateways.craftgate.return_url');

        $response = CraftgateResponse::parse(
            $this->craftgate->payment()->init3DSPayment($request),
        );

        if (! $response['success']) {
            Log::error('Craftgate 3D Secure payment initiation failed', [
                'referenceNo' => $payment->referenceNo,
                'response' => $response,
            ]);

            return PaymentPayResultDto::failed(
                message: $this->responseErrorMessage(
                    response: $response,
                    fallback: '3D Secure payment could not be initiated.',
                ),
                referenceNo: $payment->referenceNo,
            );
        }

        $data = $response['data'];

        if (($data['paymentStatus'] ?? null) !== PaymentStatus::INIT_THREEDS) {
            return PaymentPayResultDto::failed(
                message: $this->paymentErrorMessage(
                    data: $data,
                    fallback: '3D Secure payment could not be initiated.',
                ),
                referenceNo: $payment->referenceNo,
            );
        }

        $htmlContent = $data['htmlContent'] ?? null;

        if (is_string($htmlContent) && $htmlContent !== '') {
            $html = base64_decode($htmlContent, true);

            if (is_string($html) && $html !== '') {
                Log::info('Craftgate 3D Secure payment initiated', [
                    'referenceNo' => $payment->referenceNo,
                    'paymentId' => $this->nullableString($data['paymentId'] ?? null),
                ]);

                return PaymentPayResultDto::requiresClientAction(
                    referenceNo: $payment->referenceNo,
                    action: PaymentClientActionDto::html($html),
                );
            }
        }

        $redirectUrl = $data['redirectUrl'] ?? null;

        if (is_string($redirectUrl) && $redirectUrl !== '') {
            return PaymentPayResultDto::requiresClientAction(
                referenceNo: $payment->referenceNo,
                action: PaymentClientActionDto::redirect($redirectUrl),
            );
        }

        return PaymentPayResultDto::failed(
            message: 'Craftgate returned no usable 3D Secure client action.',
            referenceNo: $payment->referenceNo,
        );
    }

    private function payWithoutThreeDSecure(
        PaymentPayDto $payment,
        PaymentCardDto $card,
    ): PaymentPayResultDto {
        $response = $this->craftgate->payment()->createPayment(
            $this->paymentRequest($payment, $card, 'payment'),
        );

        return $this->mapPaymentResponse($response, $payment->referenceNo);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRequest(
        PaymentPayDto $payment,
        PaymentCardDto $card,
        string $operation,
    ): array {
        $amount = $payment->amountInMinorUnits / 100;

        return [
            'price' => $amount,
            'paidPrice' => $amount,
            'walletPrice' => 0.0,
            'installment' => 1,
            'currency' => Currency::TL,
            'paymentGroup' => PaymentGroup::PRODUCT,
            'conversationId' => $payment->referenceNo,
            'externalId' => $payment->referenceNo,
            'clientIp' => $payment->clientIp,
            'card' => [
                'cardHolderName' => $card->cardHolder,
                'cardNumber' => $card->pan,
                'expireYear' => $card->year,
                'expireMonth' => $card->month,
                'cvc' => $card->cvv,
            ],
            'items' => [[
                'externalId' => $payment->referenceNo,
                'name' => 'Payment',
                'price' => $amount,
            ]],
            'headerOptions' => [
                'idempotencyKey' => $this->idempotencyKey($operation, $payment->referenceNo),
            ],
        ];
    }

    private function mapPaymentResponse(
        string $response,
        ?string $fallbackReferenceNo,
    ): PaymentPayResultDto {
        $parsedResponse = CraftgateResponse::parse($response);

        if (! $parsedResponse['success']) {
            return PaymentPayResultDto::failed(
                message: $this->responseErrorMessage(
                    response: $parsedResponse,
                    fallback: 'Payment could not be completed.',
                ),
                referenceNo: $fallbackReferenceNo,
            );
        }

        $data = $parsedResponse['data'];
        $referenceNo = $this->nullableString($data['conversationId'] ?? null)
            ?? $fallbackReferenceNo;

        if (($data['paymentStatus'] ?? null) === PaymentStatus::SUCCESS) {
            $transactionId = $this->nullableString($data['id'] ?? null);

            Log::info('Craftgate payment successful', [
                'referenceNo' => $referenceNo,
                'transactionId' => $transactionId,
            ]);

            return PaymentPayResultDto::succeeded(
                referenceNo: $referenceNo,
                transactionId: $transactionId,
            );
        }

        return PaymentPayResultDto::failed(
            message: $this->paymentErrorMessage(
                data: $data,
                fallback: 'Payment could not be completed.',
            ),
            referenceNo: $referenceNo,
        );
    }

    /**
     * @return array{hash: string, status: string, completeStatus: string, paymentId: string, conversationData: string, conversationId: string, callbackStatus: string}|null
     */
    private function threeDSecureCallbackPayload(PaymentCallbackDto $callback): ?array
    {
        $hash = $callback->string('hash');
        $status = $callback->string('status');
        $completeStatus = $callback->string('completeStatus');
        $conversationId = $callback->string('conversationId');
        $paymentId = filter_var(
            $callback->payload['paymentId'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($hash === null || $status === null || $completeStatus === null || $conversationId === null || $paymentId === false) {
            return null;
        }

        $conversationData = $this->optionalCallbackString($callback->payload['conversationData'] ?? null);
        $callbackStatus = $this->optionalCallbackString($callback->payload['callbackStatus'] ?? null);

        if ($conversationData === null || $callbackStatus === null) {
            return null;
        }

        return [
            'hash' => $hash,
            'status' => $status,
            'completeStatus' => $completeStatus,
            'paymentId' => (string) $paymentId,
            'conversationData' => $conversationData,
            'conversationId' => $conversationId,
            'callbackStatus' => $callbackStatus,
        ];
    }

    private function optionalCallbackString(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    /**
     * @param  array{success: bool, data: array<string, mixed>|null, error: array{code: string|null, group: string|null, message: string|null, provider: array<string, mixed>|null}|null}  $response
     */
    private function responseErrorMessage(array $response, string $fallback): string
    {
        $message = $response['error']['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function paymentErrorMessage(?array $data, string $fallback): string
    {
        $paymentError = $data['paymentError'] ?? null;

        if (! is_array($paymentError)) {
            return $fallback;
        }

        $message = $paymentError['errorDescription'] ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        $providerError = $paymentError['providerError'] ?? null;
        $providerMessage = is_array($providerError)
            ? ($providerError['errorMessage'] ?? null)
            : null;

        return is_string($providerMessage) && $providerMessage !== ''
            ? $providerMessage
            : $fallback;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $string = (string) $value;

        return $string !== '' ? $string : null;
    }

    private function credentialsAreConfigured(): bool
    {
        return trim((string) config('payment.gateways.craftgate.api_key')) !== ''
            && trim((string) config('payment.gateways.craftgate.secret_key')) !== ''
            && trim((string) config('payment.gateways.craftgate.base_url')) !== '';
    }

    private function threeDSecureConfigurationIsComplete(): bool
    {
        return trim((string) config('payment.gateways.craftgate.return_url')) !== ''
            && trim((string) config('payment.gateways.craftgate.callback_key')) !== '';
    }

    private function idempotencyKey(string $operation, string $identifier): string
    {
        return hash('sha256', "craftgate:{$operation}:{$identifier}");
    }
}
