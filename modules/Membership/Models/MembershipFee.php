<?php

namespace Modules\Membership\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Entry fee and yearly dues of one year.
 */
class MembershipFee extends Model
{
    use Auditable;

    protected $fillable = ['year', 'entry_fee', 'annual_fee', 'note'];

    protected $casts = [
        'year' => 'integer',
        'entry_fee' => 'decimal:2',
        'annual_fee' => 'decimal:2',
    ];

    /**
     * The fees of the year, or of the latest earlier year when that year
     * has none yet.
     */
    public static function forYear(int $year): ?self
    {
        return static::where('year', '<=', $year)->orderByDesc('year')->first();
    }

    /**
     * "1.250 TL", "150,50 TL"; null when not set.
     */
    public static function format(int|float|string|null $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $amount = (float) $amount;

        return number_format($amount, floor($amount) == $amount ? 0 : 2, ',', '.').' TL';
    }

    public function auditLabel(): string
    {
        return (string) $this->year;
    }
}
