<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProvider;
use InvalidArgumentException;

class PaymentProviderManager
{
    public function for(string $provider): PaymentProvider
    {
        return match ($provider) {
            'mock' => new MockPaymentProvider(),
            default => throw new InvalidArgumentException('Unsupported payment provider.'),
        };
    }
}
