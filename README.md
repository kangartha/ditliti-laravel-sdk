# ditliti/laravel-sdk

Official Laravel SDK for [Ditliti](https://github.com/kangartha/inspectora) - error tracking, tracing, session replay, and profiling ingestion.

This SDK talks to a **self-hosted** Ditliti instance (`ingestion-api`). It does not connect to any managed/hosted service - point it at your own deployment's ingestion URL.

## Install

```bash
mkdir -p .ditliti/packages
curl -fsSL https://github.com/kangartha/inspectora/releases/download/sdk-v0.1.2/ditliti-php-laravel-0.1.2.zip -o .ditliti/packages/ditliti-laravel-sdk.zip
composer config repositories.ditliti artifact "$(pwd)/.ditliti/packages"
composer require ditliti/laravel-sdk:0.1.2
```

The artifact repository is the canonical fallback until Packagist confirms publication.

`Ditliti\Laravel\DitlitiServiceProvider` auto-registers via Laravel package discovery.

## Configuration

Add to `config/ditliti.php` (or set directly via `.env` + `config()` calls):

```php
return [
    'endpoint' => env('DITLITI_ENDPOINT', 'http://localhost:8305'),
    'api_key' => env('DITLITI_API_KEY'),
    'project_id' => env('DITLITI_PROJECT_ID'),
];
```

Once registered, unhandled exceptions are reported automatically via
Laravel's exception handler `reportable()` hook.

## Manual usage

```php
use Ditliti\Laravel\DitlitiClient;

$client = app(DitlitiClient::class);

try {
    riskyOperation();
} catch (\Throwable $e) {
    $client->report($e, ['extra' => 'context']);
}
```

## API

- `DitlitiClient::report(Throwable $exception, array $context = [], array $options = [])`
- `DitlitiClient::sendEnvelope(array $envelope)` - low-level batch submit.
- `DitlitiClient::captureReplaySegment(float $durationMs, array $events, array $options = [])`
- `DitlitiClient::captureProfile(int $durationMs, array $samples, array $options = [])`
- `DitlitiClient::withTrace(?string $incomingTraceparent = null): self`, `getTraceparent()`, `startSpan(...)` - see Distributed tracing below.

## Distributed tracing

Generates real W3C `traceparent` (https://www.w3.org/TR/trace-context/) trace context, so a trace can be propagated across service/microservice boundaries instead of staying siloed per project. `DitlitiClient` is immutable (all constructor properties are `readonly`), so `withTrace()` returns a **new** client instance carrying the trace rather than mutating the existing one - in a middleware, rebind it into the container for the rest of the request:

```php
// app/Http/Middleware/ContinueDitlitiTrace.php
public function handle(Request $request, Closure $next)
{
    $client = app(DitlitiClient::class)->withTrace($request->header('traceparent'));
    app()->instance(DitlitiClient::class, $client);

    return $next($request);
}
```

```php
// Service A: start a trace and call Service B, forwarding the header.
$client = $client->withTrace();
Http::withHeaders(['traceparent' => $client->getTraceparent()])->get($serviceBUrl);

$span = $client->startSpan('db.query', ['db' => 'postgres']);
// ... do the work ...
$span->finish();

// report() automatically tags trace_id when a trace is active,
// so errors show up correlated to the trace at GET /api/v1/traces/:trace_id (org-wide).
```

- `$client->withTrace(?string $incomingTraceparent = null): self` - returns a new client that starts a new trace, or continues one from an inbound `traceparent` header.
- `$client->getTraceparent(): ?string` - the header value to forward on outgoing requests.
- `$client->startSpan(string $operationName, array $tags = []): Span` → `$span->finish(array $extraTags = [])`.

## Database query instrumentation (Database Insights)

Set `DITLITI_TRACE_QUERIES=true` (`config('ditliti.trace_queries')`) to auto-instrument every query - query builder, Eloquent, and raw `DB::` calls alike - via Laravel's own `DB::listen` hook, with no query wrapping required:

```php
// config/ditliti.php
return [
    // ...
    'trace_queries' => env('DITLITI_TRACE_QUERIES', false),
];
```

With this enabled, `DitlitiServiceProvider::boot()` calls `Ditliti\Laravel\DbTracing::listen($client)`, which records every executed query as a `db.query` span (`db.system.name` from the connection's driver, `db.query.text` the real SQL - the backend parameterizes and hashes it server-side). Failed queries surface via Laravel's `QueryException`, reported through the same `reportable()` hook as `error.type`.

To call these directly instead of via config (e.g. to scope it to one connection):

```php
use Ditliti\Laravel\DbTracing;

DbTracing::listen($client, system: 'postgresql');

// In your exception handler, alongside $client->report($exception, ...):
if ($exception instanceof \Illuminate\Database\QueryException) {
    DbTracing::reportQueryException($client, $exception, system: 'postgresql');
}
```

## License

MIT - see [LICENSE](https://github.com/kangartha/inspectora/blob/main/LICENSE).
