<?php
declare(strict_types=1);

return [
    [
        'title' => 'BEpusdt 本机 API 地址',
        'name' => 'gateway_origin',
        'type' => 'input',
        'placeholder' => '例如：http://127.0.0.1:8080',
        'default' => 'http://127.0.0.1:8080',
    ],
    [
        'title' => '公开收银台地址（与当前站点一致）',
        'name' => 'checkout_origin',
        'type' => 'input',
        'placeholder' => '例如：https://shop.example.com',
        'default' => '',
    ],
    [
        'title' => '当前异次元站点地址',
        'name' => 'merchant_origin',
        'type' => 'input',
        'placeholder' => '例如：https://shop.example.com',
        'default' => '',
    ],
    [
        'title' => '法币单位',
        'name' => 'fiat',
        'type' => 'select',
        'placeholder' => '商品计价的法币单位',
        'default' => 'CNY',
        'dict' => [
            ['id' => 'CNY', 'name' => 'CNY 人民币'],
            ['id' => 'USD', 'name' => 'USD 美元'],
            ['id' => 'EUR', 'name' => 'EUR 欧元'],
            ['id' => 'JPY', 'name' => 'JPY 日元'],
            ['id' => 'GBP', 'name' => 'GBP 英镑'],
        ],
    ],
];
