<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkMessageRecipient extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = ['bulk_message_id', 'contact_id', 'email', 'phone', 'email_status', 'sms_status', 'error', 'processed_at'];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(BulkMessage::class, 'bulk_message_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }
}
