<?php
declare(strict_types=1);

namespace App\Consts {
    interface Render
    {
        public const ENGINE_SMARTY = 0;
    }
}

namespace {
    $themeRoot = dirname(__DIR__) . '/themes/Pika';
    $prefix = 'App\\View\\User\\Theme\\Pika\\';
    spl_autoload_register(static function (string $class) use ($themeRoot, $prefix): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $relative) !== 1) {
            return;
        }
        $path = $themeRoot . '/' . $relative . '.php';
        if (is_file($path) && !is_link($path)) {
            require_once $path;
        }
    });

    $config = 'App\\View\\User\\Theme\\Pika\\Config';
    if (!interface_exists($config)) {
        fwrite(STDERR, "FAIL: Pika Config did not autoload\n");
        exit(1);
    }
    if (($config::INFO['VERSION'] ?? null) !== '1.1.7'
        || ($config::INFO['RENDER'] ?? null) !== \App\Consts\Render::ENGINE_SMARTY
        || ($config::THEME['INDEX'] ?? null) !== 'Index/Index.html'
        || ($config::SUBMIT[0]['name'] ?? null) !== 'icp') {
        fwrite(STDERR, "FAIL: Pika metadata contract is inconsistent\n");
        exit(1);
    }

    $setting = require $themeRoot . '/Setting.php';
    if ($setting !== ['icp' => '']) {
        fwrite(STDERR, "FAIL: Pika default Setting.php is invalid\n");
        exit(1);
    }

    if (($argv[1] ?? '') === '--purchase-fixtures') {
        // Render the actual purchase body with synthetic data, without booting the app.
        require getenv('ACG_FAKA_OFFICIAL_ROOT') . '/vendor/autoload.php';
        function t($value) { return $value; }
        function lang($value) { return $value; }
        function item_var($value) { return ''; }
        function widget_render($value) { return ''; }
        function contact_type_msg($value) { return '联系方式'; }
        function ready($value) { return '<script data-fixture-ready="' . htmlspecialchars($value, ENT_QUOTES) . '"></script>'; }
        function lang_code() { return 'zh-CN'; }
        function css($files, $expanded = []) {
            return implode('', array_map(static fn($file) => '<link rel="stylesheet" href="' . htmlspecialchars($file, ENT_QUOTES) . '">', $files));
        }
        function js($files, $expanded = []) { return ''; }
        function index_var() { return ''; }
        function hook($hook) { return ''; }
        function active($route) { return $route === '/' ? 'active' : ''; }
        function user_header_nav() { return $GLOBALS['fixtureNavigation']; }
        function user_nav_icon($nav, $class) { return '<i class="fa-duotone fa-regular fa-list ' . htmlspecialchars($class, ENT_QUOTES) . '"></i>'; }
        $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        // A string template has no directory; resolve its category include through templateDir.
        $body = static fn(string $template): string => str_replace('file="./CategoryNode.html"', 'file="CategoryNode.html"',
            preg_replace('/#\{include file="\.\/(?:Header|Footer)\.html"\}/', '', $template));
        $source = $body($input['template']);
        $smarty = new \Smarty();
        $smarty->left_delimiter = '#{';
        $smarty->right_delimiter = '}';
        $smarty->setTemplateDir($themeRoot . '/Index');
        $compile = sys_get_temp_dir() . '/pika-purchase-' . getmypid();
        mkdir($compile, 0700);
        $smarty->setCompileDir($compile);
        $item = [
            'id' => 7, 'name' => '合成商品', 'cover' => '/fixture.svg', 'description' => '合成说明',
            'order_sold' => 4321, 'stock' => 8, 'stock_state' => 1, 'delivery_way' => 0,
            'seckill_status' => 0, 'config' => [], 'draft_status' => 0, 'contact_type' => 0,
            'coupon' => 0, 'widget' => [], 'password_status' => 0, 'minimum' => 1,
            'maximum' => 10, 'trade_captcha' => 0,
        ];
        $users = [
            'normal' => ['balance' => '12.34'], 'zero' => ['balance' => '0'],
            'guest' => null, 'missing' => ['id' => 501], 'blank' => ['balance' => ''],
            'invalid' => ['balance' => 'not-money'], 'negative' => ['balance' => '-1'],
            'infinite' => ['balance' => '1e309'], 'huge' => ['balance' => '9999999999999999999999'],
            'injection' => ['balance' => '"><img src=x onerror=alert(1)>'],
            'boolean' => ['balance' => true], 'array' => ['balance' => []],
            'changed' => ['balance' => '56.78'],
        ];
        $fixtures = [];
        foreach ($users as $name => $user) {
            $smarty->assign(['user' => $user, 'item' => $item, 'config' => ['currency_symbol' => '¥']]);
            $fixtures[$name] = $smarty->fetch('string:' . $source);
        }
        // Keep the real catalog/sidebar widths in stock layout checks.
        $index = null;
        if (isset($input['indexTemplate'])) {
            $smarty->assign(['category' => [], 'config' => [
                'title' => '合成库存布局验证', 'shop_name' => '合成商店', 'notice' => '仅使用合成数据',
            ]]);
            $index = $smarty->fetch('string:' . $body($input['indexTemplate']));
        }
        $storefronts = [];
        $brandNames = [
            'zh-six' => '松果数字商店',
            'en-short' => 'Pine Shop',
            'zh-long' => '松果数字创意与实用工具精选商店',
            'en-long' => 'Pine Digital Creative Goods and Useful Tools Store',
            'unbroken' => 'PineDigitalCreativeGoodsAndUsefulToolsStoreWithoutSpaces',
        ];
        $demo = ($input['demo'] ?? false) === true;
        if (isset($input['headerTemplate'], $input['footerTemplate'])) {
            require_once getenv('ACG_FAKA_OFFICIAL_ROOT') . '/app/Consts/Hook.php';
            $variants = ['default' => null] + $brandNames;
            foreach ($variants as $brandKey => $brandName) foreach (['guest', 'member', 'long'] as $identity) {
                if ($brandKey !== 'default' && $identity === 'long') {
                    continue;
                }
                $GLOBALS['fixtureNavigation'] = [
                    ['name' => '商品首页', 'url' => '/', 'match' => '/'],
                    ['name' => '购买记录', 'url' => '/user/personal/purchaseRecord', 'match' => '/user/personal/purchaseRecord'],
                ];
                if ($identity === 'long') {
                    $GLOBALS['fixtureNavigation'][] = ['name' => '合成帮助与使用说明', 'url' => '/fixture/help', 'match' => '/fixture/help'];
                }
                $smarty->assign([
                    'item' => null, 'user' => $identity === 'guest' ? null : [
                        'username' => $identity === 'long' ? 'SyntheticAccountLongName' : ($demo ? '示例用户' : '合成用户'),
                        'balance' => '12.34', 'avatar' => '/fixture.svg',
                    ],
                    'config' => ['title' => $demo ? '松果数字商店' : '合成完整页面验证', 'keywords' => '', 'description' => '',
                        'shop_name' => $brandName ?? ($identity === 'long' ? '合成长名称的商品与服务商店' : '合成商店'),
                        'currency_symbol' => '¥', 'notice' => $demo
                            ? '欢迎来到松果数字商店。这里展示创意素材、学习资料与实用工具；商品、库存与价格均为演示数据。'
                            : '仅使用合成数据'],
                    'category' => $demo ? array_map(static fn($entry) => [
                        'id' => $entry[0], 'name' => $entry[1], 'commodity_count' => 2,
                        'icon' => '/app/View/User/Theme/Pika/Assets/brand-mark.svg', 'children' => [],
                    ], [[1, '创意素材'], [2, '学习资料'], [3, '实用工具']]) : [],
                    'categoryId' => 1, 'setting' => ['icp' => ''],
                ]);
                $storefronts[$identity . ($brandKey === 'default' ? '' : '/' . $brandKey)] = $smarty->fetch('string:' . $input['headerTemplate'])
                    . $smarty->fetch('string:' . $body($input['indexTemplate']))
                    . $smarty->fetch('string:' . $input['footerTemplate']);
            }
        }
        fwrite(STDOUT, json_encode(['item' => $item, 'pages' => $fixtures, 'index' => $index,
            'storefronts' => $storefronts, 'brandNames' => $brandNames], JSON_THROW_ON_ERROR));
    } else {
        fwrite(STDOUT, "PASS local theme config behavior\n");
    }
}
