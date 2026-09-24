<?php
declare(strict_types=1);

namespace App\Consts {
    final class Hook
    {
        public const USER_GLOBAL_VIEW_HEADER = 1;
        public const USER_VIEW_INDEX_HEADER = 2;
    }
}

namespace {
    function fail(string $message): never
    {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }

    function lang_code(): string { return 'zh-cn'; }
    function css(array $public, array $private): string { return ''; }
    function js(string $path): string { return ''; }
    function index_var(): string { return ''; }
    function hook(int $id): string { return ''; }
    function active(string $path): string { return ''; }
    function user_nav_icon(array $item, string $class = ''): string { return ''; }
    function t(string $value): string { return $value; }
    function user_header_nav(): array { return $GLOBALS['pika_nav_fixture']; }

    $autoload = $argv[1] ?? '';
    $themeRoot = $argv[2] ?? '';
    if (!is_file($autoload) || !is_dir($themeRoot)) {
        fail('usage: local-theme-header-nav-behavior.php AUTOLOAD THEME_ROOT');
    }
    require $autoload;

    $GLOBALS['pika_nav_fixture'] = [
        ['name' => 'safe-blank', 'url' => '/safe', 'target' => '_blank', 'match' => ''],
        ['name' => 'safe-relative', 'url' => 'relative/path?x=1', 'target' => '_self', 'match' => ''],
        ['name' => 'safe-https', 'url' => 'https://example.test/path', 'target' => 'popup', 'match' => ''],
        ['name' => 'blocked-js', 'url' => 'javascript:alert(1)', 'target' => '_blank', 'match' => ''],
        ['name' => 'blocked-data', 'url' => 'data:text/html,unsafe', 'target' => '_self', 'match' => ''],
        ['name' => 'blocked-protocol-relative', 'url' => '//example.test/path', 'target' => '_self', 'match' => ''],
        ['name' => 'blocked-backslash', 'url' => '/\\example.test/path', 'target' => '_self', 'match' => ''],
        ['name' => 'blocked-control', 'url' => "/safe\nunsafe", 'target' => '_self', 'match' => ''],
    ];

    $compileDirectory = sys_get_temp_dir() . '/pika-nav-' . getmypid();
    if (!mkdir($compileDirectory, 0700, true) && !is_dir($compileDirectory)) {
        fail('unable to create compile directory');
    }
    $smarty = new \Smarty();
    $smarty->left_delimiter = '#{';
    $smarty->right_delimiter = '}';
    $smarty->setTemplateDir($themeRoot);
    $smarty->setCompileDir($compileDirectory);
    $smarty->assign('config', [
        'keywords' => '',
        'description' => '',
        'shop_name' => 'Pika',
        'title' => 'Pika',
        'currency_symbol' => '$',
    ]);
    $smarty->assign('user', null);
    $html = $smarty->fetch('Index/Header.html');

    if (!str_contains($html, 'href="/safe" target="_blank" rel="noopener noreferrer"')) {
        fail('safe _blank navigation did not receive the protected relation');
    }
    if (!str_contains($html, 'href="relative/path?x=1" target="_self"')) {
        fail('safe site-relative navigation was not rendered');
    }
    if (!str_contains($html, 'href="https://example.test/path" target="_self"')) {
        fail('safe HTTPS navigation or target coercion failed');
    }
    foreach (['blocked-js', 'blocked-data', 'blocked-protocol-relative', 'blocked-backslash', 'blocked-control'] as $name) {
        if (str_contains($html, $name)) {
            fail("unsafe navigation was rendered: {$name}");
        }
    }
    if (str_contains($html, 'target="popup"')) {
        fail('arbitrary navigation target was rendered');
    }

    fwrite(STDOUT, "PASS Pika hook navigation URL and target policy\n");
}
