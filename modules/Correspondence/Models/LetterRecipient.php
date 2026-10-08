<?php

namespace Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class LetterRecipient extends Model
{
    public const INSTITUTION = 'institution';

    public const LEGAL = 'legal';

    public const PERSON = 'person';

    public const KINDS = [
        self::INSTITUTION => 'Kamu kurumu',
        self::LEGAL => 'Tüzel kişi',
        self::PERSON => 'Gerçek kişi',
    ];

    /** What the identifier of each kind is. */
    public const IDENTIFIERS = [
        self::INSTITUTION => 'DETSİS no',
        self::LEGAL => 'MERSİS no',
        self::PERSON => 'T.C. kimlik no',
    ];

    public const ACTION = 'GRG';

    public const INFORMATION = 'BLG';

    public const DELIVERIES = [
        self::ACTION => 'Gereği',
        self::INFORMATION => 'Bilgi',
    ];

    protected $table = 'correspondence_recipients';

    protected $fillable = ['kind', 'name', 'identifier', 'address', 'delivery', 'sort'];
}
