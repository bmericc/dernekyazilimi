<?php

namespace App\Console\Commands;

use App\Events\ScheduledNoticesDue;
use Illuminate\Console\Command;

/**
 * Daily scheduled notices (birthday greetings, reminders), each turned on
 * in its settings page. Runs from the scheduler; by hand with --dry-run to
 * see who would get what.
 */
class SendScheduledNotices extends Command
{
    protected $signature = 'notices:send {--dry-run : Yalnız kaç kişiye gideceğini göster}';

    protected $description = 'Zamanlanmış bildirimleri (doğum günü, hatırlatmalar) gönderir';

    public function handle(): int
    {
        $event = new ScheduledNoticesDue((bool) $this->option('dry-run'));
        event($event);

        if ($event->report === []) {
            $this->info('Gönderilecek bildirim yok.');
        }
        foreach ($event->report as $key => $count) {
            $this->line(($event->dryRun ? '[deneme] ' : '')."{$key}: {$count} kişi");
        }

        return self::SUCCESS;
    }
}
