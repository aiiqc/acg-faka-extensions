<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use App\Model\PriceTemplate;
use App\Util\Ini;
use Kernel\Util\Decimal;
use RuntimeException;

final class PriceAdjuster
{
    /** @return array{config:array,price:string,user_price:string} */
    public function adjustPrice(
        string $config,
        string $price,
        string $userPrice,
        int $type,
        float $premium,
    ): array {
        $this->assertType($type);
        $parsed = $config === '' ? [] : Ini::toArray($config);
        foreach (['category', 'wholesale'] as $section) {
            if (!is_array($parsed[$section] ?? null)) {
                continue;
            }
            foreach ($parsed[$section] as $key => $amount) {
                $parsed[$section][$key] = $this->adjustAmount($type, $premium, $amount);
            }
        }
        foreach (['sku', 'category_wholesale'] as $section) {
            if (!is_array($parsed[$section] ?? null)) {
                continue;
            }
            foreach ($parsed[$section] as $group => $amounts) {
                if (!is_array($amounts)) {
                    throw new RuntimeException('远端价格配置格式不正确');
                }
                foreach ($amounts as $key => $amount) {
                    if ((float)$amount > 0) {
                        $parsed[$section][$group][$key] = $this->adjustAmount($type, $premium, $amount);
                    }
                }
            }
        }

        return [
            'config' => $parsed,
            'price' => $this->adjustAmount($type, $premium, $price),
            'user_price' => $this->adjustAmount($type, $premium, $userPrice),
        ];
    }

    public function adjustAmount(int $type, float $premium, float|int|string $amount): string
    {
        $this->assertType($type);
        if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount < 0) {
            throw new RuntimeException('远端价格必须是非负数字');
        }
        $value = new Decimal($amount, 2);
        return $type === PriceTemplate::TYPE_FIXED
            ? $value->add($premium)->getAmount()
            : $value->add((new Decimal($premium, 3))->mul($amount)->getAmount())->getAmount();
    }

    private function assertType(int $type): void
    {
        if (!in_array($type, [PriceTemplate::TYPE_FIXED, PriceTemplate::TYPE_PERCENT], true)) {
            throw new RuntimeException('仅支持官方固定或百分比加价模式');
        }
    }
}
