<?php

namespace Modules\Dues\Models;

use App\Models\Concerns\Auditable;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Membership\Models\MembershipFee;

/**
 * Something a member owes: yearly dues, the entry fee or another charge.
 */
class DuesCharge extends Model
{
    use Auditable;

    public const ANNUAL = 'annual';

    public const ENTRY = 'entry';

    public const OTHER = 'other';

    public const KINDS = [
        self::ANNUAL => 'Yıllık aidat',
        self::ENTRY => 'Giriş aidatı',
        self::OTHER => 'Diğer',
    ];

    protected $fillable = ['contact_id', 'kind', 'year', 'amount', 'description', 'period_key', 'cancelled_at', 'cancel_note', 'created_by'];

    protected $casts = [
        'year' => 'integer',
        'amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    /** Set by DuesAccount: how much of the charge is paid. */
    public float $paid = 0;

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOwed(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * Oldest first: the order payments settle charges in.
     */
    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('year')->orderByRaw("case kind when 'entry' then 0 when 'annual' then 1 else 2 end")->orderBy('id');
    }

    public static function periodKey(string $kind, int $year): ?string
    {
        return $kind === self::OTHER ? null : $kind.'-'.$year;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function remaining(): float
    {
        return $this->isCancelled() ? 0 : round((float) $this->amount - $this->paid, 2);
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::ANNUAL => $this->year.' yıllık aidat',
            self::ENTRY => 'Giriş aidatı ('.$this->year.')',
            default => ($this->description ?: 'Diğer'),
        };
    }

    public function auditLabel(): string
    {
        return $this->label().' '.MembershipFee::format($this->amount);
    }
}
