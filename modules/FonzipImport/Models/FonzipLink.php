<?php

namespace Modules\FonzipImport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A Fonzip record (person, debt, payment, donation) and the portal record it
 * was imported into.
 */
class FonzipLink extends Model
{
    public const CONTACT = 'contact';

    public const CHARGE = 'charge';

    public const PAYMENT = 'payment';

    public const DONATION = 'donation';

    protected $fillable = ['kind', 'fonzip_id', 'linkable_type', 'linkable_id'];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Fonzip id => portal id of the kind's links.
     *
     * @return array<string, int>
     */
    public static function map(string $kind): array
    {
        return self::where('kind', $kind)->pluck('linkable_id', 'fonzip_id')->all();
    }

    public static function link(string $kind, string|int $fonzipId, Model $model): void
    {
        self::updateOrCreate(
            ['kind' => $kind, 'fonzip_id' => (string) $fonzipId],
            ['linkable_type' => $model->getMorphClass(), 'linkable_id' => $model->getKey()],
        );
    }
}
