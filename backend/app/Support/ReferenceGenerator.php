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

    /**
     * A bare, continuously-increasing serial — no prefix, no year reset — for
     * numbers that carry on from a physical book. The delivery voucher pad
     * runs 130101, 130102, … and the digital vouchers must not collide with
     * pads still out in reps' cars, so the sequence starts at $seed.
     *
     * Cast to integer for the MAX so '99' sorts below '100': the column is a
     * string (it may be back-filled with legacy hand-written numbers) but the
     * ordering has to be numeric.
     */
    public static function nextSerial(string $modelClass, string $column, int $seed): string
    {
        /** @var Model $instance */
        $instance = new $modelClass;

        return DB::transaction(function () use ($instance, $column, $seed) {
            // Filtered in PHP rather than SQL: a numeric MAX over a string
            // column needs a cast that differs between pgsql and sqlite, and
            // any hand-entered value that isn't purely digits has to be
            // skipped rather than cast to 0.
            $highest = (int) $instance->newQuery()
                ->withTrashed()
                ->whereNotNull($column)
                ->lockForUpdate()
                ->get([$column])
                ->map(fn ($row) => (string) $row->{$column})
                ->filter(fn (string $value) => ctype_digit($value))
                ->map(fn (string $value) => (int) $value)
                ->max() ?? 0;

            return (string) max($seed, $highest + 1);
        });
    }
}
