<?php

namespace Modules\Correspondence\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterSequence;

/**
 * Gives a letter its number: the next one of the year, never reused and
 * never handed out twice.
 */
class Numbering
{
    public function __construct(private CorrespondenceSettings $settings)
    {
    }

    public function assign(Letter $letter, User $approver): Letter
    {
        return DB::transaction(function () use ($letter, $approver) {
            $year = now()->year;

            LetterSequence::firstOrCreate(['year' => $year], ['last_number' => $this->settings->startNumber() - 1]);
            $sequence = LetterSequence::where('year', $year)->lockForUpdate()->first();
            $sequence->increment('last_number');

            $letter->forceFill([
                'status' => Letter::NUMBERED,
                'number_year' => $year,
                'number' => $sequence->last_number,
                'document_no' => self::format($this->settings->numberFormat(), $year, $sequence->last_number, $letter->file_code, $this->settings->registryNumber()),
                'document_date' => today(),
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ])->save();

            return $letter;
        });
    }

    /**
     * A letter uploaded as a PDF keeps the number and date written on it; the
     * sequence of the year is not touched.
     */
    public function confirm(Letter $letter, User $approver): Letter
    {
        $letter->forceFill(['status' => Letter::NUMBERED, 'approved_by' => $approver->id, 'approved_at' => now()])->save();

        return $letter;
    }

    public static function format(string $format, int $year, int $number, ?string $fileCode = null, ?string $registryNumber = null): string
    {
        $text = preg_replace_callback(
            '/\{sira(?::(\d))?\}/',
            fn (array $match) => str_pad((string) $number, (int) ($match[1] ?? 0), '0', STR_PAD_LEFT),
            $format
        );

        return trim(strtr($text, ['{yil}' => (string) $year, '{kod}' => (string) $fileCode, '{kutuk}' => (string) $registryNumber]));
    }
}
