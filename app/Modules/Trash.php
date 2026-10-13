<?php

namespace App\Modules;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Soft-deleted records the panel can bring back ("Silinenler"). No screen
 * deletes a record for good: models deleted from the panel use SoftDeletes
 * and are registered here by the core or by their module.
 */
class Trash
{
    /** @var array<string, class-string<Model>> */
    private array $types = [];

    /** @var array<string, string> */
    private array $labels = [];

    /**
     * @param  class-string<Model>  $model  a model using SoftDeletes
     */
    public function register(string $key, string $model, ?string $label = null): void
    {
        $this->types[$key] = $model;
        $this->labels[$key] = $label ?? Audit::subjectLabel((new $model())->getMorphClass());
    }

    /**
     * Deleted records by type, newest first: key => ['label', 'records'].
     */
    public function all(): array
    {
        $groups = [];
        foreach ($this->types as $key => $model) {
            $records = $model::onlyTrashed()->orderByDesc('deleted_at')->get();
            if ($records->isNotEmpty()) {
                $groups[$key] = ['label' => $this->labels[$key], 'records' => $records];
            }
        }

        return $groups;
    }

    public function find(string $key, int $id): ?Model
    {
        $model = $this->types[$key] ?? null;

        return $model ? $model::onlyTrashed()->find($id) : null;
    }

    public static function label(Model $record): string
    {
        return method_exists($record, 'auditLabel') ? (string) $record->auditLabel() : '#'.$record->getKey();
    }

    public function count(): int
    {
        return (int) Collection::make($this->types)->sum(fn (string $model) => $model::onlyTrashed()->count());
    }
}
