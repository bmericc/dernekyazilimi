<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Support\Payments\Payments;
use Illuminate\Console\Command;

/**
 * Card payments are settled when the payer's browser comes back from the
 * gateway's page. A payer who closes the tab after paying never comes back,
 * so the payment would stay pending although the card was charged. This
 * asks the gateway about pending card payments from the last days.
 */
class CheckCardPayments extends Command
{
    protected $signature = 'payments:check-cards
        {--limit=50 : En çok kaç ödeme sorulacak}
        {--abandon-after=60 : Ödenmemiş ödeme kaç dakika sonra iptal edilecek}';

    protected $description = 'Sonucu gelmeyen kart ödemelerini ödeme sistemine sorar';

    public function handle(Payments $payments): int
    {
        $abandonedBefore = now()->subMinutes(max(30, (int) $this->option('abandon-after')));
        $pending = Payment::with('gateway')->where('status', Payment::PENDING)->where('method', Payment::CARD)
            ->whereNotNull('gateway_token')
            // A few minutes for the payer to finish; after three days the gateway no longer knows the token.
            ->whereBetween('created_at', [now()->subDays(3), now()->subMinutes(5)])
            ->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();

        $counts = [];
        foreach ($pending as $payment) {
            $status = $payments->checkCard($payment, $abandonedBefore);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if ($status === Payment::SUCCEEDED) {
                $this->line("{$payment->reference}: tahsil edilmiş, kaydedildi.");
            }
        }

        $this->info($pending->isEmpty() ? 'Sorulacak ödeme yok.' : collect($counts)->map(fn ($count, $status) => "{$status}: {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
