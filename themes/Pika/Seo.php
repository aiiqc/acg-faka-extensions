<?php
declare(strict_types=1);

namespace App\View\User\Theme\Pika;

/** Presentation-only metadata from the data already supplied to the theme. */
final class Seo
{
    public static function page(array $config, array $categories = [], ?array $item = null, bool $business = false, bool $private = false, string $uri = '/'): array
    {
        $shop = self::text($config['shop_name'] ?? '');
        $home = [
            'title' => self::text($config['title'] ?? ''),
            'description' => self::text($config['description'] ?? ''),
            'heading' => self::text(($config['title'] ?? '') ?: ($config['shop_name'] ?? '')),
            'canonical' => '',
        ];
        $origin = $business || $private ? '' : self::origin($config['domain'] ?? '');
        $home['canonical'] = $origin === '' ? '' : $origin . '/';
        $map = [];
        $aliases = [];
        self::categories($categories, $shop, $origin, $map, $aliases);
        $route = self::route($uri);
        $page = $home;
        if ($private) {
            $page['canonical'] = '';
        } elseif ($item !== null) {
            $name = self::text($item['name'] ?? '');
            $page['title'] = $name . ($shop === '' ? '' : ' - ' . $shop);
            $page['heading'] = $name;
            $page['description'] = self::text($item['description'] ?? '', 160) ?: $name;
            $id = self::id($item['id'] ?? '');
            $page['canonical'] = $origin !== '' && $id !== '' && $route === '/item/' . $id
                ? $origin . $route : '';
        } elseif (str_starts_with($route, '/cat/')) {
            $id = substr($route, 5);
            $id = $aliases[$id] ?? $id;
            $page = $map[$id] ?? array_replace($home, ['canonical' => '']);
        } elseif ($route !== '/') {
            $page['canonical'] = '';
        }
        $page['client'] = json_encode(['home' => $home, 'categories' => $map, 'aliases' => $aliases], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $page;
    }

    private static function text(mixed $value, int $limit = 0): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        // ViewSafe escapes text once; owner HTML/description can remain raw.
        // Decode once, reduce to plain text, then let the template escape output.
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $value);
        $value = (string)preg_replace('#</?(?:p|div|br|li|h[1-6]|tr)\b[^>]*>#i', ' ', $value);
        $value = trim((string)preg_replace('/\s+/u', ' ', strip_tags($value)));
        return $limit > 0 ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
    }

    private static function id(mixed $value): string
    {
        return is_scalar($value) && preg_match('/^(?:[1-9][0-9]*|recommend)$/D', (string)$value) === 1 ? (string)$value : '';
    }

    private static function origin(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $domain = strtolower(trim($value));
        // The official admin field is a hostname[:port] list, not a URL. A
        // multi-domain or merchant setup needs an explicit owner decision.
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::([1-9][0-9]{0,4}))?$/D', $domain, $matches) !== 1
            || (isset($matches[1]) && (int)$matches[1] > 65535)) {
            return '';
        }
        $host = explode(':', $domain)[0];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\.(?:[0-9]+|localhost|local|internal|test|invalid)$/D', $host) === 1) {
            return '';
        }
        return 'https://' . preg_replace('/:443$/', '', $domain);
    }

    private static function route(string $uri): string
    {
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            return '';
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            return '';
        }
        $path = rtrim($parts['path'] ?? '', '/') ?: '/';
        parse_str($parts['query'] ?? '', $query);
        if (in_array($path, ['/', '/index.php'], true) && isset($query['s'])) {
            $path = is_string($query['s']) ? '/' . trim($query['s'], '/') : '';
        }
        if (in_array($path, ['/', '/index.php', '/user/index/index'], true)) {
            return isset($query['cid']) ? '/cat/' . self::id($query['cid']) : '/';
        }
        if ($path === '/user/index/item') {
            return '/item/' . self::id($query['mid'] ?? '');
        }
        return preg_match('#^/(?:cat/(?:[1-9][0-9]*|recommend)|item/[1-9][0-9]*)$#D', $path) === 1 ? $path : '';
    }

    private static function categories(array $categories, string $shop, string $origin, array &$map, array &$aliases): string
    {
        $first = '';
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $id = self::id($category['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!empty($category['children']) && is_array($category['children'])) {
                $child = self::categories($category['children'], $shop, $origin, $map, $aliases);
                if ($child !== '') {
                    $aliases[$id] = $child;
                    $first = $first ?: $child;
                }
                continue;
            }
            $name = self::text($category['name'] ?? '');
            if ((int)($category['commodity_count'] ?? 0) <= 0) {
                continue;
            }
            // Match the existing first-visible-leaf navigation even if that
            // leaf has no usable name; do not invent metadata for it.
            $first = $first ?: $id;
            if ($name === '') {
                continue;
            }
            $label = $name . ($shop === '' ? '' : ' - ' . $shop);
            $map[$id] = ['title' => $label, 'description' => $label, 'heading' => $name,
                'canonical' => $origin === '' ? '' : $origin . '/cat/' . $id];
        }
        return $first;
    }
}
