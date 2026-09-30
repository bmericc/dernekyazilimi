<?php

namespace Modules\Dues\Models;

use App\Models\Concerns\Auditable;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member pays no yearly dues from from_year to until_year (open-ended when null).
 */
class DuesExemption extends Model
{
    use Auditable;

    protected $fillable = ['contact_id', 'from_year', 'until_year', 'reason', 'created_by'];

    protected $casts = ['from_year' => 'integer', 'until_year' => 'integer'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function scopeCovering(Builder $query, int $year): Builder
    {
        return $query->where('from_year', '<=', $year)->where(fn ($query) => $query->whereNull('until_year')->orWhere('until_year', '>=', $year));
    }

    public function period(): string
    {
        return $this->from_year.' – '.($this->until_year ?? '…');
    }

    public function auditLabel(): string
    {
        return $this->period().' '.$this->reason;
    }
}
