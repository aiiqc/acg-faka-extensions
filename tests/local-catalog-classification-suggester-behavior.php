<?php
declare(strict_types=1);

use Pika\LocalExtensions\PikaCatalogHub\Service\ClassificationSuggester;

function classificationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function classificationFails(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

$extensionRoot = dirname(__DIR__) . '/extensions/PikaCatalogHub';
$registered = require $extensionRoot . '/bootstrap.php';
classificationExpect($registered === true, 'CatalogHub bootstrap did not register its loader');
classificationExpect(class_exists(ClassificationSuggester::class), 'ClassificationSuggester did not autoload');

/** @var array<string,array{count:int,group:string,family:string,confidence:string}> $fixture */
$fixture = [
    'AI Chat-GPT' => ['count' => 9, 'group' => 'AI工具', 'family' => 'GPT', 'confidence' => 'high'],
    'AI Claude' => ['count' => 1, 'group' => 'AI工具', 'family' => 'Claude', 'confidence' => 'high'],
    'AI Gemini' => ['count' => 3, 'group' => 'AI工具', 'family' => 'Gemini', 'confidence' => 'high'],
    '(X)Twitter 新增' => ['count' => 7, 'group' => 'Twitter X', 'family' => '', 'confidence' => 'high'],
    '(X)Twitter （粉丝号）' => ['count' => 7, 'group' => 'Twitter X', 'family' => '', 'confidence' => 'high'],
    '21年-23年千粉号带作品【未开橱窗】' => ['count' => 20, 'group' => 'TikTok', 'family' => '', 'confidence' => 'low'],
    '23年千粉号【部分带随机作品】' => ['count' => 21, 'group' => 'TikTok', 'family' => '', 'confidence' => 'low'],
    'Tiktok | 15-20年（下机）' => ['count' => 17, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 15-20年（白号）' => ['count' => 19, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 16-23年（带粉）' => ['count' => 27, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 21-23年（下机）' => ['count' => 22, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 21-23年（白号）' => ['count' => 21, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 21-23年（白号）（千粉）' => ['count' => 20, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 23年 (老号)（千粉）带作品' => ['count' => 13, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 半年 (白号)【微软邮箱】' => ['count' => 32, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 多种时间 (API )（千粉）带作品' => ['count' => 13, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 多种时间 (API )（真机）带2fa' => ['count' => 17, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 满年 (白号)【微软邮箱】' => ['count' => 19, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 满年 (白号)（千粉）' => ['count' => 25, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 满月 (白号)【微软邮箱】' => ['count' => 81, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 满月 (白号)【邮箱令牌】' => ['count' => 19, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 满月 (白号)（千粉）' => ['count' => 47, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 满月 (白号)（千粉）下机号' => ['count' => 19, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 满月 (白号)（千粉）带橱窗' => ['count' => 7, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'Tiktok | 直播权限(双端) 直播伴侣权限' => ['count' => 13, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'TIKTOK特价号(google邮箱注册)-购买前请看商品说明' => ['count' => 11, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'TK各区千粉万粉自然流/中视频退役号【可自助选号】' => ['count' => 37, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'TK各区直播包推流号' => ['count' => 9, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'TK带作品千粉/api注册千粉/域名注册千粉' => ['count' => 15, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    'TK满月千粉[涨粉后静置时间大于20天]' => ['count' => 25, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 15-20年（下机）【带作品号】' => ['count' => 21, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 15-20年（白号）' => ['count' => 27, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 16-23年（下机）【随机粉丝】' => ['count' => 30, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 21-23年（下机）【带作品号】' => ['count' => 30, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 21-23年（白号）' => ['count' => 31, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 三月 (白号)【微软邮箱】' => ['count' => 21, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 半年 (白号)【微软邮箱】' => ['count' => 57, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满年 (白号)【微软邮箱】' => ['count' => 97, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满年 (白号)（千粉）' => ['count' => 28, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (下机)【随机作品】' => ['count' => 37, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (下机)（千粉）【随机作品】' => ['count' => 20, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (白号)【user名字】' => ['count' => 65, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (白号)【接码链接】' => ['count' => 30, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (白号)【真机注册】' => ['count' => 22, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (白号)【邮箱令牌】' => ['count' => 36, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (白号)【随机名字】' => ['count' => 105, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (白号)（千粉）' => ['count' => 45, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '♪ Tiktok | 满月 (白号)（千粉）【已开橱窗】' => ['count' => 15, 'group' => 'TikTok', 'family' => '', 'confidence' => 'high'],
    '双权号/直播伴侣权限号/伴侣强开' => ['count' => 18, 'group' => 'TikTok', 'family' => '', 'confidence' => 'low'],
    '满半年满年带随机粉丝号' => ['count' => 34, 'group' => 'TikTok', 'family' => '', 'confidence' => 'low'],
    'Telegram｜内置代理（无视库存）' => ['count' => 12, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ Telegram ｜API ｢真机账号｣' => ['count' => 12, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ Telegram ｜会员 频道 实名' => ['count' => 17, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 协议+Tdata+API ( 60天+)' => ['count' => 126, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 协议+Tdata+API (180天+)' => ['count' => 137, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 协议+Tdata+API (3-4年+)' => ['count' => 123, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 协议+Tdata+API (360天+)' => ['count' => 237, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 协议+Tdata+API (5年+)' => ['count' => 159, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 协议+Tdata+API (7年+)' => ['count' => 56, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 协议+Tdata+API（0-60天）' => ['count' => 100, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '✈️ TG 飞机 会员盲盒 靓号' => ['count' => 11, 'group' => 'Telegram', 'family' => '', 'confidence' => 'high'],
    '📘 Facebook BM' => ['count' => 28, 'group' => 'Facebook', 'family' => '', 'confidence' => 'high'],
    '📘 Facebook 指定国家（老外）' => ['count' => 716, 'group' => 'Facebook', 'family' => '', 'confidence' => 'high'],
    '📘 Facebook 新增' => ['count' => 65, 'group' => 'Facebook', 'family' => '', 'confidence' => 'high'],
    '📘 Facebook 粉丝页' => ['count' => 1, 'group' => 'Facebook', 'family' => '', 'confidence' => 'high'],
    '📘 Facebook 蓝标' => ['count' => 16, 'group' => 'Facebook', 'family' => '', 'confidence' => 'high'],
    '📸 Instagram 1️⃣' => ['count' => 29, 'group' => 'Instagram', 'family' => '', 'confidence' => 'high'],
    '📸 Instagram 2️⃣' => ['count' => 257, 'group' => 'Instagram', 'family' => '', 'confidence' => 'high'],
    '📸 Instagram 国家' => ['count' => 44, 'group' => 'Instagram', 'family' => '', 'confidence' => 'high'],
    '📸 Instagram 带帖' => ['count' => 2, 'group' => 'Instagram', 'family' => '', 'confidence' => 'high'],
    '📸 Instagram 带粉' => ['count' => 10, 'group' => 'Instagram', 'family' => '', 'confidence' => 'high'],
    '📸 Instagram 自营' => ['count' => 1, 'group' => 'Instagram', 'family' => '', 'confidence' => 'high'],
    '📸 Instagram 黑号' => ['count' => 6, 'group' => 'Instagram', 'family' => '', 'confidence' => 'high'],
    '📸 Threads' => ['count' => 115, 'group' => 'Threads', 'family' => '', 'confidence' => 'high'],
    '📸 Threads 自营' => ['count' => 2, 'group' => 'Threads', 'family' => '', 'confidence' => 'high'],
    '👔 Linkedin｜领英1' => ['count' => 9, 'group' => 'LinkedIn', 'family' => '', 'confidence' => 'high'],
    '👔 Linkedin｜领英2' => ['count' => 12, 'group' => 'LinkedIn', 'family' => '', 'confidence' => 'high'],
    'Reddit' => ['count' => 56, 'group' => 'Reddit', 'family' => '', 'confidence' => 'high'],
    'Snapchat' => ['count' => 7, 'group' => 'Snapchat', 'family' => '', 'confidence' => 'high'],
    '😈 Discord｜推荐购买' => ['count' => 9, 'group' => 'Discord', 'family' => '', 'confidence' => 'high'],
    '😈 Discord｜推荐购买2' => ['count' => 56, 'group' => 'Discord', 'family' => '', 'confidence' => 'high'],
    'Apple ID丨下载号-带双重(未激活iC)' => ['count' => 5, 'group' => 'Apple ID', 'family' => '', 'confidence' => 'high'],
    'Apple ID丨下载号-带密保(未激活iC)' => ['count' => 23, 'group' => 'Apple ID', 'family' => '', 'confidence' => 'high'],
    'Apple ID丨小火箭 下载号 租赁' => ['count' => 3, 'group' => 'Apple ID', 'family' => '', 'confidence' => 'high'],
    'Apple ID丨小火箭 圈X 代理工具' => ['count' => 8, 'group' => 'Apple ID', 'family' => '', 'confidence' => 'high'],
    'Google Gmail | 谷歌邮箱' => ['count' => 74, 'group' => '邮箱', 'family' => 'Gmail', 'confidence' => 'high'],
    '📩 Outlook | 微软邮箱' => ['count' => 4, 'group' => '邮箱', 'family' => 'Outlook', 'confidence' => 'high'],
    'SMS短信接码通道' => ['count' => 6, 'group' => '短信接码', 'family' => '', 'confidence' => 'high'],
    'SMS｜API接码 注册 辅助 解封' => ['count' => 7, 'group' => '短信接码', 'family' => '', 'confidence' => 'high'],
    'esim｜流量卡 保号卡 手机卡' => ['count' => 3, 'group' => 'eSIM', 'family' => '', 'confidence' => 'high'],
    'Tron｜波场靓号 能量租赁' => ['count' => 8, 'group' => 'TRON', 'family' => '', 'confidence' => 'high'],
    '卡网 机器人 号铺 收款 带搭建' => ['count' => 6, 'group' => '其他', 'family' => '', 'confidence' => 'low'],
    '号商管理工具' => ['count' => 3, 'group' => '其他', 'family' => '', 'confidence' => 'low'],
    '补差价｜预付款' => ['count' => 1, 'group' => '其他', 'family' => '', 'confidence' => 'low'],
];

classificationExpect(count($fixture) === 94, 'the S0 fixture must retain all 94 upstream categories');
classificationExpect(
    array_sum(array_column($fixture, 'count')) === 3947,
    'the S0 fixture must retain the 3947-item inventory total',
);

$catalog = [];
$sequence = 0;
foreach ($fixture as $category => $expected) {
    for ($index = 0; $index < $expected['count']; $index++) {
        $catalog[] = [
            'code' => (string)++$sequence,
            'category' => $category,
            // Deliberately conflicting product text proves category-first behavior.
            'name' => 'Facebook Instagram ChatGPT product text must be ignored',
        ];
    }
}

$suggester = new ClassificationSuggester();
$plan = $suggester->suggest($catalog);
classificationExpect($plan['schema'] === 1, 'classification suggestion schema changed unexpectedly');
classificationExpect($plan['counts'] === [
    'items' => 3947,
    'categories' => 94,
    'high' => 87,
    'low' => 7,
], 'classification summary counts are wrong');
classificationExpect(strlen($plan['plan_hash']) === 64, 'plan hash must be full SHA-256');

$byName = [];
$previous = null;
foreach ($plan['categories'] as $entry) {
    classificationExpect(!isset($byName[$entry['name']]), 'a category received more than one suggestion');
    if ($previous !== null) {
        $foldedOrder = strcmp(
            mb_strtolower($previous, 'UTF-8'),
            mb_strtolower($entry['name'], 'UTF-8'),
        );
        classificationExpect(
            $foldedOrder < 0 || ($foldedOrder === 0 && strcmp($previous, $entry['name']) < 0),
            'category suggestions are not deterministically sorted',
        );
    }
    $previous = $entry['name'];
    $byName[$entry['name']] = $entry;
}
foreach ($fixture as $category => $expected) {
    classificationExpect(isset($byName[$category]), "missing S0 category suggestion: {$category}");
    $actual = $byName[$category];
    classificationExpect($actual['count'] === $expected['count'], "wrong item count for: {$category}");
    classificationExpect($actual['target'] === [
        'group' => $expected['group'],
        'family' => $expected['family'],
    ], "wrong target for: {$category}");
    classificationExpect($actual['confidence'] === $expected['confidence'], "wrong confidence for: {$category}");
}

$reordered = array_reverse($catalog);
$reorderedPlan = $suggester->suggest($reordered);
classificationExpect($reorderedPlan === $plan, 'catalog order changed suggestions or plan hash');

$categoryWins = $suggester->suggest([
    ['category' => 'Facebook', 'name' => 'Instagram ChatGPT'],
    ['category' => 'Instagram', 'name' => 'Facebook ChatGPT'],
    ['category' => '未知平台', 'name' => 'Facebook Instagram ChatGPT'],
    ['category' => '123', 'name' => 'Facebook Instagram ChatGPT'],
]);
classificationExpect($categoryWins['counts']['categories'] === 4, 'category-first fixture collapsed unexpectedly');
$categoryTargets = [];
foreach ($categoryWins['categories'] as $entry) {
    $categoryTargets[$entry['name']] = [$entry['target'], $entry['confidence']];
}
classificationExpect(
    $categoryTargets['Facebook'] === [['group' => 'Facebook', 'family' => ''], 'high'],
    'product name created a Facebook classification conflict',
);
classificationExpect(
    $categoryTargets['Instagram'] === [['group' => 'Instagram', 'family' => ''], 'high'],
    'product name created an Instagram classification conflict',
);
classificationExpect(
    $categoryTargets['未知平台'] === [['group' => '其他', 'family' => ''], 'low'],
    'product name promoted an unknown category to high confidence',
);
classificationExpect(
    $categoryTargets['123'] === [['group' => '其他', 'family' => ''], 'low'],
    'numeric-only category names must remain valid strings',
);

$platformDefaults = $suggester->suggest([
    ['code' => 'wa-1', 'category' => 'WhatsApp 月租', 'name' => 'ignored'],
    ['code' => 'line-1', 'category' => 'LINE 账号', 'name' => 'ignored'],
    ['code' => 'zalo-1', 'category' => 'Zalo 账号', 'name' => 'ignored'],
    ['code' => 'kakao-1', 'category' => 'KakaoTalk 账号', 'name' => 'ignored'],
    ['code' => 'online-1', 'category' => 'Online service', 'name' => 'LINE must not match inside online'],
]);
$platformTargets = [];
foreach ($platformDefaults['categories'] as $entry) {
    $platformTargets[$entry['name']] = [$entry['target'], $entry['confidence']];
}
foreach (['WhatsApp 月租' => 'WhatsApp', 'LINE 账号' => 'LINE', 'Zalo 账号' => 'Zalo', 'KakaoTalk 账号' => 'Kakao'] as $category => $group) {
    classificationExpect(
        $platformTargets[$category] === [['group' => $group, 'family' => ''], 'high'],
        "missing public platform default for: {$category}",
    );
}
classificationExpect(
    $platformTargets['Online service'] === [['group' => '其他', 'family' => ''], 'low'],
    'LINE default must not match inside a longer ASCII word',
);

classificationFails(static fn() => $suggester->suggest([]), 'empty catalog must fail closed');
classificationFails(
    static fn() => $suggester->suggest(array_fill(0, 10001, ['category' => 'Facebook'])),
    'more than 10000 items must fail closed',
);
$tooManyCategories = [];
for ($index = 1; $index <= 201; $index++) {
    $tooManyCategories[] = ['category' => '平台' . $index];
}
classificationFails(
    static fn() => $suggester->suggest($tooManyCategories),
    'more than 200 categories must fail closed',
);
classificationFails(static fn() => $suggester->suggest(['invalid']), 'non-array item must fail closed');
classificationFails(static fn() => $suggester->suggest([[]]), 'missing category must fail closed');
classificationFails(
    static fn() => $suggester->suggest([['category' => "Facebook\tBM"]]),
    'category controls must fail closed',
);
classificationFails(
    static fn() => $suggester->suggest([['category' => str_repeat('类', 129)]]),
    'category longer than 128 characters must fail closed',
);
classificationFails(
    static fn() => $suggester->suggest([['category' => "\xC3\x28"]]),
    'invalid UTF-8 category must fail closed',
);

require dirname(__DIR__) . '/extensions/PikaSupplySync/bootstrap.php';
$mirror = new \Pika\LocalExtensions\PikaSupplySync\Service\UpstreamCategoryTree();
$tree = ['schema' => 1, 'capability' => 'pika_category_tree', 'categories' => [
    ['id' => 1, 'pid' => 0, 'name' => 'Telegram', 'sort' => 0],
    ['id' => 2, 'pid' => 1, 'name' => '货源A', 'sort' => 1],
    ['id' => 3, 'pid' => 2, 'name' => '同名/分类', 'sort' => 2],
    ['id' => 4, 'pid' => 1, 'name' => '同名/分类', 'sort' => 3],
], 'items' => [
    ['code' => 'synthetic-1', 'category_id' => 3, 'name' => 'synthetic', 'stock' => 1],
    ['code' => 'synthetic-2', 'category_id' => 4, 'name' => 'synthetic', 'stock' => 2],
]];
$mirrorCatalog = $mirror->flatten($tree);
$mirrorPlan = $mirror->suggest($mirrorCatalog);
classificationExpect(count($mirrorPlan['categories']) === 2, 'mirror conflated equal leaf names');
classificationExpect(array_column($mirrorCatalog['synthetic-1']['target']['path'], 'name') === ['Telegram', '货源A', '同名/分类'], 'mirror inserted an alias or changed real upstream ancestors');
$reordered = $tree;
$reordered['categories'] = array_reverse($reordered['categories']);
$reordered['items'] = array_reverse($reordered['items']);
classificationExpect($mirror->suggest($mirror->flatten($reordered)) === $mirrorPlan, 'mirror order changed its frozen identity');
$renamedProduct = $tree;
$renamedProduct['items'][0]['name'] = 'not persisted';
classificationExpect($mirror->suggest($mirror->flatten($renamedProduct)) === $mirrorPlan, 'product display name unexpectedly entered durable plan');
$treeV2 = $tree;
$treeV2['schema'] = 2;
foreach ($treeV2['items'] as &$v2Item) {
    unset($v2Item['name']);
}
unset($v2Item);
$catalogV2 = $mirror->flatten($treeV2);
classificationExpect(array_keys($catalogV2) === array_keys($mirrorCatalog)
    && count($catalogV2) === count($mirrorCatalog), 'v2 lost product identities or rows');
foreach ($catalogV2 as $row) {
    classificationExpect(!array_key_exists('name', $row), 'v2 fabricated an unused product name');
}
classificationExpect($mirror->suggest($catalogV2) === $mirrorPlan,
    'v1 and v2 produced different category bindings or plan hashes');
$iconCatalog = $mirror->flatten($treeV2, true);
$iconPlan = $mirror->suggest($iconCatalog);
classificationExpect($iconPlan['plan_hash'] !== $mirrorPlan['plan_hash']
    && $iconPlan['counts'] === $mirrorPlan['counts'], 'icon capability was omitted from the frozen plan identity');
foreach ($iconCatalog as $code => $row) {
    $target = $row['target'];
    classificationExpect($target['category_icons'] === true
        && $mirror::normalizeTarget(json_decode(json_encode($target, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR)) === $target,
        'new icon target did not survive strict snapshot rereading');
    unset($target['category_icons']);
    classificationExpect($target === $catalogV2[$code]['target']
        && $mirror::normalizeTarget($target) === $target, 'old icon-free target bytes or structure changed');
}
foreach ([false, 1, 'true', null, []] as $capability) {
    $badTarget = $catalogV2['synthetic-1']['target'] + ['category_icons' => $capability];
    classificationFails(static fn() => $mirror::normalizeTarget($badTarget), 'non-true icon capability entered a frozen target');
}
$iconSnapshot = ['schema' => 3, 'capability' => 'pika_category_icons', 'categories' => [
    $treeV2['categories'][0] + ['icon' => 'https://images.example.invalid/root.png'],
    $treeV2['categories'][2] + ['icon' => '/assets/leaf.png'],
]];
$iconNodes = $mirror::iconNodes($iconSnapshot, [3, 1]);
classificationExpect(array_keys($iconNodes) === [1, 3] && $iconNodes[3]['pid'] === 2
    && $iconNodes[3]['icon'] === '/assets/leaf.png', 'selected icons required unrequested parents or changed identity');
classificationFails(static fn() => $mirror->flatten($iconSnapshot), 'icon metadata entered the frozen import protocol');
classificationExpect($mirror->suggest($mirror->flatten($treeV2)) === $mirrorPlan,
    'reading icon metadata changed the old mirror plan');
foreach (['', '/favicon.ico', 'assets/relative.png', 'https://images.example.invalid/icon.png'] as $icon) {
    $validIcons = $iconSnapshot;
    $validIcons['categories'][0]['icon'] = $icon;
    classificationExpect($mirror::iconNodes($validIcons, [1, 3])[1]['icon'] === $icon,
        'valid or missing category icon changed unexpectedly');
}
foreach ([null, 1, false, [], str_repeat('a', 2049), 'fixture space.png', "fixture\tspace.png", "fixture\x01.png", "fixture\u{200B}.png",
    "fixture\u{2028}.png", 'assets\\icon.png', '//images.example.invalid/icon.png',
    'http://images.example.invalid/icon.png', 'data:image/png;base64,AA==', 'file:///tmp/icon.png',
    ' https://images.example.invalid/icon.png', "\xC3\x28"] as $icon) {
    $badIcons = $iconSnapshot;
    $badIcons['categories'][0]['icon'] = $icon;
    classificationFails(static fn() => $mirror::iconNodes($badIcons, [1, 3]), 'unsafe category icon accepted');
}
foreach (['extra-root', 'items', 'missing-icon', 'extra-node', 'duplicate', 'wrong-id', 'numeric-id',
    'missing-node', 'string-schema', 'old-capability', 'old-schema', 'category-name'] as $case) {
    $badIcons = $iconSnapshot;
    switch ($case) {
        case 'extra-root': $badIcons['extra'] = true; break;
        case 'items': $badIcons['items'] = []; break;
        case 'missing-icon': unset($badIcons['categories'][0]['icon']); break;
        case 'extra-node': $badIcons['categories'][0]['owner'] = 0; break;
        case 'duplicate': $badIcons['categories'][1] = $badIcons['categories'][0]; break;
        case 'wrong-id': $badIcons['categories'][1]['id'] = 4; break;
        case 'numeric-id': $badIcons['categories'][0]['id'] = '1'; break;
        case 'missing-node': array_pop($badIcons['categories']); break;
        case 'string-schema': $badIcons['schema'] = '3'; break;
        case 'old-capability': $badIcons['capability'] = 'pika_category_tree'; break;
        case 'old-schema': $badIcons['schema'] = 2; break;
        case 'category-name': $badIcons['categories'][0]['name'] = "bad\u{200B}category"; break;
    }
    classificationFails(static fn() => $mirror::iconNodes($badIcons, [1, 3]), 'invalid icon metadata accepted: ' . $case);
}
foreach ([[], [1, 1], [0], [-1], [2147483648], ['1'], [true], [1 => 1], range(1, 101)] as $ids) {
    classificationFails(static fn() => $mirror::iconIds($ids), 'invalid icon request identities accepted');
}
classificationExpect($mirror::iconIds(range(100, 1)) === range(1, 100)
    && $mirror::iconIds([2147483647]) === [2147483647], 'bounded icon identity limits changed');
foreach ([$tree, $treeV2] as $oldSnapshot) {
    $oldSnapshot['categories'][0]['icon'] = '/assets/not-in-old-schema.png';
    classificationFails(static fn() => $mirror->flatten($oldSnapshot), 'icon was silently accepted in an old strict schema');
}
foreach (["fixture\x01control", "fixture\u{0085}control", "fixture\u{200B}format",
    "fixture\u{2028}line", "fixture\u{2029}paragraph"] as $forbiddenName) {
    $invalidV1 = $tree;
    $invalidV1['items'][0]['name'] = $forbiddenName;
    classificationFails(static fn() => $mirror->flatten($invalidV1), 'v1 product-name gate was weakened');
}
foreach (['name', 'missing-key', 'unknown-schema', 'string-schema', 'invalid-code', 'duplicate-code',
    'string-stock', 'negative-stock', 'overflow-stock', 'invalid-category-name', 'missing-parent',
    'cycle', 'unknown-item-key', 'unknown-root-key', 'unknown-category-key', 'wrong-category'] as $case) {
    $badV2 = $treeV2;
    switch ($case) {
        case 'name': $badV2['items'][0]['name'] = 'not part of v2'; break;
        case 'missing-key': unset($badV2['items'][0]['stock']); break;
        case 'unknown-schema': $badV2['schema'] = 3; break;
        case 'string-schema': $badV2['schema'] = '2'; break;
        case 'invalid-code': $badV2['items'][0]['code'] = "fixture\ncode"; break;
        case 'duplicate-code': $badV2['items'][1]['code'] = $badV2['items'][0]['code']; break;
        case 'string-stock': $badV2['items'][0]['stock'] = '1'; break;
        case 'negative-stock': $badV2['items'][0]['stock'] = -1; break;
        case 'overflow-stock': $badV2['items'][0]['stock'] = 2147483648; break;
        case 'invalid-category-name': $badV2['categories'][0]['name'] = "fixture\u{200B}category"; break;
        case 'missing-parent': array_shift($badV2['categories']); break;
        case 'cycle': $badV2['categories'][0]['pid'] = 3; break;
        case 'unknown-item-key': $badV2['items'][0]['config'] = []; break;
        case 'unknown-root-key': $badV2['extra'] = true; break;
        case 'unknown-category-key': $badV2['categories'][0]['owner'] = 0; break;
        case 'wrong-category': $badV2['items'][0]['category_id'] = 999; break;
    }
    classificationFails(static fn() => $mirror->flatten($badV2), 'invalid v2 snapshot accepted: ' . $case);
}
$rebound = $tree;
$rebound['items'][0]['category_id'] = 4;
$rebound['items'][1]['category_id'] = 3;
classificationExpect($mirror->suggest($mirror->flatten($rebound))['plan_hash'] !== $mirrorPlan['plan_hash'], 'product-to-category binding was omitted from mirror identity');
foreach (['unsupported', 'missing', 'cycle', 'duplicate', 'extra', 'numeric-string', 'unknown-field', 'wrong-product-category'] as $case) {
    $bad = $tree;
    switch ($case) {
        case 'unsupported': unset($bad['capability']); break;
        case 'missing': array_shift($bad['categories']); break;
        case 'cycle': $bad['categories'][0]['pid'] = 3; break;
        case 'duplicate': $bad['categories'][] = $bad['categories'][0]; break;
        case 'extra': $bad['categories'][] = ['id' => 5, 'pid' => 0, 'name' => 'not authorized branch', 'sort' => 0]; break;
        case 'numeric-string': $bad['categories'][0]['id'] = '1'; break;
        case 'unknown-field': $bad['items'][0]['config'] = []; break;
        case 'wrong-product-category': $bad['items'][0]['category_id'] = 999; break;
    }
    classificationFails(static fn() => $mirror->flatten($bad), 'invalid mirror snapshot accepted: ' . $case);
}
$conflict = $mirrorCatalog;
$conflict['synthetic-2']['target']['path'][0]['name'] = 'conflicting parent';
classificationFails(static fn() => $mirror->suggest($conflict), 'conflicting cross-path ancestor accepted');
classificationFails(static fn() => $mirror->flatten([['id' => 1, 'name' => 'native list', 'children' => []]]), 'unsupported native list silently used as mirror');

// Only synthetic names and identities are used for the independent tree budget.
$capacityTree = ['schema' => 1, 'capability' => 'pika_category_tree', 'categories' => [
    ['id' => 1, 'pid' => 0, 'name' => 'fixture-capacity-root', 'sort' => 0],
    ['id' => 2, 'pid' => 1, 'name' => 'fixture-capacity-branch-a', 'sort' => 0],
    ['id' => 3, 'pid' => 1, 'name' => 'fixture-capacity-branch-b', 'sort' => 1],
], 'items' => []];
for ($index = 0; $index < 56; $index++) {
    $capacityTree['categories'][] = ['id' => 4 + $index, 'pid' => 2 + ($index % 2),
        'name' => 'fixture-capacity-parent-' . $index, 'sort' => $index];
}
for ($index = 0; $index < 155; $index++) {
    $id = 60 + $index;
    $capacityTree['categories'][] = ['id' => $id, 'pid' => 4 + ($index % 56),
        'name' => 'fixture-capacity-leaf-' . $index, 'sort' => $index];
    $capacityTree['items'][] = ['code' => 'fixture-capacity-item-' . $index,
        'category_id' => $id, 'name' => 'fixture-capacity-item', 'stock' => 1];
}
classificationExpect(count($capacityTree['categories']) === 214 && count($capacityTree['items']) === 155,
    'capacity fixture must contain 155 product categories and 59 pure ancestors');
// This call is the red regression on 142: C=214 used to consume the L=200 budget.
$capacityCatalog = $mirror->flatten($capacityTree);
$capacityPlan = $mirror->suggest($capacityCatalog);
$capacityNodeIds = [];
foreach ($capacityCatalog as $row) {
    classificationExpect(count($row['target']['path']) === 4, 'capacity fixture depth must remain four');
    foreach ($row['target']['path'] as $node) $capacityNodeIds[$node['id']] = true;
}
classificationExpect(count($capacityNodeIds) === 214 && count($capacityCatalog) === 155
    && $capacityPlan['counts'] === ['items' => 155, 'categories' => 155, 'high' => 155, 'low' => 0],
    'mirror lost or duplicated shared ancestors in the 214-node tree');
classificationExpect($mirror->suggest(array_reverse($capacityCatalog, true)) === $capacityPlan,
    'shared-ancestor capacity plan changed when product order was reversed');

$mirrorLimitFails = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        classificationExpect($exception->getMessage() === 'PIKA_TREE_LIMIT_EXCEEDED',
            $message . ': rejected by a different gate');
        return;
    }
    throw new RuntimeException($message);
};
// Build direct suggestion input independently of flatten, so its own gate is exercised.
$mirrorChains = static function (int $chains, int $depth): array {
    $snapshot = ['schema' => 1, 'capability' => 'pika_category_tree', 'categories' => [], 'items' => []];
    $catalog = [];
    for ($chain = 0; $chain < $chains; $chain++) {
        $path = [];
        for ($level = 0; $level < $depth; $level++) {
            $id = $chain * $depth + $level + 1;
            $node = ['id' => $id, 'pid' => $level === 0 ? 0 : $id - 1,
                'name' => 'fixture-chain-node-' . $id, 'sort' => 0];
            $snapshot['categories'][] = $node;
            $path[] = $node;
        }
        $code = 'fixture-chain-item-' . $chain;
        $snapshot['items'][] = ['code' => $code, 'category_id' => $id, 'name' => 'fixture-chain-item', 'stock' => 1];
        $catalog[$code] = ['code' => $code, 'name' => 'fixture-chain-item', 'category' => $node['name'],
            'stock' => 1, 'item' => [], 'target' => ['mode' => 'mirror', 'path' => $path]];
    }
    return [$snapshot, $catalog];
};
[$treeAtLimit, $catalogAtLimit] = $mirrorChains(32, 64);
classificationExpect(count($treeAtLimit['categories']) === 2048 && count($treeAtLimit['items']) === 32,
    'tree boundary fixture must leave item, product-category and depth budgets below their limits');
classificationExpect(count($mirror->flatten($treeAtLimit)) === 32
    && $mirror->suggest($catalogAtLimit)['counts']['categories'] === 32,
    'exactly 2048 unique tree nodes must be accepted by flatten and direct suggest');
$treeOverLimit = $treeAtLimit;
$catalogOverLimit = $catalogAtLimit;
$extraNode = ['id' => 2049, 'pid' => 64, 'name' => 'fixture-chain-node-2049', 'sort' => 0];
$treeOverLimit['categories'][] = $extraNode;
$treeOverLimit['items'][0]['category_id'] = $extraNode['id'];
$catalogOverLimit['fixture-chain-item-0']['category'] = $extraNode['name'];
$catalogOverLimit['fixture-chain-item-0']['target']['path'][] = $extraNode;
classificationExpect(count($treeOverLimit['categories']) === 2049
    && count($catalogOverLimit['fixture-chain-item-0']['target']['path']) === 65,
    'tree overflow fixture must exceed only the independent tree-node budget');
$mirrorLimitFails(static fn() => $mirror->flatten($treeOverLimit), 'flatten accepted 2049 unique tree nodes');
$mirrorLimitFails(static fn() => $mirror->suggest($catalogOverLimit), 'direct suggest accepted 2049 unique tree nodes');

[$productLimitTree, $productLimitCatalog] = $mirrorChains(200, 1);
$productLimitTree['items'][] = array_replace($productLimitTree['items'][0], ['code' => 'fixture-second-item-same-category']);
$productLimitCatalog['fixture-second-item-same-category'] = array_replace(
    $productLimitCatalog['fixture-chain-item-0'], ['code' => 'fixture-second-item-same-category'],
);
classificationExpect(count($mirror->flatten($productLimitTree)) === 201
    && $mirror->suggest($productLimitCatalog)['counts']['categories'] === 200,
    'exactly 200 direct product categories must remain accepted even with more than 200 items');
[$productOverflowTree, $productOverflowCatalog] = $mirrorChains(201, 1);
classificationExpect(count($productOverflowTree['categories']) === 201,
    'product-category overflow must remain below the independent tree-node budget');
$mirrorLimitFails(static fn() => $mirror->flatten($productOverflowTree),
    'flatten accepted 201 direct product categories below the tree-node budget');
$mirrorLimitFails(static fn() => $mirror->suggest($productOverflowCatalog),
    'direct suggest accepted 201 product categories below the tree-node budget');

echo "PikaCatalogHub classification suggester and mirror tree behavior tests passed\n";
