<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An IBAN with a valid checksum (ISO 13616); Turkish IBANs must have 26 characters.
 */
class Iban implements ValidationRule
{
    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $value));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = self::normalize($value);

        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) || (str_starts_with($iban, 'TR') && strlen($iban) !== 26)) {
            $fail('Geçerli bir IBAN girin.');

            return;
        }

        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        if ($remainder !== 1) {
            $fail('IBAN doğrulanamadı; lütfen kontrol edin.');
        }
    }
}
