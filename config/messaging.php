<?php

return [
    // netgsm, or log to write messages to the log instead of sending them.
    'sms' => env('MESSAGING_SMS_DRIVER', 'netgsm'),

    // bridge (needs WHATSAPP_BRIDGE_URL), or log.
    'whatsapp' => env('MESSAGING_WHATSAPP_DRIVER', 'bridge'),

    // Bulk messages (/admin/bulk-messages): recipients per batch and the
    // pause in seconds before the next batch.
    'bulk_per_batch' => (int) env('MESSAGING_BULK_PER_BATCH', 30),
    'bulk_pause' => (int) env('MESSAGING_BULK_PAUSE', 60),
];
