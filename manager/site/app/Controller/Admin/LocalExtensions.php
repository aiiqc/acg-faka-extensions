<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Pika\LocalExtensions\Manager\Csrf;
use Pika\LocalExtensions\Manager\Registry;

#[Interceptor(ManageSession::class)]
final class LocalExtensions extends Manage
{
    /** @throws ViewException|JSONException */
    public function index(): string
    {
        $manage = $this->getManage();
        if (!is_object($manage) || (int)$manage->type !== 0) {
            throw new JSONException('仅系统管理员可管理本地扩展');
        }

        return $this->render('本地扩展', 'LocalExtensions/Index.html', [
            'local_extensions_csrf' => Csrf::issue(),
        ]);
    }

    /** @throws ViewException|JSONException */
    public function catalogHub(): string
    {
        $manage = $this->getManage();
        if (!is_object($manage) || (int)$manage->type !== 0) {
            throw new JSONException('仅系统管理员可管理智能货源中心');
        }

        try {
            Registry::extension('PikaCatalogHub');
        } catch (\Throwable) {
            throw new JSONException('智能货源中心尚未由服务器安装器登记');
        }

        return $this->render('智能货源中心', 'LocalExtensions/CatalogHub.html', [
            'catalog_hub_csrf' => Csrf::issue(),
        ]);
    }
}
