<?php
declare(strict_types=1);

return [
    'version' => '0.1.2',
    'name' => 'Pika BEpusdt',
    'author' => 'Acg-Faka Extensions contributors',
    'website' => '',
    'description' => 'BEpusdt 本机 API 支付与充值适配器',
    'options' => [
        'usdt.bep20' => 'USDT-BEP20',
        'tron.trx' => 'TRX',
        'usdt.trc20' => 'USDT-TRC20',
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 2,
        \App\Consts\Pay::FIELD_ORDER_KEY => 'order_id',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'amount',
        \App\Consts\Pay::FIELD_RESPONSE => 'ok',
    ],
];
