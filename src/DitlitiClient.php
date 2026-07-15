<?php

namespace Ditliti\Laravel;

use GuzzleHttp\Client;
use Throwable;

/**
 * A W3C trace-context (https://www.w3.org/TR/trace-context/) carried across
 * service boundaries via the `traceparent` header, so a trace started in
 * one process is continued - not restarted - in the next.
 */
final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly bool $sampled = true
    ) {
    }

    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function start(): self
    {
        return new self(self::generateTraceId(), self::generateSpanId());
    }

    public static function fromTraceparent(?string $header): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $parts = explode('-', trim($header));
        if (count($parts) !== 4) {
            return null;
        }

        [, $traceId, $spanId, $flags] = $parts;
        if (!preg_match('/^[0-9a-f]{32}$/', $traceId) || $traceId === str_repeat('0', 32)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{16}$/', $spanId) || $spanId === str_repeat('0', 16)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{2}$/', $flags)) {
            return null;
        }

        return new self($traceId, $spanId, (hexdec($flags) & 1) === 1);
    }

    public function child(): self
    {
        return new self($this->traceId, self::generateSpanId(), $this->sampled);
    }

    public function toTraceparent(): string
    {
        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $this->sampled ? '01' : '00');
    }
}

/**
 * Returned by DitlitiClient::startSpan(); call finish() when the operation
 * completes to record and send the span.
 */
final class Span
{
    private readonly float $startedAtMicrotime;

    public function __construct(
        private readonly DitlitiClient $client,
        private readonly string $operationName,
        private readonly ?string $parentSpanId,
        private readonly TraceContext $ctx,
        private readonly array $tags
    ) {
        $this->startedAtMicrotime = microtime(true);
    }

    public function getTraceId(): string
    {
        return $this->ctx->traceId;
    }

    public function getSpanId(): string
    {
        return $this->ctx->spanId;
    }

    public function getTraceparent(): string
    {
        return $this->ctx->toTraceparent();
    }

    public function finish(array $extraTags = []): void
    {
        $durationMs = (microtime(true) - $this->startedAtMicrotime) * 1000;
        $this->client->recordSpan(
            $this->ctx->traceId,
            $this->ctx->spanId,
            $this->parentSpanId,
            $this->operationName,
            $durationMs,
            array_merge($this->tags, $extraTags)
        );
    }
}

final class DitlitiClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $projectId,
        private readonly ?string $release = null,
        private readonly ?string $environment = null,
        private readonly ?array $user = null,
        private readonly ?string $sessionId = null,
        private readonly array $breadcrumbs = [],
        private readonly Client $http = new Client(['timeout' => 1.5]),
        private readonly ?TraceContext $trace = null
    ) {
    }

    /**
     * Start (or continue, if an inbound `traceparent` header is given) the
     * active trace. Since this client is immutable, this returns a NEW
     * client instance carrying the trace - e.g. in middleware:
     * `app()->instance(DitlitiClient::class, $client->withTrace($header));`
     * Downstream calls should read getTraceparent() on the returned
     * instance and forward it as the `traceparent` header of any outgoing
     * HTTP request to propagate the trace into the next service.
     */
    public function withTrace(?string $incomingTraceparent = null): self
    {
        $parent = TraceContext::fromTraceparent($incomingTraceparent);
        $trace = $parent !== null ? $parent->child() : TraceContext::start();

        return new self(
            $this->endpoint,
            $this->apiKey,
            $this->projectId,
            $this->release,
            $this->environment,
            $this->user,
            $this->sessionId,
            $this->breadcrumbs,
            $this->http,
            $trace
        );
    }

    public function getTraceparent(): ?string
    {
        return $this->trace?->toTraceparent();
    }

    /** Start a child span under the active trace (starting one implicitly if none is active yet). */
    public function startSpan(string $operationName, array $tags = []): Span
    {
        $trace = $this->trace ?? TraceContext::start();
        $parentSpanId = $trace->spanId;
        $ctx = $trace->child();

        return new Span($this, $operationName, $parentSpanId, $ctx, array_merge(['runtime' => 'php', 'framework' => 'laravel'], $tags));
    }

    public function recordSpan(string $traceId, string $spanId, ?string $parentSpanId, string $operationName, float $durationMs, array $tags = []): void
    {
        $baseTags = ['runtime' => 'php', 'framework' => 'laravel'];
        if ($this->environment !== null) {
            $baseTags['environment'] = $this->environment;
        }
        if ($this->release !== null) {
            $baseTags['release'] = $this->release;
        }
        $this->sendEnvelope([
            'spans' => [[
                'trace_id' => $traceId,
                'span_id' => $spanId,
                'parent_span_id' => $parentSpanId,
                'project_id' => $this->projectId,
                'operation_name' => $operationName,
                'start_time' => gmdate('c'),
                'duration_ms' => $durationMs,
                'tags' => array_merge($baseTags, $tags),
            ]],
        ]);
    }

    /**
     * Records a span whose duration is already known (e.g. from Laravel's
     * `DB::listen`, which reports a query's elapsed time only after it has
     * already completed) - unlike `startSpan()`, there's no separate
     * `finish()` call since the operation is already over by the time this
     * is invoked.
     */
    public function recordCompletedSpan(string $operationName, float $durationMs, array $tags = []): void
    {
        $trace = $this->trace ?? TraceContext::start();
        $parentSpanId = $trace->spanId;
        $ctx = $trace->child();
        $this->recordSpan($ctx->traceId, $ctx->spanId, $parentSpanId, $operationName, $durationMs, $tags);
    }

    private function currentTraceTags(): array
    {
        return $this->trace !== null ? ['trace_id' => $this->trace->traceId] : [];
    }

    public function report(Throwable $exception, array $context = [], array $options = []): void
    {
        $payload = [
            'project_id' => $this->projectId,
            'level' => 'error',
            'message' => $exception->getMessage(),
            'exception_type' => $exception::class,
            'stack_trace' => $this->stackFrames($exception),
            'tags' => array_merge(
                ['runtime' => 'php', 'framework' => 'laravel'],
                $this->currentTraceTags(),
                $options['tags'] ?? []
            ),
            'context' => $context,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'release' => $options['release'] ?? $this->release,
            'environment' => $options['environment'] ?? $this->environment,
            'transaction' => $options['transaction'] ?? null,
            'session_id' => $options['session_id'] ?? $this->sessionId,
            'breadcrumbs' => array_merge($this->breadcrumbs, $options['breadcrumbs'] ?? []),
            'user' => $options['user'] ?? $this->user,
        ];

        try {
            $this->http->post(rtrim($this->endpoint, '/') . '/api/v1/ingest', [
                'headers' => [
                    'content-type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'x-event-id' => $this->eventId($exception),
                ],
                'json' => $payload,
            ]);
        } catch (Throwable) {
            // Reporting must never break the host application response path.
        }
    }

    public function sendEnvelope(array $envelope): void
    {
        $payload = [
            'project_id' => $this->projectId,
            'events' => $envelope['events'] ?? [],
            'spans' => $envelope['spans'] ?? [],
            'logs' => $envelope['logs'] ?? [],
            'replays' => $envelope['replays'] ?? [],
            'profiles' => $envelope['profiles'] ?? [],
        ];

        try {
            $this->http->post(rtrim($this->endpoint, '/') . '/api/v1/envelope', [
                'headers' => [
                    'content-type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                ],
                'json' => $payload,
            ]);
        } catch (Throwable) {
            // Reporting must never break the host application response path.
        }
    }

    public function captureReplaySegment(float $durationMs, array $events, array $options = []): void
    {
        $sessionId = $options['session_id'] ?? $this->sessionId ?? null;
        if ($sessionId === null || $sessionId === '') {
            return;
        }

        $this->sendEnvelope([
            'replays' => [[
                'project_id' => $this->projectId,
                'session_id' => $sessionId,
                'started_at' => $options['started_at'] ?? gmdate('c'),
                'duration_ms' => $durationMs,
                'segment_index' => $options['segment_index'] ?? 0,
                'events' => $events,
                'tags' => array_merge(['runtime' => 'php', 'framework' => 'laravel'], $options['tags'] ?? []),
                'user' => $options['user'] ?? $this->user,
                'release' => $options['release'] ?? $this->release,
                'environment' => $options['environment'] ?? $this->environment,
            ]],
        ]);
    }

    public function captureProfile(int $durationMs, array $samples, array $options = []): void
    {
        $this->sendEnvelope([
            'profiles' => [[
                'project_id' => $this->projectId,
                'trace_id' => $options['trace_id'] ?? $this->trace?->traceId,
                'transaction' => $options['transaction'] ?? null,
                'started_at' => $options['started_at'] ?? gmdate('c'),
                'duration_ms' => $durationMs,
                'platform' => 'php',
                'samples' => $samples,
                'tags' => array_merge(['runtime' => 'php', 'framework' => 'laravel'], $options['tags'] ?? []),
                'release' => $options['release'] ?? $this->release,
                'environment' => $options['environment'] ?? $this->environment,
            ]],
        ]);
    }

    private function eventId(Throwable $exception): string
    {
        return hash('sha256', implode('|', [
            $this->projectId,
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            (string) $exception->getLine(),
        ]));
    }

    private function stackFrames(Throwable $exception): array
    {
        return array_map(
            static fn (array $frame): array => [
                'function' => $frame['function'] ?? null,
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'column' => null,
            ],
            $exception->getTrace()
        );
    }
}
