<?php
declare(strict_types=1);

namespace App\View\User\Theme\Pika;

use App\Consts\Render;

final class Metadata
{
    public const VERSION = '1.1.6';

    public const INFO = [
        'NAME' => 'Pika',
        'AUTHOR' => 'Acg-Faka Extensions contributors',
        'VERSION' => self::VERSION,
        'WEB_SITE' => '#',
        'DESCRIPTION' => 'Pika bright storefront and account theme',
        'RENDER' => Render::ENGINE_SMARTY,
    ];

    public const SUBMIT = [
        [
            'title' => 'ICP备案号',
            'name' => 'icp',
            'type' => 'input',
            'placeholder' => '填写后将会在店铺底部显示ICP备案号，不填写则不显示。',
        ],
    ];

    public const THEME = [
        'INDEX' => 'Index/Index.html',
        'ITEM' => 'Index/Item.html',
        'CLOSED' => 'Index/Closed.html',
        'QUERY' => 'Index/Query.html',
        'LOGIN' => 'Authentication/Login.html',
        'REGISTER' => 'Authentication/Register.html',
        'FORGET_EMAIL' => 'Authentication/ForgetEmail.html',
        'FORGET_PHONE' => 'Authentication/ForgetPhone.html',
        'DASHBOARD' => 'Dashboard/Index.html',
        'PURCHASE_RECORD' => 'User/PurchaseRecord.html',
        'RECHARGE' => 'User/Recharge.html',
        'BILL' => 'User/Bill.html',
        'BUSINESS' => 'User/Business.html',
        'CATEGORY' => 'User/Category.html',
        'COMMODITY' => 'User/Commodity.html',
        'CARD' => 'User/Card.html',
        'COUPON' => 'User/Coupon.html',
        'CASH' => 'User/Cash.html',
        'CASH_RECORD' => 'User/CashRecord.html',
        'PERSONAL' => 'User/Personal.html',
        'EMAIL' => 'User/Email.html',
        'PHONE' => 'User/Phone.html',
        'PASSWORD' => 'User/Password.html',
        'ORDER' => 'User/Order.html',
        'TICKET' => 'User/Ticket.html',
        'TICKET_CREATE' => 'User/TicketCreate.html',
        'TICKET_DETAIL' => 'User/TicketDetail.html',
        'MESSAGE' => 'User/Message.html',
        'AGENT_MEMBER' => 'Agent/Member.html',
        'AGENT_PROMOTE' => 'Agent/Promote.html',
    ];
}
