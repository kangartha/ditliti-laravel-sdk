<?php

namespace Ditliti\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Database query auto-instrumentation for Laravel.
 *
 * `listen()` hooks Laravel's own `DB::listen` event - no query wrapping,
 * no ORM subclassing, works for the query builder, Eloquent, and raw
 * `DB::` calls alike. `reportQueryException()` covers the failure path
 * (`QueryExecuted` only fires for successful queries; Laravel wraps
 * driver errors in `QueryException` instead).
 *
 * The backend (ingestion-api) parameterizes and sanitizes `db.query.text`
 * server-side and derives `db.query.summary` / `db.operation.name` /
 * `db.collection.name` / `ditliti.query_hash` from it - this only needs
 * to supply the real SQL text, exactly like every other span already
 * sent by this SDK travels over the same trust boundary to the user's
 * own ingestion-api.
 */
final class DbTracing
{
    /**
     * Registers a `DB::listen` callback that records every executed query
     * as a `db.query` span. Call once during application boot (e.g. from
     * `DitlitiServiceProvider::boot()` when `config('ditliti.trace_queries')`
     * is enabled).
     */
    public static function listen(DitlitiClient $client, ?string $system = null): void
    {
        DB::listen(static function (QueryExecuted $query) use ($client, $system): void {
            try {
                $client->recordCompletedSpan('db.query', $query->time, [
                    'db.system.name' => $system ?? $query->connection->getDriverName(),
                    'db.query.text' => $query->sql,
                ]);
            } catch (\Throwable) {
                // Instrumentation must never break the application's query path.
            }
        });
    }

    /**
     * Records a failed query as a `db.query` span with `error.type` set.
     * Call from the app's exception handler when reporting a
     * `QueryException` (duration is unknown at this point - Laravel
     * doesn't attach timing to query exceptions - so `duration_ms` is 0).
     */
    public static function reportQueryException(DitlitiClient $client, QueryException $exception, ?string $system = null): void
    {
        try {
            $client->recordCompletedSpan('db.query', 0.0, [
                'db.system.name' => $system ?? 'unknown',
                'db.query.text' => $exception->getSql(),
                'error.type' => $exception::class,
            ]);
        } catch (\Throwable) {
            // Instrumentation must never break the application's error-reporting path.
        }
    }
}
