<?php

namespace App\Support;

use BahriCanli\TcKimlik;

/**
 * Checks a Turkish identity number against the name, surname and birth
 * year (NVI, through the configured tckimlik service). Bound in the
 * container so tests can replace it.
 */
class IdentityCheck
{
    public function verify(string $identityNumber, string $name, string $surname, int $birthYear): bool
    {
        return (bool) TcKimlik::validate([
            'tcno' => $identityNumber,
            'isim' => $name,
            'soyisim' => $surname,
            'dogumyili' => $birthYear,
        ]);
    }
}
