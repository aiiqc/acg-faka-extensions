<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaCatalogHub\Hook;

use App\Consts\Manage;
use App\Util\Context as AppContext;
use Kernel\Consts\Base;
use Kernel\Util\Context;
use Pika\LocalExtensions\Manager\Csrf;

final class NativeCategoryRename
{
    public function toolbar(): string
    {
        return $this->available()
            ? '<script>ready("/assets/local-extensions/PikaCatalogHub/category-rename.js");</script>'
            : '';
    }

    public function form(): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $token = json_encode(Csrf::issue(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return ['submit' => '/admin/api/category/save', 'field' => 'name', 'direction' => 'after',
            'code' => '({name:"pika_source_rename",type:"custom",submit:false,complete:function(form,dom){'
                . 'return window.PikaCategoryRename ? window.PikaCategoryRename.mount(form,dom,' . $token . ') : null;}})'];
    }

    private function available(): bool
    {
        $manage = AppContext::get(Manage::SESSION);
        return strtolower((string)Context::get(Base::ROUTE)) === '/admin/category/index'
            && is_object($manage) && (int)($manage->type ?? -1) === 0;
    }
}
