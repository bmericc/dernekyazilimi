<?php

namespace Modules\FonzipImport\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\FonzipImport\Jobs\RunFonzipImport;
use Modules\FonzipImport\Support\FonzipClient;
use Modules\FonzipImport\Support\FonzipImport;
use Modules\FonzipImport\Support\FonzipRunner;
use Modules\FonzipImport\Support\FonzipStore;

/**
 * Fonzip import: fetch the data (a queued job, ~15 minutes), preview what
 * would change, then import (queued too). The fetched data waits on the
 * private disk and is deleted after the import.
 */
class FonzipImportController extends Controller
{
    public function index(FonzipStore $store, FonzipClient $client): View
    {
        $snapshot = $store->snapshot();

        return view('fonzip-import::admin.index', [
            'state' => $store->state(),
            'busy' => $store->busy(),
            'configured' => $client->configured(),
            'snapshot' => $snapshot ? [
                'fetched_at' => $snapshot['fetched_at'],
                'started_at' => $snapshot['started_at'],
                'users' => count($snapshot['users']),
                'debts' => count($snapshot['debts']),
                'payments' => count($snapshot['payments']),
                'donations' => count($snapshot['donations']),
            ] : null,
        ]);
    }

    public function fetch(FonzipStore $store, FonzipRunner $runner, FonzipClient $client): RedirectResponse
    {
        if ($store->busy()) {
            return back()->with('danger-status', 'Bir işlem zaten sürüyor.');
        }
        if (! $client->configured()) {
            return back()->with('danger-status', 'Fonzip API anahtarı .env\'de tanımlı değil.');
        }

        $runner->startFetch();
        RunFonzipImport::dispatch('fetch');
        $this->set_log('other', 'Fonzip verisini çekme başlatıldı');

        return redirect()->route('admin.fonzip')->with('success-status', 'Fonzip verisi çekiliyor. Bu birkaç dakika (kişi sayısına göre 15 dakikaya kadar) sürer; sayfa kendiliğinden yenilenir.');
    }

    /**
     * Continue a fetch that stopped on an error, from where it stopped.
     */
    public function resume(FonzipStore $store): RedirectResponse
    {
        $state = $store->state();
        $snapshot = $store->snapshot();
        if ($state['phase'] !== FonzipStore::FAILED || ! $snapshot) {
            return back();
        }

        if (($state['failed_during'] ?? null) === FonzipStore::APPLYING) {
            $store->setState(['phase' => FonzipStore::APPLYING, 'error' => null]);
            RunFonzipImport::dispatch('apply');
        } elseif (! $snapshot['fetched_at']) {
            $store->setState(['phase' => FonzipStore::FETCHING, 'error' => null]);
            RunFonzipImport::dispatch('fetch');
        } else {
            $store->setState(['phase' => FonzipStore::FETCHED, 'error' => null]);
        }

        return redirect()->route('admin.fonzip')->with('success-status', 'Kaldığı yerden devam ediyor.');
    }

    public function preview(FonzipStore $store, FonzipImport $import): View|RedirectResponse
    {
        $snapshot = $store->snapshot();
        if (! $snapshot || ! $snapshot['fetched_at'] || $store->busy()) {
            return redirect()->route('admin.fonzip')->with('danger-status', 'Önce Fonzip verisini çekin.');
        }

        return view('fonzip-import::admin.preview', [
            'plan' => $import->plan($snapshot),
            'snapshot' => $snapshot,
        ]);
    }

    public function apply(FonzipStore $store, FonzipRunner $runner): RedirectResponse
    {
        $snapshot = $store->snapshot();
        if (! $snapshot || ! $snapshot['fetched_at'] || $store->busy()) {
            return redirect()->route('admin.fonzip')->with('danger-status', 'Önce Fonzip verisini çekin.');
        }

        $runner->startApply(Auth::id());
        RunFonzipImport::dispatch('apply');

        return redirect()->route('admin.fonzip')->with('success-status', 'İçe aktarma başladı; sayfa kendiliğinden yenilenir.');
    }

    public function discard(FonzipStore $store): RedirectResponse
    {
        if ($store->state()['phase'] === FonzipStore::APPLYING) {
            return back()->with('danger-status', 'İçe aktarma sürerken veri silinemez.');
        }

        $store->discard();
        $store->setState(['phase' => FonzipStore::IDLE, 'progress' => null, 'error' => null, 'position' => null]);

        return redirect()->route('admin.fonzip')->with('success-status', 'Çekilen Fonzip verisi silindi.');
    }
}
