<?php
declare(strict_types=1);

use Pika\LocalExtensions\Manager\ConfigStore as ManagerConfig;
use Pika\LocalExtensions\Manager\StateStore as ManagerState;
use Pika\LocalExtensions\PikaSupplySync\Service\ExtensionLogger;
use Pika\LocalExtensions\PikaSupplySync\Service\ImageCache;
use Pika\LocalExtensions\PikaSupplySync\Service\Options;
use Pika\LocalExtensions\PikaSupplySync\Service\PriceAdjuster;
use Pika\LocalExtensions\PikaSupplySync\Service\RunBudget;
use Pika\LocalExtensions\PikaSupplySync\Service\SafeHttpClient;
use Pika\LocalExtensions\PikaSupplySync\Service\SharedGateway;
use Pika\LocalExtensions\PikaSupplySync\Service\SourcePolicy;
use Pika\LocalExtensions\PikaSupplySync\Service\SyncService;

const EXTENSION_ID = 'PikaSupplySync';

$parseArguments = static function (array $tokens): array {
    $valueOptions = ['root' => true, 'source' => true, 'mode' => true, 'batch' => true, 'only-code-hashes' => true];
    $flagOptions = ['dry-run' => true, 'help' => true];
    $parsed = [];

    for ($index = 0; $index < count($tokens); $index++) {
        $token = $tokens[$index];
        if (!is_string($token) || !str_starts_with($token, '--') || $token === '--') {
            throw new RuntimeException('同步命令包含不支持的位置参数或短参数');
        }
        $body = substr($token, 2);
        $separator = strpos($body, '=');
        $name = $separator === false ? $body : substr($body, 0, $separator);
        if (!isset($valueOptions[$name]) && !isset($flagOptions[$name])) {
            throw new RuntimeException("同步命令包含未知参数：--{$name}");
        }
        if (array_key_exists($name, $parsed)) {
            throw new RuntimeException("同步命令参数重复：--{$name}");
        }
        if (isset($flagOptions[$name])) {
            if ($separator !== false) {
                throw new RuntimeException("同步命令开关不接受参数值：--{$name}");
            }
            $parsed[$name] = true;
            continue;
        }

        if ($separator !== false) {
            $value = substr($body, $separator + 1);
        } else {
            $index++;
            $value = $tokens[$index] ?? null;
        }
        if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
            throw new RuntimeException("同步命令参数缺少值：--{$name}");
        }
        $parsed[$name] = $value;
    }

    return $parsed;
};

try {
    $arguments = $parseArguments(array_slice($_SERVER['argv'] ?? [], 1));
    if (isset($arguments['help'])) {
        fwrite(STDOUT, "Usage: php bin/sync.php [--root=/path/to/acg-faka] [--source=1,2] [--mode=basic|full] [--batch=100] [--dry-run]\n");
        fwrite(STDOUT, "Targeted acceptance: --source=ID --only-code-hashes=HASH[,HASH] (1-2 distinct 12-digit lowercase hashes; saved six-field full-follow/basic required; no rotation advance)\n");
        exit(0);
    }
    $targetHashes = isset($arguments['only-code-hashes']) ? explode(',', $arguments['only-code-hashes']) : null;
    if ($targetHashes !== null && (count($targetHashes) > 2 || count(array_unique($targetHashes)) !== count($targetHashes)
        || preg_match('/^[a-f0-9]{12}(,[a-f0-9]{12})?$/D', $arguments['only-code-hashes']) !== 1
        || preg_match('/^[1-9][0-9]*$/D', $arguments['source'] ?? '') !== 1)) {
        throw new RuntimeException('定向同步需要明确唯一 --source 及 1-2 个不同的 12 位小写商品编号哈希');
    }

    $extensionRoot = dirname(__DIR__);
    $candidate = $arguments['root'] ?? getenv('ACG_FAKA_ROOT') ?: dirname($extensionRoot, 3);
    if (!is_string($candidate) || $candidate === '') {
        throw new RuntimeException('必须指定 Acg-Faka 站点根目录');
    }
    $siteRoot = realpath($candidate);
    if (
        $siteRoot === false
        || !is_dir($siteRoot)
        || !is_file($siteRoot . '/kernel/Console.php')
        || !is_file($siteRoot . '/config/app.php')
    ) {
        throw new RuntimeException('Acg-Faka 站点根目录不正确');
    }
    $managerBootstrap = $siteRoot . '/local-extensions/bootstrap.php';
    if (!is_file($managerBootstrap) || is_link($managerBootstrap)) {
        throw new RuntimeException('LocalExtensions 管理器未安装');
    }

    require $siteRoot . '/kernel/Console.php';
    require $managerBootstrap;
    require $extensionRoot . '/bootstrap.php';

    if (!ManagerState::isEnabled(EXTENSION_ID)) {
        throw new RuntimeException('PikaSupplySync LocalExtension 未启用');
    }
    $defaults = require $extensionRoot . '/Config/Config.php';
    if (!is_array($defaults)) {
        throw new RuntimeException('PikaSupplySync 默认配置格式不正确');
    }
    $saved = ManagerConfig::get(EXTENSION_ID);
    if (!is_array($saved)) {
        throw new RuntimeException('PikaSupplySync 管理配置格式不正确');
    }
    $config = array_merge($defaults, $saved);
    $overrides = [
        'source_ids' => $arguments['source'] ?? null,
        'mode' => $arguments['mode'] ?? null,
        'batch_limit' => $arguments['batch'] ?? null,
        'dry_run' => isset($arguments['dry-run']),
    ];
    $options = Options::fromArray($config, $overrides);
    $targetOptionsReader = $targetHashes === null ? null : static function () use ($defaults, $options): Options {
        if (!ManagerState::isEnabled(EXTENSION_ID, true)) throw new RuntimeException('定向同步期间扩展已停用');
        return SyncService::targetedOptions(
            Options::fromArray(array_merge($defaults, ManagerConfig::get(EXTENSION_ID))), $options,
        );
    };

    $policy = new SourcePolicy();
    $budget = new RunBudget();
    $http = new SafeHttpClient($policy, null, $budget);
    $images = new ImageCache($http, $budget);
    $service = new SyncService(
        new SharedGateway($http, $policy),
        new PriceAdjuster(),
        $policy,
        $images,
        new ExtensionLogger(),
        $budget,
    );
    $result = $service->run($options, $targetHashes, $targetOptionsReader);
    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);

    foreach ($result['sources'] as $source) {
        if (in_array($source['status'] ?? '', ['error', 'partial'], true)
            || ($targetHashes !== null && ($source['status'] ?? '') !== 'ok')) {
            exit(1);
        }
    }
    exit(0);
} catch (Throwable $exception) {
    $message = trim(strip_tags($exception->getMessage()));
    $message = (string)preg_replace(
        '/(?i)(app[_-]?key|secret|token|password)\s*[:=]\s*[^\s,;]+/',
        '$1=[REDACTED]',
        $message
    );
    $message = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
    $message = $message === '' ? '同步任务启动失败' : $message;
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 200, 'UTF-8')
        : substr($message, 0, 200);
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
    exit(1);
}
