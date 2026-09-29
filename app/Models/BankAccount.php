<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use Auditable;

    protected $fillable = ['bank_name', 'branch', 'account_holder', 'iban', 'currency', 'purposes', 'is_active', 'sort'];

    protected $casts = [
        'purposes' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    /**
     * Active accounts shown for the purpose (accounts without purposes serve all).
     */
    public static function for(string $purpose)
    {
        return static::active()->get()->filter(fn (self $account) => empty($account->purposes) || in_array($purpose, $account->purposes, true))->values();
    }

    /**
     * "TR12 3456 ..." in groups of four.
     */
    public function formattedIban(): string
    {
        return trim(chunk_split($this->iban, 4, ' '));
    }

    public function auditLabel(): string
    {
        return $this->bank_name.' '.$this->formattedIban();
    }
}
