<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A collection (card, bank transfer, cash) for a module's payable.
 */
class Payment extends Model
{
    use Auditable;

    public const CARD = 'card';

    public const TRANSFER = 'transfer';

    public const CASH = 'cash';

    public const METHODS = [
        self::CARD => 'Kredi kartı',
        self::TRANSFER => 'Havale / EFT',
        self::CASH => 'Elden',
    ];

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    public const STATUSES = [
        self::PENDING => 'Bekliyor',
        self::SUCCEEDED => 'Tahsil edildi',
        self::FAILED => 'Başarısız',
        self::CANCELLED => 'İptal',
        self::REFUNDED => 'İade edildi',
    ];

    public const STATUS_COLORS = [
        self::PENDING => 'yellow',
        self::SUCCEEDED => 'green',
        self::FAILED => 'red',
        self::CANCELLED => 'secondary',
        self::REFUNDED => 'orange',
    ];

    protected $fillable = [
        'uuid', 'reference', 'purpose', 'contact_id', 'payer_name', 'payer_email', 'payer_phone',
        'amount', 'currency', 'method', 'status', 'bank_account_id', 'payment_gateway_id', 'return_url', 'ip', 'paid_at', 'recorded_by', 'note',
    ];

    protected $hidden = ['gateway_token', 'gateway_response'];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public function formattedAmount(): string
    {
        $amount = (float) $this->amount;

        return number_format($amount, floor($amount) == $amount ? 0 : 2, ',', '.').' '.($this->currency === 'TRY' ? 'TL' : $this->currency);
    }

    public function auditLabel(): string
    {
        return $this->reference.' '.$this->formattedAmount();
    }
}
