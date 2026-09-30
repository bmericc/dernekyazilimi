<?php

namespace Modules\FonzipImport\Support;

use App\Models\ProcessLogs;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the fetch and the import in short slices and keeps their state, for
 * the queued job and the console command alike.
 */
class FonzipRunner
{
    public function __construct(private FonzipFetcher $fetcher, private FonzipImport $import, private FonzipStore $store) {}

    /**
     * Runs $callback unless another slice (a second job, the console) is
     * working; slices read their position from the state, so they never
     * process the same part twice.
     */
    public function exclusively(callable $callback): mixed
    {
        $lock = Cache::lock('fonzip-import:slice', 120);
        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function startFetch(): void
    {
        $this->fetcher->start();
    }

    /**
     * True when the copy is complete.
     */
    public function fetchSlice(float $seconds): bool
    {
        return $this->fetcher->step($seconds);
    }

    public function startApply(?int $userId): void
    {
        $this->store->setState([
            'phase' => FonzipStore::APPLYING, 'progress' => 'Başlıyor', 'error' => null,
            'position' => ['phase' => 'setup', 'offset' => 0],
            'summary' => FonzipImport::emptySummary(), 'user_id' => $userId,
        ]);
    }

    /**
     * Import for about $seconds; true when the import is complete.
     */
    public function applySlice(float $seconds): bool
    {
        $state = $this->store->state();
        $snapshot = $this->store->snapshot();
        if (! $snapshot || $state['phase'] !== FonzipStore::APPLYING) {
            return true;
        }

        $summary = $state['summary'] ?? FonzipImport::emptySummary();
        $position = $state['position'] ?? ['phase' => 'setup', 'offset' => 0];
        $until = microtime(true) + $seconds;
        do {
            $position = $this->import->applyStep($snapshot, $position, $summary, $state['user_id'] ?? null);
        } while ($position && microtime(true) < $until);

        if ($position) {
            $this->store->setState(['position' => $position, 'summary' => $summary, 'progress' => $this->progress($position, $snapshot)]);

            return false;
        }

        $this->store->discard();
        $this->store->setState(['phase' => FonzipStore::DONE, 'position' => null, 'summary' => $summary, 'progress' => null, 'finished_at' => now()->toIso8601String()]);

        $log = new ProcessLogs;
        $log->process_by = $state['user_id'] ?? null;
        $log->process_type = 'change';
        $log->process = self::message($summary);
        $log->request_ip = null;
        $log->save();

        return true;
    }

    public function fail(string $message): void
    {
        $this->store->setState(['phase' => FonzipStore::FAILED, 'error' => $message, 'failed_during' => $this->store->state()['phase']]);
    }

    public static function message(array $summary): string
    {
        return "Fonzip aktarıldı: {$summary['contacts_new']} yeni kişi, {$summary['contacts_updated']} güncellenen, {$summary['contacts_skipped']} atlanan kişi; "
            ."{$summary['memberships_new']} yeni üyelik, ".($summary['memberships_left'] ?? 0)." ayrılmış üyelik (Fonzip'te silinmiş), {$summary['numbers_set']} üye no, ".($summary['accounts_new'] ?? 0)." yeni hesap; "
            ."{$summary['charges']} aidat borcu ({$summary['charges_matched']} mevcut borçla eşleşti), {$summary['payments']} aidat ödemesi, {$summary['donations']} bağış.";
    }

    private function progress(array $position, array $snapshot): string
    {
        $labels = ['setup' => 'Alanlar ve etiketler', 'contacts' => 'Kişiler', 'charges' => 'Aidat borçları', 'payments' => 'Aidat ödemeleri', 'refunds' => 'İadeler', 'donations' => 'Bağışlar'];
        $total = count($position['phase'] === 'contacts' ? $snapshot['users'] : ($snapshot[$position['phase'] === 'charges' ? 'debts' : $position['phase']] ?? []));

        return $labels[$position['phase']].($total ? ': '.min($position['offset'], $total).' / '.$total : '');
    }
}
