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
    /**
     * Namespace for this class's advisory locks, so they cannot collide with
     * any other advisory lock the application might take.
     */
    protected const LOCK_NAMESPACE = 21828;

    /**
     * Serialise sequence generation for the rest of the current transaction.
     *
     * `lockForUpdate()` locks the rows it reads — and before the first record
     * exists there are no rows to lock, so two simultaneous "first ever"
     * generations can compute the same number. The unique index stops a
     * duplicate being persisted, but one request then dies on a constraint
     * violation during what should be a routine write: on go-live day, of all
     * days.
     *
     * A transaction-level advisory lock closes that window. It needs no extra
     * infrastructure, and Postgres releases it when the transaction ends, so a
     * worker that dies mid-request cannot leave it held. A cache-based lock
     * would have made every transfer depend on the cache being healthy, which
     * is a worse failure than the one being fixed.
     *
     * SQLite (dev and tests) admits a single writer at a time, so there is no
     * equivalent gap to close there.
     */
    protected static function serialise(string $scope): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Cast explicitly: PDO can present bindings untyped, and Postgres would
        // then fail to resolve the int4 overload.
        DB::statement('SELECT pg_advisory_xact_lock(?::int, ?::int)', [
            self::LOCK_NAMESPACE,
            self::lockKeyFor($scope),
        ]);
    }

    /**
     * A stable 32-bit key per sequence, so two different sequences do not
     * queue behind one another. Seven hex digits keep it inside int4.
     */
    public static function lockKeyFor(string $scope): int
    {
        return (int) hexdec(substr(md5($scope), 0, 7));
    }

    public static function next(string $modelClass, string $column, string $prefix): string
    {
        /** @var Model $instance */
        $instance = new $modelClass;
        $year = (string) now()->year;
        $search = "{$prefix}-{$year}-";

        return DB::transaction(function () use ($instance, $column, $search, $prefix, $year) {
            self::serialise($instance->getTable().'.'.$column);

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
            self::serialise($instance->getTable().'.'.$column);

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
