<?php

namespace App\DTOs;

readonly class PaymentCardDto
{
    public function __construct(
        public string $pan,
        public string $month,
        public string $year,
        public string $cvv,
        public string $cardHolder,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            pan: (string) $data['pan'],
            month: (string) $data['month'],
            year: (string) $data['year'],
            cvv: (string) $data['cvv'],
            cardHolder: (string) $data['cardHolder'],
        );
    }
}
