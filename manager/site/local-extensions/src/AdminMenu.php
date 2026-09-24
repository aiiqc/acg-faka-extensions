<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

use App\Consts\Manage as ManageConst;
use App\Util\Context as AppContext;
use Kernel\Consts\Base;
use Kernel\Util\Context;

final class AdminMenu
{
    public function render(): string
    {
        $manage = AppContext::get(ManageConst::SESSION);
        if (!is_object($manage) || (int)($manage->type ?? -1) !== 0) {
            return '';
        }
        $route = (string)(Context::get(Base::ROUTE) ?? '');
        $indexActive = $route === '/admin/localExtensions/index' ? 'active' : '';
        $menu = '<div class="menu-item"><a class="menu-link ' . $indexActive . '" href="/admin/localExtensions/index">'
            . '<span class="menu-icon"><span class="material-icons-outlined" aria-hidden="true">extension</span></span>'
            . '<span class="menu-title">本地扩展</span></a></div>';

        try {
            Registry::extension('PikaCatalogHub');
            $hubActive = $route === '/admin/localExtensions/catalogHub' ? 'active' : '';
            $menu .= '<div class="menu-item"><a class="menu-link ' . $hubActive . '" href="/admin/localExtensions/catalogHub">'
                . '<span class="menu-icon"><span class="material-icons-outlined" aria-hidden="true">account_tree</span></span>'
                . '<span class="menu-title">智能货源中心</span></a></div>';
        } catch (\Throwable) {
            // The menu must remain available when the optional extension is absent.
        }

        return $menu;
    }
}
