<?php

namespace Modules\FonzipImport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\FonzipImport\Support\FonzipRunner;
use Modules\FonzipImport\Support\FonzipStore;
use Throwable;

/**
 * One slice of the fetch or the import; queues the next slice until done.
 * Slices stay well under the queue's retry_after (90 s), so a long copy is
 * never picked up twice.
 */
class RunFonzipImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(public string $mode) {}

    public function handle(FonzipRunner $runner, FonzipStore $store): void
    {
        $expected = $this->mode === 'fetch' ? FonzipStore::FETCHING : FonzipStore::APPLYING;
        if ($store->state()['phase'] !== $expected) {
            return;
        }

        $done = $runner->exclusively(fn () => $this->mode === 'fetch' ? $runner->fetchSlice(40) : $runner->applySlice(40));
        // null: another slice holds the lock and queues the next one itself.
        if ($done === false) {
            self::dispatch($this->mode);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(FonzipRunner::class)->fail($exception->getMessage());
    }
}
