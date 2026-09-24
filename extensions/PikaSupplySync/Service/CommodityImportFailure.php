<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;
use Throwable;

final class CommodityImportFailure extends RuntimeException
{
    public const SOURCE_POLICY_FAILED = 'ITEM_SOURCE_POLICY_FAILED';
    public const DETAIL_FETCH_FAILED = 'ITEM_DETAIL_FETCH_FAILED';
    public const DETAIL_UNKNOWN_FAILED = 'ITEM_DETAIL_UNKNOWN_FAILED';
    public const DETAIL_TRANSPORT_FAILED = 'ITEM_DETAIL_TRANSPORT_FAILED';
    public const DETAIL_HTTP_RETRYABLE = 'ITEM_DETAIL_HTTP_RETRYABLE';
    public const DETAIL_ITEM_UNAVAILABLE = 'ITEM_DETAIL_UNAVAILABLE';
    public const ITEM_DATA_INVALID = 'ITEM_REMOTE_DATA_INVALID';
    public const DETAIL_HTTP_REJECTED = 'ITEM_DETAIL_HTTP_REJECTED';
    public const DETAIL_CREDENTIALS_INVALID = 'ITEM_DETAIL_CREDENTIALS_INVALID';
    public const DETAIL_BUSINESS_REJECTED = 'ITEM_DETAIL_BUSINESS_REJECTED';
    public const DETAIL_RESPONSE_INVALID = 'ITEM_DETAIL_RESPONSE_INVALID';
    public const DETAIL_JSON_INVALID = 'ITEM_DETAIL_JSON_INVALID';
    public const DETAIL_BUDGET_EXCEEDED = 'ITEM_DETAIL_BUDGET_EXCEEDED';
    public const DETAIL_NORMALIZATION_FAILED = 'ITEM_DETAIL_NORMALIZATION_FAILED';
    public const STOCK_VALIDATION_FAILED = 'ITEM_STOCK_VALIDATION_FAILED';
    public const PRICE_ADJUSTMENT_FAILED = 'ITEM_PRICE_ADJUSTMENT_FAILED';
    public const CONFIG_EXTRACTION_FAILED = 'ITEM_CONFIG_EXTRACTION_FAILED';
    public const CATEGORY_TRANSACTION_FAILED = 'ITEM_CATEGORY_TRANSACTION_FAILED';
    public const PERSISTENCE_FAILED = 'ITEM_PERSISTENCE_FAILED';

    private const SAFE_CODES = [
        self::SOURCE_POLICY_FAILED,
        self::DETAIL_FETCH_FAILED,
        self::DETAIL_UNKNOWN_FAILED,
        self::DETAIL_TRANSPORT_FAILED,
        self::DETAIL_HTTP_RETRYABLE,
        self::DETAIL_ITEM_UNAVAILABLE,
        self::ITEM_DATA_INVALID,
        self::DETAIL_HTTP_REJECTED,
        self::DETAIL_CREDENTIALS_INVALID,
        self::DETAIL_BUSINESS_REJECTED,
        self::DETAIL_RESPONSE_INVALID,
        self::DETAIL_JSON_INVALID,
        self::DETAIL_BUDGET_EXCEEDED,
        self::DETAIL_NORMALIZATION_FAILED,
        self::STOCK_VALIDATION_FAILED,
        self::PRICE_ADJUSTMENT_FAILED,
        self::CONFIG_EXTRACTION_FAILED,
        self::CATEGORY_TRANSACTION_FAILED,
        self::PERSISTENCE_FAILED,
    ];

    public readonly string $safeCode;
    public readonly ?array $safeDiagnostics;

    public static function isIsolatableItemCode(?string $code): bool
    {
        return in_array($code, [
            self::DETAIL_TRANSPORT_FAILED,
            self::DETAIL_HTTP_RETRYABLE,
            self::DETAIL_ITEM_UNAVAILABLE,
            self::ITEM_DATA_INVALID,
            self::DETAIL_JSON_INVALID,
        ], true);
    }

    public static function isResumableDetailCode(?string $code): bool
    {
        // The generic legacy code remains manually resumable, not confirmed transient.
        return in_array($code, [self::DETAIL_FETCH_FAILED, self::DETAIL_TRANSPORT_FAILED, self::DETAIL_HTTP_RETRYABLE], true);
    }

    public function __construct(string $safeCode, Throwable $previous)
    {
        if (!in_array($safeCode, self::SAFE_CODES, true)) {
            $safeCode = 'ITEM_IMPORT_FAILED';
        }
        $this->safeCode = $safeCode;
        $this->safeDiagnostics = $previous instanceof UpstreamFailure
            ? $previous->diagnostics
            : ($previous instanceof BudgetExceeded ? $previous->safeDiagnostics : null);
        parent::__construct($safeCode, 0, $previous);
    }
}
