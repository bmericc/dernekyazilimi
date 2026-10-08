<?php

namespace Modules\Correspondence\Models;

use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outgoing official letter.
 */
class Letter extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const NUMBERED = 'numbered';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Taslak',
        self::PENDING => 'Onay bekliyor',
        self::NUMBERED => 'Sayı verildi',
        self::CANCELLED => 'İptal edildi',
    ];

    public const STATUS_COLORS = [
        self::DRAFT => 'secondary',
        self::PENDING => 'yellow',
        self::NUMBERED => 'green',
        self::CANCELLED => 'red',
    ];

    /** Written in the portal, which prints it as a PDF. */
    public const COMPOSED = 'composed';

    /** A finished letter uploaded as a PDF, with the number and date it already carries. */
    public const PDF = 'pdf';

    /** Files of the letters on the private disk. */
    public const DIRECTORY = 'correspondence';

    protected $table = 'correspondence_letters';

    protected $fillable = ['subject', 'body', 'references', 'signers', 'file_code', 'file_name'];

    protected $attributes = [
        'source' => self::COMPOSED,
    ];

    protected $casts = [
        'references' => 'array',
        'signers' => 'array',
        'document_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(LetterRecipient::class)->orderBy('sort')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LetterAttachment::class)->orderBy('sort')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPdf(): bool
    {
        return $this->source === self::PDF;
    }

    /** The content may change only before the letter gets its number. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Numbered and not cancelled: the letter is in force. */
    public function isNumbered(): bool
    {
        return $this->status === self::NUMBERED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function directory(): string
    {
        return self::DIRECTORY.'/'.$this->id;
    }

    public function auditLabel(): string
    {
        return trim(($this->document_no ? $this->document_no.' ' : '').$this->subject);
    }
}
