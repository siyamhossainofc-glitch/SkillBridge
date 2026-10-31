<?php

namespace App\Contracts\Payments;

use App\Models\PaymentTransaction;

interface PaymentProvider
{
    public function createIntent(PaymentTransaction $payment): array;

    public function verifyWebhookSignature(string $payload, string $signature): bool;

    public function parseWebhook(string $payload): array;
}
