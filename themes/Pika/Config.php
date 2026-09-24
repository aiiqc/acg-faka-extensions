<?php
declare(strict_types=1);

namespace App\View\User\Theme\Pika;

/**
 * Interface Config
 * @package App\View\User\Theme\Pika
 */
interface Config
{
    /**
     * 介绍信息
     */
    const INFO = Metadata::INFO;

    /**
     * 配置信息
     */
    const SUBMIT = Metadata::SUBMIT;

    /**
     * 模板文件重定向，不需要修改的直接删除
     */
    const THEME = Metadata::THEME;

}
