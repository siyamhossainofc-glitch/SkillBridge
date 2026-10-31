<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Http;
use Throwable;

class FraudDetectionService
{
    public function analyze(Bid $bid): array
    {
        $signals = $this->signals($bid);
        $fallback = [
            'risk_score' => $signals['deterministic_score'],
            'risk_level' => $this->level($signals['deterministic_score']),
            'reasons' => $signals['reasons'],
            'signal_keys' => array_keys($signals['signal_values']),
            'source' => 'deterministic-v1',
            'action_taken' => false,
            'notice' => 'Risk analysis is advisory. Admin moderation remains the final authority.',
        ];
        $key = config('services.ai_fraud.key');
        $endpoint = config('services.ai_fraud.endpoint');
        if (!$key || !$endpoint) return $fallback;

        try {
            $response = Http::timeout(20)->withToken($key)->post($endpoint, [
                'model' => config('services.ai_fraud.model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Analyze only the supplied marketplace risk signals. Do not accuse, ban, suspend, delete, or recommend automatic punishment. Return a normalized advisory score and explainable reasons using only supplied signal keys.'],
                    ['role' => 'user', 'content' => json_encode($signals, JSON_THROW_ON_ERROR)],
                ],
                'response_format' => ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'fraud_risk_analysis', 'strict' => true, 'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'risk_score' => ['type' => 'number'],
                            'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'signal_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['risk_score', 'reasons', 'signal_keys'],
                        'additionalProperties' => false,
                    ],
                ]],
                'temperature' => 0,
            ]);
            if (!$response->successful()) return $fallback;
            $content = data_get($response->json(), 'choices.0.message.content');
            $analysis = is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
            if (!is_array($analysis) || !is_array($analysis['signal_keys'] ?? null) || !is_array($analysis['reasons'] ?? null)) return $fallback;
            $validKeys = array_fill_keys(array_keys($signals['signal_values']), true);
            $keys = collect($analysis['signal_keys'] ?? [])->filter(fn ($key) => is_string($key) && isset($validKeys[$key]))->unique()->values()->all();
            $reasons = collect($analysis['reasons'] ?? [])->filter(fn ($reason) => is_string($reason) && trim($reason) !== '')->map(fn ($reason) => trim($reason))->values()->all();
            if (!is_array($analysis) || !is_numeric($analysis['risk_score'] ?? null) || count($keys) !== count($analysis['signal_keys'] ?? []) || !$reasons) return $fallback;
            $score = max(0, min(100, (int) round((float) $analysis['risk_score'])));
            return [
                'risk_score' => $score,
                'risk_level' => $this->level($score),
                'reasons' => $reasons,
                'signal_keys' => $keys,
                'source' => 'ai-v1',
                'action_taken' => false,
                'notice' => $fallback['notice'],
            ];
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function signals(Bid $bid): array
    {
        $provider = $bid->provider;
        $job = $bid->jobPost;
        $providerBids = Bid::where('provider_id', $provider->id);
        $totalBids = (clone $providerBids)->count();
        $withdrawnOrRejected = (clone $providerBids)->whereIn('status', ['withdrawn', 'rejected'])->count();
        $contracts = Contract::where('provider_id', $provider->id);
        $failedPayments = PaymentTransaction::whereIn('contract_id', (clone $contracts)->select('id'))->whereIn('status', ['failed', 'cancelled'])->count();
        $signalValues = [];
        $reasons = [];
        $score = 0;
        $add = function (string $key, int $points, string $reason) use (&$signalValues, &$reasons, &$score): void {
            $signalValues[$key] = $points;
            $score += $points;
            $reasons[] = $reason;
        };
        if ($provider->moderation_status !== 'active') $add('provider_not_active', 50, 'Provider account is not active.');
        if (!$provider->verification || $provider->verification->status !== 'verified') $add('provider_not_verified', 10, 'Provider verification is not confirmed.');
        if ($job->budget_max && (float) $bid->amount > (float) $job->budget_max * 1.5) $add('amount_far_above_budget', 25, 'Bid amount is materially above the job budget maximum.');
        if ($job->budget_min && (float) $bid->amount < (float) $job->budget_min * 0.5) $add('amount_far_below_budget', 15, 'Bid amount is materially below the job budget minimum.');
        if ($totalBids >= 20) $add('high_bid_volume', 15, 'Provider has submitted a high number of bids.');
        if ($totalBids >= 5 && $withdrawnOrRejected / $totalBids >= 0.8) $add('high_withdrawal_rejection_ratio', 20, 'Provider has a high withdrawn or rejected bid ratio.');
        if ($failedPayments > 0) $add('failed_payment_history', 20, 'Provider is associated with failed or cancelled payment transactions.');
        if (!$provider->skills()->exists() && !$provider->services()->where('status', 'published')->exists()) $add('thin_provider_profile', 10, 'Provider has no stored skills or published services.');
        return ['deterministic_score' => min(100, $score), 'signal_values' => $signalValues, 'reasons' => $reasons, 'bid' => ['id' => (int) $bid->id, 'amount' => $bid->amount], 'job' => ['id' => (int) $job->id, 'category_id' => $job->category_id, 'budget_min' => $job->budget_min, 'budget_max' => $job->budget_max], 'provider' => ['id' => (int) $provider->id, 'role' => $provider->role, 'moderation_status' => $provider->moderation_status, 'total_bids' => $totalBids, 'contracts' => $contracts->count(), 'failed_payments' => $failedPayments]];
    }

    private function level(int $score): string
    {
        return $score >= 70 ? 'high' : ($score >= 35 ? 'medium' : 'low');
    }
}
