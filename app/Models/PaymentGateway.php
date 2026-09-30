<?php

namespace App\Models;

use App\Contracts\Payments\CardGateway;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A card payment provider set up for the installation (driver + credentials).
 */
class PaymentGateway extends Model
{
    use Auditable;

    protected $fillable = ['driver', 'name', 'credentials', 'test_mode', 'purposes', 'is_active', 'sort'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'test_mode' => 'boolean',
        'purposes' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    /**
     * Active gateways offered for the purpose (gateways without purposes serve all).
     */
    public static function for(string $purpose)
    {
        return static::active()->get()
            ->filter(fn (self $gateway) => (empty($gateway->purposes) || in_array($purpose, $gateway->purposes, true)) && $gateway->driverClass())
            ->values();
    }

    public function driverClass(): ?string
    {
        return config('payments.drivers.'.$this->driver);
    }

    public function client(): CardGateway
    {
        $class = $this->driverClass() ?? throw new \RuntimeException("Unknown payment driver {$this->driver}");

        return new $class($this);
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
