<?php

namespace Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class LetterSequence extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'correspondence_sequences';

    protected $primaryKey = 'year';

    protected $fillable = ['year', 'last_number'];
}
