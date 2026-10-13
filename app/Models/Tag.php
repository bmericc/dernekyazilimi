<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tag extends Model
{
    use Auditable, SoftDeletes;

    /** Tabler colours offered for tags. */
    public const COLORS = ['blue', 'azure', 'indigo', 'purple', 'pink', 'red', 'orange', 'yellow', 'lime', 'green', 'teal', 'cyan', 'secondary'];

    protected $fillable = ['name', 'color'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)->withPivot('created_at');
    }

    public function auditLabel(): string
    {
        return (string) $this->name;
    }
}
