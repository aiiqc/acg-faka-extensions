<?php
declare(strict_types=1);

$extensionRoot = dirname(__DIR__) . '/extensions/PikaOrderReturnWait';
$registered = require $extensionRoot . '/bootstrap.php';

use Pika\LocalExtensions\PikaOrderReturnWait\Service\WaitPage;

function expectLocalOrderWait(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expectLocalOrderWait($registered === true, 'bootstrap registers the package autoloader');
expectLocalOrderWait(class_exists(WaitPage::class), 'package namespace autoloads on Linux');

$normalized = WaitPage::normalizeConfig([
    'wait_seconds' => '999',
    'refresh_seconds' => '-3',
]);
expectLocalOrderWait(
    $normalized === ['waitSeconds' => 120, 'refreshSeconds' => 1],
    'configuration is clamped'
);

expectLocalOrderWait(
    WaitPage::render('/user/index/index', '202608301234567890', []) === '',
    'unrelated routes are not modified'
);
expectLocalOrderWait(
    WaitPage::render('/user/index/query', '<script>alert(1)</script>', []) === '',
    'invalid trade numbers are rejected'
);

$guest = WaitPage::render('/user/index/query', '202608301234567890', [
    'wait_seconds' => 30,
    'refresh_seconds' => 3,
    'password' => '<img src=x onerror=alert(1)>',
]);
expectLocalOrderWait(str_contains($guest, 'data-mode="guest"'), 'guest mode is rendered');
expectLocalOrderWait(
    str_contains($guest, '/user/index/query?tradeNo=202608301234567890'),
    'official guest query entry is retained'
);
expectLocalOrderWait(
    str_contains($guest, '/assets/local-extensions/PikaOrderReturnWait/order-return-wait.js'),
    'public asset path is isolated from extension source'
);
expectLocalOrderWait(
    !str_contains($guest, 'onerror') && !str_contains($guest, 'password'),
    'query password and unrelated configuration are never rendered'
);

$member = WaitPage::render('/user/personal/purchaseRecord/', '202608301234567890', []);
expectLocalOrderWait(str_contains($member, 'data-mode="member"'), 'member route matches case-insensitively');
expectLocalOrderWait(
    str_contains($member, '/user/personal/purchaseRecord?tradeNo=202608301234567890'),
    'official member query entry is retained'
);

fwrite(STDOUT, "PASS local order wait behavior\n");
