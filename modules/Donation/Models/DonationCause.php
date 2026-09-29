<?php

namespace Modules\Donation\Models;

use App\Models\Concerns\Auditable;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DonationCause extends Model
{
    use Auditable;

    protected $fillable = ['name', 'description', 'target_amount', 'is_active', 'sort'];

    protected $casts = ['target_amount' => 'decimal:2', 'is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'cause_id');
    }

    /**
     * Collected so far.
     */
    public function collected(): float
    {
        return (float) Payment::where('purpose', 'donation')->where('status', Payment::SUCCEEDED)
            ->where('payable_type', (new Donation())->getMorphClass())
            ->whereIn('payable_id', $this->donations()->select('id'))->sum('amount');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
