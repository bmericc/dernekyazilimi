<?php

namespace Modules\FonzipImport\Console;

use Illuminate\Console\Command;
use Modules\FonzipImport\Support\FonzipImport;
use Modules\FonzipImport\Support\FonzipRunner;
use Modules\FonzipImport\Support\FonzipStore;
use Throwable;

/**
 * The Fonzip import from the console, without the queue:
 *   fonzip:import fetch     copy Fonzip data into the snapshot (~15 min)
 *   fonzip:import preview   what the import would do
 *   fonzip:import apply     import the snapshot
 * Run as www-data: the snapshot lives in storage.
 */
class FonzipImportCommand extends Command
{
    protected $signature = 'fonzip:import {step : fetch, preview veya apply} {--resume : Yarım kalan çekmeye kaldığı yerden devam et}';

    protected $description = 'Fonzip verisini çeker, önizler ve içe aktarır';

    public function handle(FonzipRunner $runner, FonzipStore $store, FonzipImport $import): int
    {
        if ($store->busy() && ! $this->option('resume')) {
            $this->error('Başka bir Fonzip işlemi sürüyor: '.$store->state()['phase']);

            return self::FAILURE;
        }

        try {
            return match ($this->argument('step')) {
                'fetch' => $this->fetch($runner, $store),
                'preview' => $this->preview($store, $import),
                'apply' => $this->apply($runner, $store),
                default => $this->invalid(),
            };
        } catch (Throwable $e) {
            $runner->fail($e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function fetch(FonzipRunner $runner, FonzipStore $store): int
    {
        if ($this->option('resume') && $store->snapshot()) {
            $store->setState(['phase' => FonzipStore::FETCHING, 'error' => null]);
        } else {
            $runner->startFetch();
        }

        while (($done = $runner->exclusively(fn () => $runner->fetchSlice(20))) !== true) {
            // null: a queued slice is working; wait for it.
            $done === null ? sleep(2) : $this->line($store->state()['progress'] ?? '');
        }
        $this->info('Fonzip verisi çekildi.');

        return self::SUCCESS;
    }

    private function preview(FonzipStore $store, FonzipImport $import): int
    {
        $snapshot = $store->snapshot();
        if (! $snapshot || ! $snapshot['fetched_at']) {
            $this->error('Önce "fonzip:import fetch" çalıştırın.');

            return self::FAILURE;
        }

        $plan = $import->plan($snapshot);
        $this->table(['Kişiler', 'Sayı'], collect($plan['counts'])->map(fn ($count, $action) => [$action, $count])->values());
        $this->table(['Üyelik', 'Sayı'], collect($plan['memberships'])->map(fn ($count, $action) => [$action, $count])->values());
        $this->table(['Hesap', 'Sayı'], collect($plan['accounts'])->map(fn ($count, $action) => [$action, $count])->values());
        $this->table(['Yönlendirme', 'Sayı'], collect($plan['forwardings'])->map(fn ($count, $action) => [$action, $count])->values());
        $this->table(['Kayıt', 'Toplam', 'Yeni', 'Zaten aktarılmış', 'Kişisi yok', 'Tutar'], collect($plan['finance'])
            ->map(fn ($row, $kind) => [$kind, $row['total'], $row['new'], $row['linked'], $row['orphan'], number_format($row['amount'], 2, ',', '.')])->values());

        foreach ($plan['contacts'] as $item) {
            foreach ($item['problems'] as $problem) {
                $this->warn("Fonzip #{$item['fonzip_id']}: $problem");
            }
        }

        return self::SUCCESS;
    }

    private function apply(FonzipRunner $runner, FonzipStore $store): int
    {
        $snapshot = $store->snapshot();
        if (! $snapshot || ! $snapshot['fetched_at']) {
            $this->error('Önce "fonzip:import fetch" çalıştırın.');

            return self::FAILURE;
        }

        if (! $this->option('resume') || $store->state()['phase'] !== FonzipStore::APPLYING) {
            $runner->startApply(null);
        }
        while (($done = $runner->exclusively(fn () => $runner->applySlice(20))) !== true) {
            // null: a queued slice is working; wait for it.
            $done === null ? sleep(2) : $this->line($store->state()['progress'] ?? '');
        }
        $this->info(FonzipRunner::message($store->state()['summary']));

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Adım fetch, preview veya apply olmalı.');

        return self::INVALID;
    }
}
