<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An email and/or SMS to the contacts that match criteria, sent by the
 * queue worker a few at a time (App\Support\BulkMessaging).
 */
class BulkMessage extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const SENDING = 'sending';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Taslak',
        self::SENDING => 'Gönderiliyor',
        self::DONE => 'Gönderildi',
        self::CANCELLED => 'Durduruldu',
    ];

    public const STATUS_COLORS = [
        self::DRAFT => 'secondary',
        self::SENDING => 'blue',
        self::DONE => 'green',
        self::CANCELLED => 'orange',
    ];

    public const CHANNELS = ['email' => 'E-posta', 'sms' => 'SMS'];

    protected $fillable = ['status', 'channels', 'criteria', 'informational', 'subject', 'body', 'sms', 'total', 'created_by', 'started_by', 'started_at', 'finished_at'];

    protected $attributes = [
        'status' => self::DRAFT,
    ];

    protected $casts = [
        'channels' => 'array',
        'criteria' => 'array',
        'informational' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(BulkMessageRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function uses(string $channel): bool
    {
        return in_array($channel, $this->channels ?? [], true);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Short name for lists: the subject, else the start of the SMS.
     */
    public function title(): string
    {
        return $this->subject ?: mb_strimwidth((string) $this->sms, 0, 60, '…') ?: '#'.$this->id;
    }

    public function auditLabel(): string
    {
        return $this->title();
    }
}
