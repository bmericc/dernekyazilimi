<?php

namespace Modules\FonzipImport;

use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use Modules\FonzipImport\Support\FonzipClient;

/**
 * One-off migration from Fonzip: people, membership numbers, custom fields,
 * tags, consents, dues debts and payments, donations. Turned on for the move
 * and off afterwards; the links it leaves (fonzip_links) let a later run
 * import only what is new.
 */
class FonzipImportServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'fonzip-import';
    }

    public function register(): void
    {
        parent::register();

        // One client per process: it keeps the token and spaces the requests.
        $this->app->singleton(FonzipClient::class);
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->permissions()->register('fonzip.import', 'Fonzip\'ten veri aktarabilsin', 'membership', 29);

        $menu->add('admin', 'membership', 'Fonzip\'ten aktar', 'admin.fonzip', ['fonzip.import'], 26);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\FonzipImportCommand::class]);
        }
    }
}
