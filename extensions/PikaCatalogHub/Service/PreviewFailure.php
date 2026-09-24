<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Service;

use RuntimeException;
use Throwable;

final class PreviewFailure extends RuntimeException
{
    private const STAGES = [
        'lock' => [
            'PREVIEW_LOCK_FAILED', '预览锁阶段未完成。',
            '请让管理员检查预览锁文件的权限与状态，再由您决定是否重试。',
        ],
        'dependency' => [
            'PREVIEW_DEPENDENCY_FAILED', '预览依赖加载阶段未完成。',
            '请让管理员核对扩展安装与依赖状态。',
        ],
        'source_lookup' => [
            'PREVIEW_SOURCE_LOOKUP_FAILED', '预览货源查询阶段未完成。',
            '请让管理员核对本站货源记录与数据库状态。',
        ],
        'catalog_request' => [
            'PREVIEW_CATALOG_REQUEST_FAILED', '预览目录请求阶段未完成，具体原因尚未确定。',
            '请保留诊断编号交由管理员检查，不要反复点击预览。',
        ],
        'catalog_normalize' => [
            'PREVIEW_CATALOG_NORMALIZE_FAILED', '预览目录字段规范化阶段未完成。',
            '请让管理员检查目录字段与格式边界，不要直接入库。',
        ],
        'rule_classify' => [
            'PREVIEW_RULE_CLASSIFY_FAILED', '预览规则分类阶段未完成。',
            '请让管理员检查本站分类规则与预览限制，不要直接入库。',
        ],
        'release' => [
            'PREVIEW_RELEASE_FAILED', '预览结束释放阶段未完成。',
            '请让管理员检查预览锁与运行状态，再由您决定是否重试。',
        ],
    ];

    private const SAFE_MESSAGES = [
        'lock' => [
            '已有分类预览正在运行，请稍后重试。' => [
                'PREVIEW_BUSY', '请等待当前预览结束后，再由您手动重试。',
            ],
            '分类预览处于 30 秒冷却期，请稍后重试。' => [
                'PREVIEW_COOLDOWN', '请等待 30 秒冷却结束后，再由您手动重试。',
            ],
        ],
        'source_lookup' => [
            '共享店铺不存在。' => [
                'PREVIEW_SOURCE_MISSING', '请刷新货源列表并重新选择现有货源。',
            ],
        ],
        'rule_classify' => [
            '请先为该货源设置唯一别名，再生成分类预览。' => [
                'PREVIEW_ALIAS_MISSING', '请先保存该货源的唯一分类名称。',
            ],
            '远端商品目录为空，无法生成可信分类预览。' => [
                'PREVIEW_CATALOG_EMPTY', '请让管理员确认目录为空的原因，不要继续入库。',
            ],
        ],
    ];

    private function __construct(
        private readonly string $safeCode,
        private readonly string $stage,
        private readonly string $correlationId,
        private readonly string $nextStep,
        string $summary,
    ) {
        parent::__construct(
            $summary . $nextStep . '（错误码：' . $safeCode . '；诊断编号：' . $correlationId . '）',
        );
    }

    public static function fromThrowable(
        Throwable $exception,
        string $stage,
        ?string $correlationId = null,
    ): self {
        if (!isset(self::STAGES[$stage])) {
            throw new \LogicException('分类预览诊断阶段不正确。');
        }
        [$code, $summary, $nextStep] = self::STAGES[$stage];
        if ($exception instanceof RuntimeException) {
            $message = $exception->getMessage();
            $safe = self::SAFE_MESSAGES[$stage][$message] ?? null;
            if ($safe !== null) {
                [$code, $nextStep] = $safe;
                $summary = $message;
            }
        }
        if ($correlationId === null || preg_match('/^[a-f0-9]{16}$/D', $correlationId) !== 1) {
            $correlationId = bin2hex(random_bytes(8));
        }
        $failure = new self($code, $stage, $correlationId, $nextStep, $summary);

        // Contention and cooldown are expected user-facing states, not error events.
        if (!in_array($code, ['PREVIEW_BUSY', 'PREVIEW_COOLDOWN'], true)) {
            try {
                error_log(json_encode([
                    'code' => $code,
                    'stage' => $stage,
                    'correlation' => $correlationId,
                ], JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                // Logging must never replace the safe business failure or prevent cleanup.
            }
        }
        return $failure;
    }

    public function safeCode(): string { return $this->safeCode; }
    public function stage(): string { return $this->stage; }
    public function correlationId(): string { return $this->correlationId; }
    public function nextStep(): string { return $this->nextStep; }
}
