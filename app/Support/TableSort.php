<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Column sorting for admin tables paginated on the server: ?sort=<key>&dir=asc|desc.
 * Only the keys a controller lists are honoured; anything else falls back to the
 * table's default order. Headers link here through <x-sort-header>, filter forms
 * keep the sort with <x-sort-inputs />.
 *
 *     $query->tap(TableSort::by($request, ['amount' => 'amount'], fn ($query) => $query->latest('id')))
 */
class TableSort
{
    /**
     * The sort as a callback for Builder::tap().
     *
     * @param  array<string, string|Closure>  $columns
     */
    public static function by(Request $request, array $columns, Closure $default): Closure
    {
        return fn (Builder $query) => static::apply($query, $request, $columns, $default);
    }

    /**
     * @param  array<string, string|Closure>  $columns  key => column, or fn (Builder $query, string $direction)
     * @param  Closure  $default  fn (Builder $query): the order used when no valid key is asked for
     */
    public static function apply(Builder $query, Request $request, array $columns, Closure $default): Builder
    {
        $key = $request->query('sort');

        if (! is_string($key) || ! array_key_exists($key, $columns)) {
            $default($query);

            return $query;
        }

        $direction = static::direction($request);
        $column = $columns[$key];

        $column instanceof Closure ? $column($query, $direction) : $query->orderBy($column, $direction);

        // Equal values would otherwise shuffle between pages.
        return $query->orderBy($query->getModel()->getQualifiedKeyName(), $direction);
    }

    public static function direction(Request $request): string
    {
        return $request->query('dir') === 'desc' ? 'desc' : 'asc';
    }
}
