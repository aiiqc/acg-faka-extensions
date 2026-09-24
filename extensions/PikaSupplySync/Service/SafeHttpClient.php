<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;

final class RetryableTransportFailure extends RuntimeException
{
    public function __construct(public readonly int $curlCode)
    {
        parent::__construct('远端 HTTPS 传输失败');
    }
}

final class SafeHttpClient
{
    private const CONNECT_TIMEOUT_MS = 5000;
    private const REQUEST_TIMEOUT_MS = 90000;
    private const DEFAULT_READ_IDLE_SECONDS = 30;
    private const CATALOG_READ_IDLE_SECONDS = 60;
    private const MAX_JSON_BYTES = 16777216;
    private const MAX_IMAGE_BYTES = 5242880;
    private const MAX_JSON_DEPTH = 32;
    private const MAX_JSON_NODES = 500000;
    private const MAX_ATTEMPTS = 3;
    private const RETRY_BACKOFF_MS = [500, 1000];
    private const MAX_RETRY_AFTER_MS = 300000;
    private const RETRYABLE_HTTP_STATUSES = [408, 429, 502, 503, 504];

    /** @var (\Closure(array,string,string,array,string,int,int,int): array)|null */
    private ?\Closure $transport;
    /** @var \Closure(int): void */
    private \Closure $sleep;
    private array $diagnostics = [];
    private array $diagnosticHeaderState = [];
    private ?int $diagnosticBodyBytes = null;
    private bool $diagnosticBodyStarted = false;
    private bool $diagnosticBodyComplete = false;
    private ?array $detailDiagnostics = null;
    private array $requestDiagnostics = [];
    private bool $requestEntered = false;

    public function resetRequestDiagnostics(): void
    {
        $this->requestDiagnostics = [];
    }

    public function requestDiagnostics(): array
    {
        return UpstreamFailure::sanitizeRequests($this->requestDiagnostics);
    }

    public function diagnostics(): array
    {
        return UpstreamFailure::sanitize($this->diagnostics);
    }

    public function detailDiagnostics(): ?array
    {
        return $this->detailDiagnostics;
    }

    public function clearDetailDiagnostics(): void
    {
        $this->detailDiagnostics = null;
    }

    public function __construct(
        private SourcePolicy $policy,
        ?callable $transport = null,
        private ?RunBudget $budget = null,
        ?callable $sleeper = null,
    ) {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
        $this->sleep = $sleeper === null
            ? static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            }
            : \Closure::fromCallable($sleeper);
    }

    /** @return array<string,mixed>|array<int,mixed> */
    public function postJson(string $url, array $headers, array $form): array
    {
        // Only these existing read-only detail routes tolerate an inaccurate MIME.
        $detail = in_array(parse_url($url, PHP_URL_PATH), [
            '/shared/commodity/item', '/plugin/open-api/item', '/plugin/SharedStock/api/item',
        ], true);
        $failure = null;
        $jsonValid = null;
        $effectiveMime = 'unknown';
        $this->detailDiagnostics = null;
        $this->requestEntered = false;
        $this->diagnostics = UpstreamFailure::sanitize(['stage' => $detail ? 'detail' : 'catalog', 'attempt_history' => []]);
        if ($detail) {
            $this->resetDiagnosticObservation();
        }
        try {
            $body = $this->formBody($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $response = $this->request(
                'POST',
                $url,
                $headers,
                $body,
                self::MAX_JSON_BYTES,
                false,
                $this->jsonReadIdleSeconds($url),
                false,
                $detail,
            );
            $effectiveMime = $this->mimeCategory($response['content_type']);
            $contentType = strtolower(trim(explode(';', $response['content_type'], 2)[0]));
            if (!$detail && !in_array($contentType, ['application/json', 'text/json'], true)) {
                throw new UpstreamFailure('content_type', $this->diagnostics());
            }
            try {
                $decoded = json_decode($response['body'], true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
                $jsonValid = true;
            } catch (\JsonException $exception) {
                $jsonValid = false;
                $diagnostics = $this->diagnostics();
                if ($detail) {
                    $diagnostics['json_error_code'] = $exception->getCode();
                    $diagnostics['json_error'] = UpstreamFailure::jsonErrorKind($exception->getCode());
                }
                throw new UpstreamFailure('json', $diagnostics);
            }
            if (!is_array($decoded)) {
                throw new UpstreamFailure('schema', $this->diagnostics());
            }
            $this->assertNodeLimit($decoded);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        if ($detail) {
            $safe = $failure instanceof UpstreamFailure ? $failure->diagnostics : $this->diagnostics();
            if ($safe['attempts'] > 0) {
                $mime = $this->diagnosticMime(self::diagnosticUnavailable()['mime'], $safe['http_status']);
                $mimeCategory = $mime['observed'] ? $mime['category'] : $effectiveMime;
                $mimeCount = $mime['observed'] && !$mime['counts_truncated'] ? $mime['content_type_count'] : null;
                $this->diagnostics = UpstreamFailure::sanitize(array_merge($safe, [
                    'category' => $failure === null ? 'none' : ($failure instanceof BudgetExceeded ? 'budget'
                        : ($failure instanceof UpstreamFailure ? $failure->diagnostics['category'] : 'unknown')),
                    'mime_category' => $mimeCategory,
                    'mime_count' => $mimeCount,
                    'json_valid' => $jsonValid,
                    // This is an observation, not a claim that downstream validation passed.
                    'mime_compatibility' => $mimeCategory !== 'unknown'
                        && (!in_array($mimeCategory, ['application_json', 'text_json'], true)
                            || ($mimeCount !== null && $mimeCount !== 1)),
                ]));
                $this->detailDiagnostics = $this->diagnostics();
            }
        }
        $this->finishRequest($failure);
        if ($failure instanceof UpstreamFailure && $detail) {
            throw new UpstreamFailure($failure->diagnostics['category'], $this->diagnostics());
        }
        if ($failure instanceof BudgetExceeded && $detail) {
            throw new BudgetExceeded($failure->scope, '请求剩余预算不足', $this->diagnostics());
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $decoded;
    }

    /** A fixed, value-free result also used for rejected standalone input. */
    public static function diagnosticUnavailable(): array
    {
        return [
            'schema_version' => 1,
            'diagnostic_only' => true,
            'category' => 'preflight',
            'http_status' => 0, 'curl_code' => 0, 'elapsed_ms' => 0, 'attempts' => 0,
            'mime' => [
                'observed' => false, 'response_blocks' => 0, 'final_status' => 0,
                'final_headers_complete' => false, 'final_header_count' => 0,
                'content_type_present' => null, 'content_type_count' => 0,
                'category' => 'unknown', 'effective_category' => 'unknown',
                'counts_truncated' => false,
            ],
            'body_bytes' => null, 'body_complete' => false,
            'json_valid' => null, 'json_error' => 'unobserved', 'top_level' => 'unobserved',
            'within_json_limits' => null, 'business_success' => null,
            'shape' => array_fill_keys(
                ['code', 'data', 'detail', 'children', 'name', 'stock', 'config', 'sku'],
                'unobserved',
            ),
        ];
    }

    /**
     * Observe one detail response without interpreting it as importable data.
     * No raw response, request value, or exception text may leave this method.
     */
    public function diagnosePostJson(string $url, array $headers, array $form, int $sourceType): array
    {
        $report = self::diagnosticUnavailable();
        $started = hrtime(true);
        $this->diagnostics = UpstreamFailure::sanitize([]);
        $this->diagnosticHeaderState = [];
        $this->diagnosticBodyBytes = null;
        $this->diagnosticBodyStarted = false;
        $this->diagnosticBodyComplete = false;
        try {
            $detailPaths = [0 => '/shared/commodity/item', 1 => '/plugin/open-api/item', 2 => '/plugin/SharedStock/api/item'];
            if (!isset($detailPaths[$sourceType]) || parse_url($url, PHP_URL_PATH) !== $detailPaths[$sourceType]) {
                return $report;
            }
            $body = $this->formBody($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $response = $this->request(
                'POST', $url, $headers, $body, self::MAX_JSON_BYTES, false,
                self::DEFAULT_READ_IDLE_SECONDS, true,
            );
            $report['category'] = $response['status'] === 200 ? 'none'
                : (in_array($response['status'], self::RETRYABLE_HTTP_STATUSES, true)
                    ? 'http_retryable' : 'http_rejected');
            $report['mime']['effective_category'] = $this->mimeCategory($response['content_type']);
            $contentType = strtolower(trim(explode(';', $response['content_type'], 2)[0]));
            if ($report['category'] === 'none'
                && !in_array($contentType, ['application/json', 'text/json'], true)) {
                $report['category'] = 'content_type';
            }
            // Strict decoding is diagnostic only, even when MIME would reject import.
            try {
                $decoded = json_decode($response['body'], false, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
                $report['json_valid'] = true;
                $report['json_error'] = 'none';
                $report['top_level'] = $this->diagnosticValueType($decoded);
                $report['within_json_limits'] = true;
                try {
                    if (is_array($decoded) || $decoded instanceof \stdClass) {
                        $this->assertNodeLimit($decoded);
                    }
                } catch (UpstreamFailure) {
                    $report['within_json_limits'] = false;
                    $report['json_error'] = 'nodes';
                    if ($report['category'] === 'none') {
                        $report['category'] = 'schema';
                    }
                }
                if ($report['within_json_limits']) {
                    $this->describeDiagnosticShape($report, $decoded, $sourceType);
                }
            } catch (\JsonException $failure) {
                $report['json_valid'] = false;
                $report['within_json_limits'] = false;
                $report['json_error'] = match ($failure->getCode()) {
                    JSON_ERROR_UTF8 => 'utf8',
                    JSON_ERROR_DEPTH => 'depth',
                    default => 'syntax',
                };
                if ($report['category'] === 'none') {
                    $report['category'] = 'json';
                }
            }
        } catch (\Throwable $failure) {
            $safe = $failure instanceof UpstreamFailure ? $failure->diagnostics : $this->diagnostics();
            $report['category'] = $failure instanceof BudgetExceeded ? 'budget'
                : ($safe['attempts'] === 0 ? 'preflight' : $safe['category']);
            if ($report['category'] === 'response_size') {
                $report['within_json_limits'] = false;
            }
        }
        $safe = $this->diagnostics();
        foreach (['http_status', 'curl_code', 'attempts'] as $key) {
            $report[$key] = $safe[$key];
        }
        $report['elapsed_ms'] = min(480000, (int)((hrtime(true) - $started) / 1000000));
        $report['body_bytes'] = $this->diagnosticBodyBytes;
        $report['body_complete'] = $this->diagnosticBodyComplete;
        $report['mime'] = $this->diagnosticMime($report['mime'], $report['http_status']);
        return $report;
    }

    /** @return array{body:string,content_type:string} */
    public function getImage(string $url): array
    {
        $failure = null;
        try {
            $response = $this->request(
                'GET',
                $url,
                ['Accept: image/*'],
                '',
                self::MAX_IMAGE_BYTES,
                true,
                self::DEFAULT_READ_IDLE_SECONDS,
            );
            if ((string)$response['body'] === '') {
                throw new RemoteCoverUnavailable('远端图片为空');
            }
            return ['body' => (string)$response['body'], 'content_type' => (string)$response['content_type']];
        } catch (\Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            $this->finishRequest($failure);
        }
    }

    /** @return array{status:int,content_type:string,body:string,connected_ip:string} */
    private function request(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $maxBytes,
        bool $allowQuery,
        int $readIdleSeconds,
        bool $diagnostic = false,
        bool $observeDetail = false,
    ): array {
        $started = hrtime(true);
        $this->requestEntered = true;
        $this->diagnostics = UpstreamFailure::sanitize([
            'stage' => $allowQuery ? 'image' : ($observeDetail || $diagnostic ? 'detail' : 'catalog'),
            'attempt_history' => [],
        ]);
        try {
            $this->budget?->remainingMilliseconds();
            $endpoint = $this->policy->resolve($url, false, $allowQuery);
            $headers = $this->headers($headers);
            $addresses = $endpoint['addresses'];
            $maxAttempts = $diagnostic ? 1 : self::MAX_ATTEMPTS;
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                [$connectTimeoutMs, $requestTimeoutMs] = $this->attemptTimeouts();
                if ($diagnostic || $observeDetail) {
                    $this->resetDiagnosticObservation();
                }
                $address = $addresses[$attempt % count($addresses)];
                $retryAfterMs = 0;
                $attemptStarted = hrtime(true);
                $previousAttempts = $this->diagnostics['attempts'];
                $attemptCategory = 'unknown';
                $remainingBefore = $this->remainingObservation();
                try {
                    $this->diagnostics['http_status'] = 0;
                    $this->diagnostics['curl_code'] = 0;
                    if ($this->transport !== null) {
                        $this->diagnostics['attempts']++;
                    }
                    $response = $this->transport === null
                        ? $this->curl(
                            $endpoint,
                            $address,
                            $method,
                            $headers,
                            $body,
                            $maxBytes,
                            $connectTimeoutMs,
                            $requestTimeoutMs,
                            $readIdleSeconds,
                            $diagnostic || $observeDetail,
                        )
                        : ($this->transport)(
                            $endpoint,
                            $address,
                            $method,
                            $headers,
                            $body,
                            $maxBytes,
                            $connectTimeoutMs,
                            $requestTimeoutMs,
                        );
                    if (($diagnostic || $observeDetail) && is_string($response['body'] ?? null)) {
                        $this->diagnosticBodyBytes = strlen($response['body']);
                    }
                    $response = $this->validateResponse($response, $address, $maxBytes);
                    $this->diagnostics['http_status'] = $response['status'];
                    if ($diagnostic || $observeDetail) {
                        $this->diagnosticBodyComplete = true;
                    }
                    if ($response['status'] !== 200 && !$diagnostic) {
                        if (in_array($response['status'], self::RETRYABLE_HTTP_STATUSES, true)) {
                            $retryAfterMs = $response['retry_after_ms'];
                            throw new UpstreamFailure('http_retryable');
                        }
                        throw new UpstreamFailure('http_rejected');
                    }
                    $attemptCategory = 'none';
                    $this->budget?->checkpoint();
                    $this->diagnostics['elapsed_ms'] = (int)((hrtime(true) - $started) / 1000000);
                    return $response;
                } catch (BudgetExceeded $exception) {
                    throw $exception;
                } catch (RetryableTransportFailure $exception) {
                    $this->diagnostics['curl_code'] = $exception->curlCode;
                    $category = $this->isRetryableCurlError($exception->curlCode) ? 'transport' : 'unknown';
                    $attemptCategory = $category;
                } catch (UpstreamFailure $exception) {
                    $category = $exception->diagnostics['category'];
                    $attemptCategory = $category;
                } catch (\Throwable $exception) {
                    unset($exception);
                    $category = 'unknown';
                } finally {
                    $this->recordAttempt($previousAttempts, $attemptStarted, $attemptCategory,
                        $connectTimeoutMs, $requestTimeoutMs, $remainingBefore);
                }
                $this->diagnostics['category'] = $category;
                $this->budget?->checkpoint();
                if (!in_array($category, ['transport', 'http_retryable'], true) || $attempt + 1 >= $maxAttempts) {
                    throw new UpstreamFailure($category, $this->diagnostics());
                }
                $this->backoff($attempt, $retryAfterMs);
            }
        } catch (BudgetExceeded $exception) {
            $this->diagnostics['elapsed_ms'] = (int)((hrtime(true) - $started) / 1000000);
            $this->diagnostics['remaining_budget'] = $this->remainingObservation();
            throw new BudgetExceeded($exception->scope, '请求剩余预算不足', $this->diagnostics());
        } catch (\Throwable $exception) {
            $this->diagnostics['elapsed_ms'] = (int)((hrtime(true) - $started) / 1000000);
            $category = $exception instanceof UpstreamFailure ? $exception->diagnostics['category'] : 'unknown';
            throw new UpstreamFailure($category, $this->diagnostics());
        }
        throw new UpstreamFailure('unknown', $this->diagnostics());
    }

    private function remainingObservation(): array
    {
        try {
            return $this->budget?->diagnosticRemaining() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function recordAttempt(int $previousAttempts, int $started, string $category,
        int $connectTimeoutMs, int $requestTimeoutMs, array $remainingBefore): void
    {
        try {
            // Setup failure is not a transport attempt. No retry/budget decisions here.
            if ($this->diagnostics['attempts'] <= $previousAttempts
                || count($this->diagnostics['attempt_history']) >= self::MAX_ATTEMPTS) return;
            $this->diagnostics['attempt_history'][] = [
                'category' => $category, 'http_status' => $this->diagnostics['http_status'],
                'curl_code' => $this->diagnostics['curl_code'],
                'elapsed_ms' => (int)((hrtime(true) - $started) / 1000000),
                'connect_timeout_ms' => $connectTimeoutMs, 'request_timeout_ms' => $requestTimeoutMs,
                'remaining_before' => $remainingBefore,
            ];
        } catch (\Throwable) {
            // Observation must never replace the request's original outcome.
        }
    }

    private function finishRequest(?\Throwable $failure): void
    {
        try {
            $category = $failure === null ? 'none' : ($failure instanceof BudgetExceeded ? 'budget'
                : ($failure instanceof UpstreamFailure ? $failure->diagnostics['category'] : 'unknown'));
            $this->diagnostics['remaining_budget'] = $this->remainingObservation();
            $observation = array_replace($this->diagnostics, ['category' => $category]);
            if (!$this->requestEntered) unset($observation['elapsed_ms']);
            $safe = UpstreamFailure::sanitizeObservation($observation);
            $stage = $safe['stage'];
            $summary = $this->requestDiagnostics[$stage] ?? ['count' => 0];
            $summary['count'] = min(10000, $summary['count'] + 1);
            $summary['last'] = $safe;
            if ($failure !== null) $summary['last_failure'] = $safe;
            $this->requestDiagnostics[$stage] = $summary;
        } catch (\Throwable) {
            // Keep bounded best-effort evidence out of the business control flow.
        }
    }

    /** @return array{status:int,content_type:string,body:string,connected_ip:string} */
    private function curl(
        array $endpoint,
        string $address,
        string $method,
        array $headers,
        string $body,
        int $maxBytes,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
        int $readIdleSeconds,
        bool $diagnostic = false,
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl 扩展不可用');
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('无法初始化 HTTPS 客户端');
        }
        $received = '';
        $tooLarge = false;
        $retryAfterMs = 0;
        $resolveAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $resolveHost = str_contains($endpoint['host'], ':')
            ? '[' . $endpoint['host'] . ']'
            : $endpoint['host'];
        $resolve = $resolveHost . ':443:' . $resolveAddress;
        $options = [
            CURLOPT_URL => $endpoint['url'],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $method === 'POST' ? $body : null,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$retryAfterMs, $diagnostic): int {
                $retryAfterMs = $this->retryAfterHeaderMilliseconds($header, $retryAfterMs);
                if ($diagnostic) {
                    $this->recordDiagnosticHeader($header);
                }
                return strlen($header);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $requestTimeoutMs,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => max(
                1,
                min($readIdleSeconds, (int)ceil($requestTimeoutMs / 1000)),
            ),
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_PROXY => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'PikaSupplySync/1.0',
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$received, &$tooLarge, $maxBytes, $diagnostic): int {
                if ($diagnostic) {
                    $this->diagnosticBodyStarted = true;
                    $this->diagnosticBodyBytes = strlen($received) + strlen($chunk);
                }
                if (strlen($received) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $received .= $chunk;
                return strlen($chunk);
            },
        ];
        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('无法配置 HTTPS 客户端');
            }
            $this->diagnostics['attempts']++;
            $completed = curl_exec($handle);
            $this->diagnostics['http_status'] = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $this->diagnostics['curl_code'] = curl_errno($handle);
            if ($completed !== true) {
                if ($tooLarge) {
                    throw new UpstreamFailure('response_size');
                }
                if ($this->isRetryableCurlError(curl_errno($handle))) {
                    throw new RetryableTransportFailure(curl_errno($handle));
                }
                $this->diagnostics['curl_code'] = curl_errno($handle);
                throw new UpstreamFailure('unknown');
            }
            return [
                'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'content_type' => (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
                'body' => $received,
                'connected_ip' => (string)curl_getinfo($handle, CURLINFO_PRIMARY_IP),
                'retry_after_ms' => $retryAfterMs,
            ];
        } finally {
            curl_close($handle);
        }
    }

    private function backoff(int $attempt, int $retryAfterMs): void
    {
        $milliseconds = self::RETRY_BACKOFF_MS[$attempt] ?? null;
        if (!is_int($milliseconds) || $milliseconds < 1) {
            throw new RuntimeException('远端 HTTPS 请求失败');
        }
        $milliseconds = max($milliseconds, $retryAfterMs);
        if ($this->budget !== null) {
            // Reserve at least one millisecond for the next real attempt.
            $this->budget->remainingMilliseconds($milliseconds + 1);
        } elseif ($milliseconds > self::RETRY_BACKOFF_MS[$attempt]) {
            // Without a run budget, do not add a long supplier-directed wait.
            throw new UpstreamFailure('budget');
        }
        try {
            ($this->sleep)($milliseconds);
        } catch (\Throwable $exception) {
            unset($exception);
            throw new RuntimeException('远端 HTTPS 请求失败');
        }
        if ($this->budget !== null) {
            $this->budget->checkpoint();
        }
    }

    private function retryAfterHeaderMilliseconds(string $header, int $current): int
    {
        if (preg_match('/^HTTP\//i', $header)) {
            return 0;
        }
        if (strncasecmp($header, 'Retry-After:', 12) === 0) {
            // Oversized supplier delays must stop within budget, never be ignored.
            return max($current, strlen($header) > 256 ? self::MAX_RETRY_AFTER_MS
                : $this->retryAfterMilliseconds(trim(substr($header, 12), " \t\r\n")));
        }
        return $current;
    }

    private function retryAfterMilliseconds(string $value): int
    {
        if (preg_match('/^[0-9]+$/D', $value)) {
            return (int)(min(self::MAX_RETRY_AFTER_MS / 1000, (float)$value) * 1000);
        }
        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('GMT'));
        if ($date === false || $date->format('D, d M Y H:i:s \G\M\T') !== $value) {
            return 0;
        }
        return (int)min(self::MAX_RETRY_AFTER_MS, max(0, $date->getTimestamp() - time()) * 1000);
    }

    private function isRetryableCurlError(int $error): bool
    {
        foreach ([
            'CURLE_COULDNT_RESOLVE_HOST',
            'CURLE_COULDNT_CONNECT',
            'CURLE_OPERATION_TIMEDOUT',
            'CURLE_PARTIAL_FILE',
            'CURLE_HTTP2',
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_SEND_ERROR',
            'CURLE_RECV_ERROR',
            'CURLE_GOT_NOTHING',
            'CURLE_AGAIN',
            'CURLE_HTTP2_STREAM',
        ] as $name) {
            if (defined($name) && constant($name) === $error) {
                return true;
            }
        }
        return false;
    }

    private function jsonReadIdleSeconds(string $url): int
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && in_array($path, [
            '/shared/commodity/items',
            '/plugin/SharedStock/api/items',
            '/plugin/open-api/items',
        ], true)) {
            return self::CATALOG_READ_IDLE_SECONDS;
        }
        return self::DEFAULT_READ_IDLE_SECONDS;
    }

    /** @return array{0:int,1:int} */
    private function attemptTimeouts(): array
    {
        if ($this->budget === null) {
            return [self::CONNECT_TIMEOUT_MS, self::REQUEST_TIMEOUT_MS];
        }
        $remainingMs = $this->budget->remainingMilliseconds();
        return [
            min(self::CONNECT_TIMEOUT_MS, $remainingMs),
            min(self::REQUEST_TIMEOUT_MS, $remainingMs),
        ];
    }

    /** @return array{status:int,content_type:string,body:string,connected_ip:string} */
    private function validateResponse(mixed $response, string $expectedIp, int $maxBytes): array
    {
        if (!is_array($response)) {
            throw new UpstreamFailure('schema');
        }
        $status = $response['status'] ?? null;
        $contentType = $response['content_type'] ?? null;
        $body = $response['body'] ?? null;
        $connectedIp = $response['connected_ip'] ?? null;
        if (!is_int($status) || $status < 100 || $status > 599 || !is_string($contentType) || !is_string($body) || !is_string($connectedIp)) {
            throw new UpstreamFailure('schema');
        }
        if (strlen($body) > $maxBytes) {
            throw new UpstreamFailure('response_size');
        }
        SourcePolicy::assertPublicIp($connectedIp);
        if (!hash_equals(SourcePolicy::normalizeIp($expectedIp), SourcePolicy::normalizeIp($connectedIp))) {
            throw new RuntimeException('实际连接地址与已验证 DNS 地址不一致');
        }
        return [
            'status' => $status,
            'content_type' => $contentType,
            'body' => $body,
            'connected_ip' => $connectedIp,
            'retry_after_ms' => is_int($response['retry_after_ms'] ?? null)
                ? max(0, min(self::MAX_RETRY_AFTER_MS, $response['retry_after_ms']))
                : $this->retryAfterMilliseconds(is_string($response['retry_after'] ?? null) ? $response['retry_after'] : ''),
        ];
    }

    private function formBody(array $form): string
    {
        if (count($form) > 64) {
            throw new RuntimeException('远端请求参数过多');
        }
        foreach ($form as $key => $value) {
            if (
                !is_string($key)
                || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $key) !== 1
                || (!is_scalar($value) && $value !== null)
                || strlen((string)$value) > 4096
            ) {
                throw new RuntimeException('远端请求参数格式不正确');
            }
        }
        $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        if (strlen($body) > 65536) {
            throw new RuntimeException('远端请求参数超过大小上限');
        }
        return $body;
    }

    /** @return string[] */
    private function headers(array $headers): array
    {
        if (count($headers) > 32) {
            throw new RuntimeException('远端请求头过多');
        }
        $clean = [];
        foreach ($headers as $header) {
            if (!is_string($header) || strlen($header) > 8192 || preg_match('/[\r\n]/', $header)) {
                throw new RuntimeException('远端请求头格式不正确');
            }
            $clean[] = $header;
        }
        return $clean;
    }

    private function resetDiagnosticObservation(): void
    {
        $this->diagnosticHeaderState = [];
        $this->diagnosticBodyBytes = null;
        $this->diagnosticBodyStarted = false;
        $this->diagnosticBodyComplete = false;
    }

    /** Reduce each line immediately; never keep arbitrary header names or values. */
    private function recordDiagnosticHeader(string $header): void
    {
        if ($this->diagnosticBodyStarted) {
            return; // Trailers are not the final response's Content-Type.
        }
        $line = rtrim($header, "\r\n");
        if (preg_match('/^HTTP\/[0-9.]+[ \t]+([1-5][0-9]{2})(?:[ \t]|$)/D', $line, $match)) {
            $blocks = ($this->diagnosticHeaderState['blocks'] ?? 0) + 1;
            $this->diagnosticHeaderState = [
                'blocks' => min(65535, $blocks), 'status' => (int)$match[1],
                'in_headers' => true, 'complete' => false, 'headers' => 0,
                'mime_count' => 0, 'classes' => [], 'last_mime' => false,
                'truncated' => $blocks > 65535 || ($this->diagnosticHeaderState['truncated'] ?? false),
            ];
            return;
        }
        if (!($this->diagnosticHeaderState['in_headers'] ?? false)) {
            return;
        }
        if ($line === '') {
            $this->diagnosticHeaderState['in_headers'] = false;
            $this->diagnosticHeaderState['complete'] = true;
            return;
        }
        // Older libcurl versions may deliver folded lines separately.
        if ($line[0] === ' ' || $line[0] === "\t") {
            if ($this->diagnosticHeaderState['last_mime']) {
                $this->diagnosticHeaderState['classes']['malformed'] = true;
            }
            return;
        }
        if ($this->diagnosticHeaderState['headers'] < 65535) {
            $this->diagnosticHeaderState['headers']++;
        } else {
            $this->diagnosticHeaderState['truncated'] = true;
        }
        $colon = strpos($line, ':');
        $name = $colon === false ? '' : substr($line, 0, $colon);
        $this->diagnosticHeaderState['last_mime'] = strcasecmp(trim($name), 'Content-Type') === 0;
        if (!$this->diagnosticHeaderState['last_mime']) {
            return;
        }
        if ($this->diagnosticHeaderState['mime_count'] < 65535) {
            $this->diagnosticHeaderState['mime_count']++;
        } else {
            $this->diagnosticHeaderState['truncated'] = true;
        }
        $class = $name !== trim($name) ? 'malformed' : $this->mimeCategory(substr($line, $colon + 1));
        $this->diagnosticHeaderState['classes'][$class] = true;
    }

    private function mimeCategory(string $value): string
    {
        $mime = strtolower(trim(explode(';', $value, 2)[0]));
        if ($mime === '') {
            return 'empty';
        }
        if (preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/D', $mime) !== 1) {
            return 'malformed';
        }
        return match ($mime) {
            'application/json' => 'application_json',
            'text/json' => 'text_json',
            'text/html' => 'text_html',
            'text/plain' => 'text_plain',
            default => str_starts_with($mime, 'application/') && str_ends_with($mime, '+json')
                ? 'json_suffix' : 'other',
        };
    }

    private function diagnosticMime(array $mime, int $httpStatus): array
    {
        $state = $this->diagnosticHeaderState;
        if ($state === []) {
            return $mime;
        }
        $mime['response_blocks'] = $state['blocks'];
        $mime['counts_truncated'] = $state['truncated'];
        // Do not claim an interim or incomplete header block is the final one.
        if ($state['status'] < 200 || $state['status'] !== $httpStatus || !$state['complete']) {
            return $mime;
        }
        $mime['observed'] = true;
        $mime['final_status'] = $state['status'];
        $mime['final_headers_complete'] = true;
        $mime['final_header_count'] = $state['headers'];
        $mime['content_type_present'] = $state['mime_count'] > 0;
        $mime['content_type_count'] = $state['mime_count'];
        $classes = array_keys($state['classes']);
        $mime['category'] = in_array('malformed', $classes, true) ? 'malformed'
            : (count($classes) > 1 ? 'conflicting' : ($classes[0] ?? 'missing'));
        return $mime;
    }

    private function diagnosticValueType(mixed $value): string
    {
        return match (true) {
            $value instanceof \stdClass => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => 'unobserved',
        };
    }

    /** Inspect only fixed field names and types, never contents or arbitrary keys. */
    private function describeDiagnosticShape(array &$report, mixed $decoded, int $sourceType): void
    {
        $envelope = $decoded instanceof \stdClass ? get_object_vars($decoded) : [];
        foreach (['code', 'data'] as $key) {
            $report['shape'][$key] = array_key_exists($key, $envelope)
                ? $this->diagnosticValueType($envelope[$key]) : 'missing';
        }
        if (array_key_exists('code', $envelope)) {
            $code = $envelope['code'];
            $report['business_success'] = (is_int($code) || is_float($code)
                || (is_string($code) && is_numeric($code))) && (int)$code === 200;
        }
        $data = $envelope['data'] ?? null;
        $detail = $data;
        if ($sourceType === 2) {
            $category = is_array($data) ? ($data[0] ?? null) : null;
            $children = $category instanceof \stdClass ? get_object_vars($category) : [];
            $report['shape']['children'] = array_key_exists('children', $children)
                ? $this->diagnosticValueType($children['children']) : 'missing';
            $detail = is_array($children['children'] ?? null) ? ($children['children'][0] ?? null) : null;
        }
        $report['shape']['detail'] = $this->diagnosticValueType($detail);
        $fields = $detail instanceof \stdClass ? get_object_vars($detail) : [];
        foreach (['name', 'stock', 'config', 'sku'] as $key) {
            $report['shape'][$key] = array_key_exists($key, $fields)
                ? $this->diagnosticValueType($fields[$key]) : 'missing';
        }
        if ($report['category'] === 'none') {
            if (!$decoded instanceof \stdClass || !array_key_exists('code', $envelope)
                || (!is_array($data) && !$data instanceof \stdClass)) {
                $report['category'] = 'schema';
            } elseif ($report['business_success'] !== true) {
                $report['category'] = 'business';
            }
        }
    }

    private function assertNodeLimit(array|\stdClass $decoded): void
    {
        $nodes = 0;
        $stack = [$decoded];
        while ($stack !== []) {
            $value = array_pop($stack);
            foreach ($value as $child) {
                $nodes++;
                if ($nodes > self::MAX_JSON_NODES) {
                    throw new UpstreamFailure('schema', $this->diagnostics());
                }
                if (is_array($child) || $child instanceof \stdClass) {
                    $stack[] = $child;
                }
            }
        }
    }
}
