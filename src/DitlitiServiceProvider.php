<?php

namespace Ditliti\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class DitlitiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DitlitiClient::class, fn () => new DitlitiClient(
            config('ditliti.endpoint'),
            config('ditliti.api_key'),
            config('ditliti.project_id')
        ));
    }

    public function boot(DitlitiClient $client): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (Throwable $exception) use ($client): void {
                if (config('ditliti.trace_queries') && $exception instanceof \Illuminate\Database\QueryException) {
                    DbTracing::reportQueryException($client, $exception);
                }
                $client->report($exception, [
                    'environment' => app()->environment(),
                    'url' => request()?->fullUrl(),
                ]);
            });
        }

        // Opt-in: set DITLITI_TRACE_QUERIES=true (config/ditliti.php
        // `trace_queries`) to auto-instrument every query executed via
        // DB::listen as a `db.query` span for Database Insights.
        if (config('ditliti.trace_queries')) {
            DbTracing::listen($client);
        }
    }
}
