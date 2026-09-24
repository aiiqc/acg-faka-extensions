<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaOrderReturnWait\Hook;

use Pika\LocalExtensions\PikaOrderReturnWait\Service\WaitPage;
use Kernel\Consts\Base;
use Kernel\Util\Context;
use Pika\LocalExtensions\Manager\ConfigStore;

final class OrderReturnWait
{
    public function guestOrderPage(): string
    {
        return $this->render(WaitPage::GUEST_ROUTE);
    }

    public function memberOrderPage(): string
    {
        return $this->render(WaitPage::MEMBER_ROUTE);
    }

    private function render(string $expectedRoute): string
    {
        $route = (string)Context::get(Base::ROUTE);
        if (strtolower(rtrim($route, '/')) !== strtolower(rtrim($expectedRoute, '/'))) {
            return '';
        }

        return WaitPage::render(
            $route,
            $_GET['tradeNo'] ?? null,
            ConfigStore::get('PikaOrderReturnWait')
        );
    }
}
