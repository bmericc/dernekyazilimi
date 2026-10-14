<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A scheduled notice (birthday, reminder) sent to a person.
 */
class SentNotice extends Model
{
    public $timestamps = false;

    protected $fillable = ['contact_id', 'key', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];
}
