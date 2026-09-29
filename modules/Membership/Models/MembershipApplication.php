<?php

namespace Modules\Membership\Models;

use App\Models\Concerns\Auditable;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipApplication extends Model
{
    use Auditable;

    public const REFERENCES_PENDING = 'references_pending';

    public const READY = 'ready';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::REFERENCES_PENDING => 'Referans teyidi bekleniyor',
        self::READY => 'Karar bekliyor',
        self::APPROVED => 'Kabul edildi',
        self::REJECTED => 'Reddedildi',
        self::WITHDRAWN => 'Geri çekildi',
    ];

    public const STATUS_COLORS = [
        self::REFERENCES_PENDING => 'yellow',
        self::READY => 'blue',
        self::APPROVED => 'green',
        self::REJECTED => 'red',
        self::WITHDRAWN => 'secondary',
    ];

    public const GENDERS = ['male' => 'Erkek', 'female' => 'Kadın'];

    public const DOCUMENT_TYPES = ['id_card' => 'Kimlik Kartı', 'passport' => 'Pasaport', 'other' => 'Diğer'];

    public const PHOTO_CHOICES = [
        'card' => 'Dijital fotoğrafımı göndereceğim. Etkinliklerde ve üye kartı basımında kullanımına izin veriyorum.',
        'events' => 'Dijital fotoğrafımı göndereceğim. Etkinliklerde kullanımına izin veriyorum. Üye kartı istemiyorum.',
        'none' => 'Dijital fotoğrafımı göndermeyeceğim. Üye kartı istemiyorum.',
    ];

    protected $fillable = ['membership_id', 'contact_id', 'user_id', 'reference_no', 'status', 'data', 'submitted_at'];

    protected $casts = [
        'data' => 'array',
        'submitted_at' => 'datetime',
        'signed_form_received_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(MembershipReference::class, 'application_id')->orderBy('position')->orderBy('id');
    }

    /**
     * References that count: not withdrawn or expired.
     */
    public function currentReferences(): HasMany
    {
        return $this->references()->whereIn('status', [MembershipReference::PENDING, MembershipReference::ACCEPTED, MembershipReference::DECLINED]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::REFERENCES_PENDING, self::READY], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function answer(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function auditLabel(): string
    {
        return $this->reference_no.' — '.($this->contact?->display_name ?? '');
    }
}
