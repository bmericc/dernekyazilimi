<?php

namespace App\Jobs;

use App\Support\BulkMessaging;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One batch of a bulk message; queues the next one itself.
 */
class SendBulkMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $messageId)
    {
    }

    public function handle(BulkMessaging $bulk): void
    {
        $bulk->work($this->messageId);
    }
}
