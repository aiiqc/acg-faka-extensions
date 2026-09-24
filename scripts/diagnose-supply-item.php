<?php
declare(strict_types=1);

use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;

/** The sole fallback literal also covers failures before dependencies are loaded. */
function pikaSupplyItemDiagnosticUnavailable(): array
{
    return [
        'schema_version' => 1, 'diagnostic_only' => true, 'category' => 'preflight',
        'http_status' => 0, 'curl_code' => 0, 'elapsed_ms' => 0, 'attempts' => 0,
        'mime' => [
            'observed' => false, 'response_blocks' => 0, 'final_status' => 0,
            'final_headers_complete' => false, 'final_header_count' => 0,
            'content_type_present' => null, 'content_type_count' => 0,
            'category' => 'unknown', 'effective_category' => 'unknown', 'counts_truncated' => false,
        ],
        'body_bytes' => null, 'body_complete' => false, 'json_valid' => null,
        'json_error' => 'unobserved', 'top_level' => 'unobserved', 'within_json_limits' => null,
        'business_success' => null,
        'shape' => [
            'code' => 'unobserved', 'data' => 'unobserved', 'detail' => 'unobserved',
            'children' => 'unobserved', 'name' => 'unobserved', 'stock' => 'unobserved',
            'config' => 'unobserved', 'sku' => 'unobserved',
        ],
    ];
}

/** One invocation per process, including an invocation that fails preflight. */
function pikaDiagnoseSupplyItem(string $coreRoot, array $source, string $code): array
{
    static $consumed = false;
    if ($consumed) {
        return pikaSupplyItemDiagnosticUnavailable();
    }
    $consumed = true;

    $previousIni = [];
    set_error_handler(static function (): never {
        throw new RuntimeException('Diagnostic unavailable');
    });
    try {
        foreach (['display_errors', 'display_startup_errors', 'log_errors'] as $setting) {
            $previousIni[$setting] = ini_get($setting);
            if (ini_set($setting, '0') === false) {
                throw new RuntimeException('Diagnostic unavailable');
            }
        }
        if (PHP_SAPI !== 'cli' || defined('BASE_PATH') || spl_autoload_functions() !== []) {
            throw new RuntimeException('Diagnostic unavailable');
        }
        foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $className) {
            $normalizedClassName = strtolower($className);
            if (str_starts_with($normalizedClassName, 'app\\')
                || str_starts_with($normalizedClassName, 'pika\\localextensions\\pikasupplysync\\service\\')) {
                throw new RuntimeException('Diagnostic unavailable');
            }
        }

        $releaseRoot = dirname(__DIR__);
        if ($coreRoot === '' || realpath($coreRoot) !== $coreRoot || !is_dir($coreRoot)
            || realpath($releaseRoot) !== $releaseRoot) {
            throw new RuntimeException('Diagnostic unavailable');
        }
        $dependencies = [$coreRoot . '/app/Util/Str.php'];
        foreach (['SourcePolicy', 'UpstreamFailure', 'RunBudget', 'SafeHttpClient', 'SharedGateway'] as $service) {
            $dependencies[] = $releaseRoot . '/extensions/PikaSupplySync/Service/' . $service . '.php';
        }
        foreach ($dependencies as $dependency) {
            if (realpath($dependency) !== $dependency || !is_file($dependency) || !is_readable($dependency)) {
                throw new RuntimeException('Diagnostic unavailable');
            }
        }
        foreach ($dependencies as $dependency) {
            require $dependency;
        }
        if (!class_exists('App\\Util\\Str', false)
            || !is_callable(['App\\Util\\Str', 'generateSignature'])
            || SafeHttpClient::diagnosticUnavailable() !== pikaSupplyItemDiagnosticUnavailable()) {
            throw new RuntimeException('Diagnostic unavailable');
        }

        $policy = new SourcePolicy();
        return (new SharedGateway(new SafeHttpClient($policy), $policy))->diagnoseItem($source, $code);
    } catch (Throwable) {
        return pikaSupplyItemDiagnosticUnavailable();
    } finally {
        foreach ($previousIni as $setting => $value) {
            if (is_string($value)) {
                try {
                    ini_set($setting, $value);
                } catch (Throwable) {
                    // Restoration must never emit the original failure or caller data.
                }
            }
        }
        restore_error_handler();
    }
}
