<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProvider;
use App\Models\PaymentTransaction;
use Illuminate\Support\Str;
use RuntimeException;

class MockPaymentProvider implements PaymentProvider
{
    public function createIntent(PaymentTransaction $payment): array
    {
        if (config('services.payments.mock.fail_create', false)) {
            throw new RuntimeException('Payment provider unavailable.');
        }

        return [
            'provider_payment_id' => 'mock_'.Str::lower((string) Str::uuid()),
            'status' => 'pending',
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, (string) config('services.payments.mock.webhook_secret'));
        return $signature !== '' && hash_equals($expected, $signature);
    }

    public function parseWebhook(string $payload): array
    {
        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['event_id'], $event['provider_payment_id'], $event['status'])) {
            throw new RuntimeException('Invalid payment webhook payload.');
        }

        return [
            'event_id' => (string) $event['event_id'],
            'provider_payment_id' => (string) $event['provider_payment_id'],
            'status' => (string) $event['status'],
            'amount' => isset($event['amount']) ? (string) $event['amount'] : null,
            'currency' => isset($event['currency']) ? strtoupper((string) $event['currency']) : null,
        ];
    }
}
