<?php

namespace App\Support\Payments;

final class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw  provider response, stored without secrets
     */
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $paymentId = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {
    }
}
