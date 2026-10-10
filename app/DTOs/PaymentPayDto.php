<?php

namespace App\DTOs;

use App\Models\Customer;

readonly class PaymentPayDto
{
    public function __construct(
        public Customer $customer,
        public int $amountInMinorUnits,
        public string $referenceNo,
        public string $clientIp,
        public string $customerAddress,
        public ?PaymentCardDto $card = null,
        public bool $threeDSecure = false,
    ) {}
}
