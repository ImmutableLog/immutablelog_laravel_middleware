<?php

// ImmutableLog middleware for Laravel (10, 11, 12).
//
// Copy this file to app/Http/Middleware/ImmutableLogMiddleware.php and register it as a
// global middleware (see the README). Uses only ext-curl and ext-json; no Composer package.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ImmutableLogMiddleware
{
    private const DEFAULT_API_URL = 'https://api.immutablelog.com';
    private const DEFAULT_SKIP_PATHS = ['/health', '/healthz', '/up'];
    private const TIMEOUT_SECONDS = 5;
    private const MAX_ERROR_MESSAGE = 500;
    private const MAX_PATH = 2048;
    private const MAX_USER_AGENT = 512;
    private const MAX_TRAIL = 256;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->skip($request)) {
            $request->attributes->set('imtbl.started_at', microtime(true));
        }

        return $next($request);
    }

    /**
     * Runs after the response was sent to the client (under PHP-FPM, after
     * fastcgi_finish_request()), so the POST to ImmutableLog never delays it.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! $request->attributes->has('imtbl.started_at')) {
            return; // skipped (OPTIONS, health) or the request never reached handle()
        }

        try {
            $apiKey = (string) $this->setting('key', 'IMTBL_API_KEY', '');
            if ($apiKey === '') {
                return;
            }
            $event = $this->buildEvent($request, $response);
            $this->send(
                rtrim((string) $this->setting('url', 'IMTBL_URL', self::DEFAULT_API_URL), '/').'/v1/events',
                $apiKey,
                $event['request_id'],
                $event['body']
            );
        } catch (\Throwable $e) {
            Log::warning('[ImmutableLog] failed to send event: '.$e->getMessage());
        }
    }

    private function skip(Request $request): bool
    {
        if ($request->isMethod('OPTIONS')) {
            return true;
        }
        $skip = config('services.immutablelog.skip_paths', self::DEFAULT_SKIP_PATHS);

        return in_array('/'.ltrim($request->path(), '/'), (array) $skip, true);
    }

    /** config('services.immutablelog.<key>') first (works with config:cache), then the env var. */
    private function setting(string $key, string $envName, string $default): string
    {
        $value = config("services.immutablelog.$key");
        if ($value === null || $value === '') {
            $value = env($envName);
        }

        return ($value === null || $value === '') ? $default : (string) $value;
    }

    /** @return array{request_id: string, body: string} */
    private function buildEvent(Request $request, Response $response): array
    {
        $status = $response->getStatusCode();
        $kind = $status >= 400 ? 'error' : ($status >= 300 ? 'info' : ($status >= 200 ? 'success' : 'info'));
        $method = $request->getMethod();
        $path = mb_substr('/'.ltrim($request->path(), '/'), 0, self::MAX_PATH);
        $requestId = (string) Str::uuid();
        $latencyMs = (int) round((microtime(true) - (float) $request->attributes->get('imtbl.started_at')) * 1000);
        $clientIp = self::clientIp(
            $request->headers->get('X-Forwarded-For'),
            $request->headers->get('X-Real-IP'),
            $request->server('REMOTE_ADDR')
        );
        $service = $this->setting('service', 'IMTBL_SERVICE_NAME', 'laravel-service');
        $env = $this->setting('env', 'IMTBL_ENV', 'production');

        // Route template: "users/{id}" from the matched route; null on a 404.
        $route = $request->route();
        $httpRoute = ($route !== null && method_exists($route, 'uri')) ? '/'.ltrim($route->uri(), '/') : $path;

        $eventName = $request->attributes->get('imtbl.event_name') ?: "http.$method.$path";
        $trailSource = $request->attributes->get('imtbl.trail');
        if ($trailSource === null && config('services.immutablelog.trust_trail_header', false)) {
            $trailSource = $request->headers->get('X-Imtbl-Trail');
        }
        $trail = self::sanitizeTrail($trailSource);

        // Laravel attaches the exception to the response it renders for it.
        $exception = property_exists($response, 'exception') ? $response->exception : null;
        $errorType = $exception instanceof \Throwable ? get_class($exception) : null;
        $errorMessage = $exception instanceof \Throwable ? $exception->getMessage() : null;

        $payload = [
            'id' => (string) Str::uuid(),
            'kind' => $kind,
            'message' => "$method $path -> $status",
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'context' => [
                'ip' => $clientIp ?? 'unknown',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, self::MAX_USER_AGENT),
            ],
            'request' => ['request_id' => $requestId, 'method' => $method, 'path' => $path],
            'metrics' => ['latency_ms' => $latencyMs, 'status_code' => $status],
            'severity' => $kind === 'error' ? 'high' : 'low',
        ];
        if ($kind === 'error') {
            $payload['error'] = [
                'status_code' => $status,
                'retryable' => in_array($status, [408, 429, 500, 502, 503, 504], true),
            ];
            if ($errorType !== null) {
                $payload['error']['exception'] = $errorType;
                $payload['error']['exception_message'] = mb_substr((string) $errorMessage, 0, self::MAX_ERROR_MESSAGE);
            }
        }

        // meta is a string -> string map in the core: every value is cast with (string).
        // http_status goes as "200", never as the integer 200.
        $meta = [
            'type' => $kind,
            'event_type' => $kind,
            'event_name' => (string) $eventName,
            'service' => (string) $service,
            'request_id' => $requestId,
            // "env" is the legacy key; "environment" is the canonical one (CONTRACT.md §4.1).
            'env' => (string) $env,
            'environment' => (string) $env,
            'http_method' => $method,
            'http_status' => (string) $status,
            'http_route' => (string) $httpRoute,
            'log_level' => self::logLevel($kind),
        ];
        if ($clientIp !== null) {
            $meta['client_ip'] = $clientIp;
        }
        if ($errorType !== null) {
            $meta['error_type'] = $errorType;
            if ((string) $errorMessage !== '') {
                $meta['error_message'] = mb_substr((string) $errorMessage, 0, self::MAX_ERROR_MESSAGE);
            }
        }
        if ($trail !== null) {
            $meta['immutable_trail'] = $trail;
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
        $body = json_encode(['payload' => json_encode($payload, $flags), 'meta' => $meta], $flags);

        return ['request_id' => $requestId, 'body' => $body];
    }

    private function send(string $url, string $apiKey, string $idempotencyKey, string $body): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$apiKey,
                'Idempotency-Key: '.$idempotencyKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            // The send runs in terminate(), after the client got the response, but it still holds
            // the PHP-FPM worker until it finishes: keep the timeout short.
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => (int) ((float) config('services.immutablelog.timeout', self::TIMEOUT_SECONDS) * 1000),
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($result === false) {
            Log::warning('[ImmutableLog] failed to send event: '.curl_error($ch));
        } elseif ($status >= 400) {
            Log::warning("[ImmutableLog] event rejected: HTTP $status");
        }
        curl_close($ch);
    }

    /**
     * client_ip for the SIEM: first entry of X-Forwarded-For, then X-Real-IP, then REMOTE_ADDR.
     * Only a bare IP is returned (no port): the SIEM drops "ip:port" silently. The headers are
     * read directly, without TrustProxies; see "Proxy e X-Forwarded-For" in the README.
     */
    public static function clientIp(?string $xff, ?string $realIp, ?string $remoteAddr): ?string
    {
        $first = $xff !== null ? explode(',', $xff)[0] : null;

        return self::bareIp($first) ?? self::bareIp($realIp) ?? self::bareIp($remoteAddr);
    }

    /** "203.0.113.7", "203.0.113.7:4711", "[2001:db8::1]:8080", "2001:db8::1" -> bare IP. */
    private static function bareIp(?string $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if ($s[0] === '[') {
            $end = strpos($s, ']');
            $s = $end === false ? '' : substr($s, 1, $end - 1);
        } elseif (substr_count($s, ':') === 1) {
            $s = explode(':', $s)[0];
        }
        if (filter_var($s, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        // "::ffff:10.0.0.1" -> "10.0.0.1"
        if (stripos($s, '::ffff:') === 0 && filter_var(substr($s, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return substr($s, 7);
        }

        return $s;
    }

    /** The core rejects a trail that is empty, longer than 256 chars or contains ':'. */
    private static function sanitizeTrail(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $s = mb_substr(str_replace(':', '-', trim((string) $value)), 0, self::MAX_TRAIL);

        return $s === '' ? null : $s;
    }

    private static function logLevel(string $kind): string
    {
        return match ($kind) {
            'error' => 'error',
            'warning' => 'warn',
            'debug' => 'debug',
            default => 'info',
        };
    }
}
