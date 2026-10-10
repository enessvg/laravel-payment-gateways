<?php

namespace App\Http\Controllers;

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentPayDto;
use App\DTOs\PaymentPayResultDto;
use App\Http\Requests\ProcessPaymentRequest;
use App\Interfaces\PaymentInterface;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentController extends Controller
{
    private const int PAYMENT_AMOUNT_IN_MINOR_UNITS = 10_000;

    public function __construct(
        private readonly PaymentInterface $paymentGateway,
    ) {}

    public function pay(ProcessPaymentRequest $request): JsonResponse
    {
        $customer = Customer::query()->with('addresses')->firstOrFail();
        $card = $request->validated('card');

        $payment = new PaymentPayDto(
            threeDSecure: $request->validated('3d_secure'),
            customer: $customer,
            amountInMinorUnits: self::PAYMENT_AMOUNT_IN_MINOR_UNITS,
            referenceNo: 'SIP'.Str::ulid(),
            clientIp: $request->ip() ?? '',
            customerAddress: (string) ($customer->addresses->first()?->address ?? 'Demo Address'),
            card: is_array($card) ? PaymentCardDto::fromArray($card) : null,
        );

        $result = $this->paymentGateway->pay($payment);

        return $this->paymentResponse($result);
    }

    public function callback(Request $request): SymfonyResponse
    {
        $result = $this->paymentGateway->handleCallback(
            PaymentCallbackDto::fromArray($request->all()),
        );

        if ($result->response !== null) {
            return response(
                content: $result->response->body,
                status: $result->response->statusCode,
                headers: $result->response->headers,
            );
        }

        return $this->paymentResponse($result->payment);
    }

    public function returnSuccess(): JsonResponse
    {
        return response()->json([
            'status' => 'pending',
            'message' => 'Payment result is being verified.',
        ]);
    }

    public function returnFailure(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $request->string('fail_message')->value() ?: 'Payment could not be completed.',
        ]);
    }

    private function paymentResponse(PaymentPayResultDto $result): JsonResponse
    {
        return response()->json(
            data: $result->toArray(),
            status: $result->success
                ? SymfonyResponse::HTTP_OK
                : SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
