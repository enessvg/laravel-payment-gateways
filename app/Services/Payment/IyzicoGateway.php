<?php

namespace App\Services\Payment;

use App\DTOs\PaymentCallbackDto;
use App\DTOs\PaymentCallbackResultDto;
use App\DTOs\PaymentCardDto;
use App\DTOs\PaymentClientActionDto;
use App\DTOs\PaymentPayDto;
use App\DTOs\PaymentPayResultDto;
use App\Interfaces\PaymentInterface;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\BasketItemType;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Locale;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\PaymentChannel;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Model\PaymentResource;
use Iyzipay\Model\ThreedsInitialize;
use Iyzipay\Model\ThreedsPayment;
use Iyzipay\Options;
use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Request\CreateThreedsPaymentRequest;

class IyzicoGateway implements PaymentInterface
{
    private const string BASKET_ID = 'B67832';

    public function requiresServerSideCard(): bool
    {
        return true;
    }

    public function pay(PaymentPayDto $payment): PaymentPayResultDto
    {
        if ($payment->card === null) {
            return PaymentPayResultDto::failed(
                message: 'Card details are required for Iyzico payments.',
                referenceNo: $payment->referenceNo,
            );
        }

        return $payment->threeDSecure
            ? $this->startThreeDSecure($payment, $payment->card)
            : $this->payWithoutThreeDSecure($payment, $payment->card);
    }

    public function handleCallback(PaymentCallbackDto $callback): PaymentCallbackResultDto
    {
        $payload = $this->threeDSecureCallbackPayload($callback);

        if ($payload === null) {
            Log::error('Iyzico 3D Secure callback data is incomplete', [
                'payload' => $callback->payload,
            ]);

            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'Iyzico 3D Secure callback data is incomplete.',
                    referenceNo: $callback->string('conversationId'),
                ),
            );
        }

        if (! $this->hasValidCallbackSignature($payload)) {
            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'Iyzico 3D Secure callback signature is invalid.',
                    referenceNo: $payload['conversationId'] ?: null,
                ),
            );
        }

        if ($payload['status'] !== 'success') {
            return PaymentCallbackResultDto::payment(
                PaymentPayResultDto::failed(
                    message: 'Iyzico 3D Secure authentication failed.',
                    referenceNo: $payload['conversationId'] ?: null,
                ),
            );
        }

        $request = new CreateThreedsPaymentRequest;
        $request->setLocale(Locale::TR);
        $request->setConversationId($payload['conversationId']);
        $request->setPaymentId($payload['paymentId']);
        $request->setConversationData($payload['conversationData']);

        $result = ThreedsPayment::create($request, $this->options());

        return PaymentCallbackResultDto::payment(
            $this->mapPaymentResult(
                result: $result,
                fallbackReferenceNo: $payload['conversationId'] ?: null,
            ),
        );
    }

    private function payWithoutThreeDSecure(
        PaymentPayDto $payment,
        PaymentCardDto $card,
    ): PaymentPayResultDto {
        $result = Payment::create(
            request: $this->createPaymentRequest($payment, $card),
            options: $this->options(),
        );

        return $this->mapPaymentResult($result, $payment->referenceNo);
    }

    private function startThreeDSecure(
        PaymentPayDto $payment,
        PaymentCardDto $card,
    ): PaymentPayResultDto {
        $request = $this->createPaymentRequest($payment, $card);
        $request->setCallbackUrl((string) config('payment.gateways.iyzico.return_url'));

        $result = ThreedsInitialize::create($request, $this->options());
        $html = $result->getHtmlContent();

        if ($result->getStatus() === 'success' && ! $this->hasValidThreeDSecureInitializationSignature($result)) {
            return PaymentPayResultDto::failed(
                message: 'Iyzico 3D Secure initialization signature is invalid.',
                referenceNo: $result->getConversationId() ?? $payment->referenceNo,
            );
        }

        if ($result->getStatus() === 'success' && is_string($html) && $html !== '') {
            $referenceNo = $result->getConversationId() ?? $payment->referenceNo;

            Log::info('3D Secure payment initiated', [
                'referenceNo' => $referenceNo,
                'transactionId' => $result->getPaymentId(),
            ]);

            return PaymentPayResultDto::requiresClientAction(
                referenceNo: $referenceNo,
                action: PaymentClientActionDto::html($html),
            );
        }

        return PaymentPayResultDto::failed(
            message: $result->getErrorMessage() ?? '3D Secure payment could not be initiated.',
            referenceNo: $result->getConversationId() ?? $payment->referenceNo,
        );
    }

    private function mapPaymentResult(
        PaymentResource $result,
        ?string $fallbackReferenceNo,
    ): PaymentPayResultDto {
        $referenceNo = $result->getConversationId() ?? $fallbackReferenceNo;

        if ($result->getStatus() === 'success') {
            if (! $this->hasValidPaymentSignature($result)) {
                return PaymentPayResultDto::failed(
                    message: 'Iyzico payment response signature is invalid.',
                    referenceNo: $referenceNo,
                );
            }

            Log::info('Payment successful', [
                'referenceNo' => $referenceNo,
                'transactionId' => $result->getPaymentId(),
            ]);

            return PaymentPayResultDto::succeeded(
                referenceNo: $referenceNo,
                transactionId: $result->getPaymentId(),
            );
        }

        return PaymentPayResultDto::failed(
            message: $result->getErrorMessage() ?? 'Payment could not be completed.',
            referenceNo: $referenceNo,
        );
    }

    private function options(): Options
    {
        $options = new Options;
        $options->setApiKey((string) config('payment.gateways.iyzico.api_key'));
        $options->setSecretKey((string) config('payment.gateways.iyzico.secret_key'));
        $options->setBaseUrl((string) config('payment.gateways.iyzico.base_url'));

        return $options;
    }

    private function createPaymentRequest(
        PaymentPayDto $payment,
        PaymentCardDto $card,
    ): CreatePaymentRequest {
        $request = new CreatePaymentRequest;
        $request->setLocale(Locale::TR);
        $request->setConversationId($payment->referenceNo);
        $request->setPrice($this->formattedAmount($payment));
        $request->setPaidPrice($this->formattedAmount($payment));
        $request->setCurrency(Currency::TL);
        $request->setInstallment(1);
        $request->setBasketId(self::BASKET_ID);
        $request->setPaymentChannel(PaymentChannel::WEB);
        $request->setPaymentGroup(PaymentGroup::PRODUCT);
        $request->setPaymentCard($this->createPaymentCard($card));
        $request->setBuyer($this->createBuyer($payment->customer));
        $request->setShippingAddress($this->createShippingAddress());
        $request->setBillingAddress($this->createBillingAddress());
        $request->setBasketItems($this->createBasketItems());

        return $request;
    }

    private function createPaymentCard(PaymentCardDto $card): PaymentCard
    {
        $paymentCard = new PaymentCard;
        $paymentCard->setCardHolderName($card->cardHolder);
        $paymentCard->setCardNumber($card->pan);
        $paymentCard->setExpireMonth($card->month);
        $paymentCard->setExpireYear($card->year);
        $paymentCard->setCvc($card->cvv);
        $paymentCard->setRegisterCard(0);

        return $paymentCard;
    }

    private function createBuyer(Customer $customer): Buyer
    {
        $buyer = new Buyer;
        $buyer->setId((string) $customer->getKey());
        $buyer->setName((string) $customer->name);
        $buyer->setSurname((string) $customer->surname);
        $buyer->setGsmNumber((string) $customer->phone);
        $buyer->setEmail((string) $customer->email);
        $buyer->setIdentityNumber('74300864791');
        $buyer->setLastLoginDate('2015-10-05 12:43:35');
        $buyer->setRegistrationDate('2013-04-21 15:12:09');
        $buyer->setRegistrationAddress('Nidakule Göztepe, Merdivenköy Mah. Bora Sok. No:1');
        $buyer->setIp('85.34.78.112');
        $buyer->setCity('Istanbul');
        $buyer->setCountry('Turkey');
        $buyer->setZipCode('34732');

        return $buyer;
    }

    private function createShippingAddress(): Address
    {
        $shippingAddress = new Address;
        $shippingAddress->setContactName('Jane Doe');
        $shippingAddress->setCity('Istanbul');
        $shippingAddress->setCountry('Turkey');
        $shippingAddress->setAddress('Nidakule Göztepe, Merdivenköy Mah. Bora Sok. No:1');
        $shippingAddress->setZipCode('34742');

        return $shippingAddress;
    }

    private function createBillingAddress(): Address
    {
        $billingAddress = new Address;
        $billingAddress->setContactName('Jane Doe');
        $billingAddress->setCity('Istanbul');
        $billingAddress->setCountry('Turkey');
        $billingAddress->setAddress('Nidakule Göztepe, Merdivenköy Mah. Bora Sok. No:1');
        $billingAddress->setZipCode('34742');

        return $billingAddress;
    }

    /**
     * @return list<BasketItem>
     */
    private function createBasketItems(): array
    {
        $items = [
            ['id' => 'BI101', 'name' => 'Binocular', 'price' => 30],
            ['id' => 'BI102', 'name' => 'Accessory', 'price' => 70],
        ];

        $basketItems = [];

        foreach ($items as $item) {
            $basketItem = new BasketItem;
            $basketItem->setId($item['id']);
            $basketItem->setName($item['name']);
            $basketItem->setCategory1('Collectibles');
            $basketItem->setCategory2('Accessories');
            $basketItem->setItemType(BasketItemType::PHYSICAL);
            $basketItem->setPrice($item['price']);
            $basketItems[] = $basketItem;
        }

        return $basketItems;
    }

    private function formattedAmount(PaymentPayDto $payment): string
    {
        return number_format($payment->amountInMinorUnits / 100, 2, '.', '');
    }

    /**
     * @return array{status: string, paymentId: string, conversationData: string, conversationId: string, mdStatus: string, signature: string}|null
     */
    private function threeDSecureCallbackPayload(PaymentCallbackDto $callback): ?array
    {
        $fields = [
            'status',
            'paymentId',
            'conversationData',
            'conversationId',
            'mdStatus',
            'signature',
        ];

        $payload = [];

        foreach ($fields as $field) {
            $value = $callback->payload[$field] ?? null;

            if ($field === 'conversationData' && $value === null) {
                $value = '';
            }

            if ($field === 'conversationId' && is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value)) {
                return null;
            }

            $payload[$field] = $value;
        }

        if ($payload['status'] === '' || $payload['paymentId'] === '' || $payload['conversationId'] === '' || $payload['mdStatus'] === '' || $payload['signature'] === '') {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array{status: string, paymentId: string, conversationData: string, conversationId: string, mdStatus: string, signature: string}  $payload
     */
    private function hasValidCallbackSignature(array $payload): bool
    {
        return $this->matchesSignature(
            values: [
                $payload['conversationData'],
                $payload['conversationId'],
                $payload['mdStatus'],
                $payload['paymentId'],
                $payload['status'],
            ],
            signature: $payload['signature'],
        );
    }

    private function hasValidThreeDSecureInitializationSignature(ThreedsInitialize $result): bool
    {
        return $this->matchesSignature(
            values: [
                $result->getPaymentId(),
                $result->getConversationId(),
            ],
            signature: $result->getSignature(),
        );
    }

    private function hasValidPaymentSignature(PaymentResource $result): bool
    {
        return $this->matchesSignature(
            values: [
                $result->getPaymentId(),
                $result->getCurrency(),
                $result->getBasketId(),
                $result->getConversationId(),
                $result->getPaidPrice(),
                $result->getPrice(),
            ],
            signature: $result->getSignature(),
        );
    }

    /**
     * @param  list<mixed>  $values
     */
    private function matchesSignature(array $values, mixed $signature): bool
    {
        $secretKey = (string) config('payment.gateways.iyzico.secret_key');

        if ($secretKey === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                return false;
            }
        }

        $signedData = implode(':', array_map(strval(...), $values));

        $expectedSignature = hash_hmac('sha256', $signedData, $secretKey);

        return hash_equals($expectedSignature, $signature);
    }
}
