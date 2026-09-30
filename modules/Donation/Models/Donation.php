<?php

namespace Modules\Donation\Models;

use App\Models\Contact;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Donation extends Model
{
    protected $fillable = ['cause_id', 'contact_id', 'amount', 'donor_name', 'donor_email', 'donor_phone', 'hide_name', 'message', 'source', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'hide_name' => 'boolean'];

    public function payment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->latestOfMany();
    }

    public function cause(): BelongsTo
    {
        return $this->belongsTo(DonationCause::class, 'cause_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }
}
