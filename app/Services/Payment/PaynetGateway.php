<?php

namespace App\Services\Payment;

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCallbackResultDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentClientActionDto;
use App\DTOs\PaymentPayDto;
use App\DTOs\PaymentPayResultDto;
use App\Interfaces\PaymentInterface;
use Illuminate\Support\Facades\Log;
use Paynet\DTOs\PaymentParams;
use Paynet\DTOs\PaymentResponse;
use Paynet\DTOs\TdsChargeParams;
use Paynet\DTOs\TdsInitialParams;
use Paynet\Facades\Paynet;
use Paynet\Support\PaynetTools;

class PaynetGateway implements PaymentInterface
{
    public function requiresServerSideCard(): bool
    {
        return true;
    }

    public function pay(PaymentPayDto $payment): PaymentPayResultDto
    {
        if ($payment->card === null) {
            return PaymentPayResultDto::failed(
                message: 'Card details are required for Paynet payments.',
                referenceNo: $payment->referenceNo,
            );
        }

        return $payment->threeDSecure
            ? $this->startThreeDSecure($payment, $payment->card)
            : $this->payWithoutThreeDSecure($payment, $payment->card);
    }

    public function handleCallback(PaymentCallbackDto $callback): PaymentCallbackResultDto
    {
        $sessionId = $callback->string('session_id');
        $tokenId = $callback->string('token_id');

        if ($sessionId === null || $tokenId === null) {
            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'Paynet 3D Secure callback data is incomplete.',
                    referenceNo: $callback->string('reference_no'),
                ),
            );
        }

        $result = Paynet::tdsCharge(new TdsChargeParams(
            sessionId: $sessionId,
            tokenId: $tokenId,
        ));

        return PaymentCallbackResultDto::payment(
            $this->mapPaymentResponse(
                result: $result,
                fallbackReferenceNo: $callback->string('reference_no'),
            ),
        );
    }

    private function payWithoutThreeDSecure(
        PaymentPayDto $payment,
        PaymentCardDto $card,
    ): PaymentPayResultDto {
        $result = Paynet::payment(new PaymentParams(
            amount: $this->formattedAmount($payment),
            referenceNo: $payment->referenceNo,
            domain: (string) config('payment.gateways.paynet.domain'),
            pan: $card->pan,
            month: $card->month,
            year: $card->year,
            cvc: $card->cvv,
            cardHolder: $card->cardHolder,
            userId: (string) $payment->customer->getKey(),
        ));

        return $this->mapPaymentResponse($result, $payment->referenceNo);
    }

    private function startThreeDSecure(
        PaymentPayDto $payment,
        PaymentCardDto $card,
    ): PaymentPayResultDto {
        $result = Paynet::tdsInitial(new TdsInitialParams(
            amount: $this->formattedAmount($payment),
            referenceNo: $payment->referenceNo,
            domain: (string) config('payment.gateways.paynet.domain'),
            returnUrl: (string) config('payment.gateways.paynet.return_url'),
            pan: $card->pan,
            month: $card->month,
            year: $card->year,
            cvc: $card->cvv,
            cardHolder: $card->cardHolder,
            userId: (string) $payment->customer->getKey(),
        ));

        if ($result->successful() && $result->postUrl !== null && $result->postUrl !== '') {
            Log::info('3D Secure payment initiated', [
                'referenceNo' => $payment->referenceNo,
            ]);

            return PaymentPayResultDto::requiresClientAction(
                referenceNo: $payment->referenceNo,
                action: PaymentClientActionDto::redirect($result->postUrl),
            );
        }

        return PaymentPayResultDto::failed(
            message: $result->errorMessage() ?? '3D Secure payment could not be initiated.',
            referenceNo: $payment->referenceNo,
        );
    }

    private function mapPaymentResponse(
        PaymentResponse $result,
        ?string $fallbackReferenceNo,
    ): PaymentPayResultDto {
        $referenceNo = $result->referenceNo ?? $fallbackReferenceNo;

        if ($result->successful()) {
            Log::info('Payment successful', [
                'referenceNo' => $referenceNo,
                'transactionId' => $result->xactId,
            ]);

            return PaymentPayResultDto::succeeded(
                referenceNo: $referenceNo,
                transactionId: $result->xactId,
            );
        }

        return PaymentPayResultDto::failed(
            message: $result->errorMessage() ?? 'Payment could not be completed.',
            referenceNo: $referenceNo,
        );
    }

    private function formattedAmount(PaymentPayDto $payment): string
    {
        return PaynetTools::formatWithDecimalSeparator(
            PaynetTools::centsToAmount($payment->amountInMinorUnits),
        );
    }
}
