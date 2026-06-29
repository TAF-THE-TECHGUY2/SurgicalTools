<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Generates human-friendly, sequential document references such as
 * TR1-2026-000123. Sequence is per prefix + year and computed inside a
 * transaction to avoid collisions under concurrency.
 */
class ReferenceGenerator
{
    public static function next(string $modelClass, string $column, string $prefix): string
    {
        /** @var Model $instance */
        $instance = new $modelClass;
        $year = (string) now()->year;
        $search = "{$prefix}-{$year}-";

        return DB::transaction(function () use ($instance, $column, $search, $prefix, $year) {
            $last = $instance->newQuery()
                ->withTrashed()
                ->where($column, 'like', $search.'%')
                ->lockForUpdate()
                ->orderByDesc($column)
                ->value($column);

            $sequence = $last
                ? ((int) substr($last, strlen($search)) + 1)
                : 1;

            return sprintf('%s-%s-%06d', $prefix, $year, $sequence);
        });
    }
}
