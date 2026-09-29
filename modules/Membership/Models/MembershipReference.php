<?php

namespace Modules\Membership\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member named as reference on an application, and their answer.
 */
class MembershipReference extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::PENDING => 'Yanıt bekleniyor',
        self::ACCEPTED => 'Kabul etti',
        self::DECLINED => 'Kabul etmedi',
        self::EXPIRED => 'Süresi doldu',
        self::WITHDRAWN => 'Değiştirildi',
    ];

    public const STATUS_COLORS = [
        self::PENDING => 'yellow',
        self::ACCEPTED => 'green',
        self::DECLINED => 'red',
        self::EXPIRED => 'secondary',
        self::WITHDRAWN => 'secondary',
    ];

    protected $fillable = ['application_id', 'applicant_contact_id', 'referee_contact_id', 'position', 'status', 'token_hash', 'invited_at', 'expires_at'];

    protected $casts = [
        'invited_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(MembershipApplication::class, 'application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'applicant_contact_id')->withTrashed();
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'referee_contact_id')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }

    /**
     * The status as shown: an unanswered invitation past its deadline is expired.
     */
    public function displayStatus(): string
    {
        return $this->status === self::PENDING && $this->expires_at->isPast() ? self::EXPIRED : $this->status;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->displayStatus()] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->displayStatus()] ?? 'secondary';
    }
}
