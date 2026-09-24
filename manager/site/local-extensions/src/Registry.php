<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\Manager;

final class Registry
{
    /** @var array{extensions:array<string,array<string,mixed>>,themes:array<string,array<string,mixed>>}|null */
    private static ?array $cache = null;

    /** @return array<string,array<string,mixed>> */
    public static function extensions(): array
    {
        return self::load()['extensions'];
    }

    /** @return array<string,array<string,mixed>> */
    public static function themes(): array
    {
        return self::load()['themes'];
    }

    /** @return array<string,mixed> */
    public static function extension(string $id): array
    {
        $id = PathGuard::extensionId($id);
        $extension = self::extensions()[$id] ?? null;
        if (!is_array($extension)) {
            throw new \RuntimeException('Unknown local extension.');
        }
        return $extension;
    }

    public static function isTrustedTheme(string $themeKey): bool
    {
        try {
            $themeKey = PathGuard::themeId($themeKey);
        } catch (\RuntimeException) {
            return false;
        }
        return isset(self::themes()[$themeKey]);
    }

    /** @return array{extensions:array<string,array<string,mixed>>,themes:array<string,array<string,mixed>>} */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $siteRoot = PathGuard::siteRoot();
        $managerRoot = $siteRoot . '/local-extensions';
        $registryPath = PathGuard::immutableFileWithin($managerRoot, $managerRoot . '/registry.json');
        $registry = self::readJson($registryPath, 262144);
        if (($registry['schema'] ?? null) !== 1 || array_diff(array_keys($registry), ['schema', 'extensions', 'themes']) !== []) {
            throw new \RuntimeException('Local extension registry schema is invalid.');
        }

        $extensions = self::loadEntries($registry['extensions'] ?? [], false, $siteRoot, $managerRoot);
        $themes = self::loadEntries($registry['themes'] ?? [], true, $siteRoot, $managerRoot);
        self::$cache = ['extensions' => $extensions, 'themes' => $themes];
        return self::$cache;
    }

    /** @return array<string,array<string,mixed>> */
    private static function loadEntries(mixed $entries, bool $themes, string $siteRoot, string $managerRoot): array
    {
        if (!is_array($entries) || count($entries) > 256) {
            throw new \RuntimeException('Local extension registry entries are invalid.');
        }

        $loaded = [];
        foreach (array_values($entries) as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['id', 'manifest', 'manifest_sha256']) !== []) {
                throw new \RuntimeException('Local extension registry entry is invalid.');
            }
            $id = $themes
                ? PathGuard::themeId((string)($entry['id'] ?? ''))
                : PathGuard::extensionId((string)($entry['id'] ?? ''));
            if (isset($loaded[$id])) {
                throw new \RuntimeException('Duplicate local extension registry identifier.');
            }

            $expectedRelative = $themes
                ? 'app/View/User/Theme/' . $id . '/theme.json'
                : 'extensions/' . $id . '/local-extension.json';
            if (($entry['manifest'] ?? null) !== $expectedRelative
                || !is_string($entry['manifest_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['manifest_sha256']) !== 1) {
                throw new \RuntimeException('Local extension registry binding is invalid.');
            }

            $root = $themes ? $siteRoot . '/app/View/User/Theme/' . $id : $managerRoot . '/extensions/' . $id;
            $candidate = $themes ? $siteRoot . '/' . $expectedRelative : $managerRoot . '/' . $expectedRelative;
            $manifestPath = PathGuard::immutableFileWithin($root, $candidate);
            $actualHash = hash_file('sha256', $manifestPath);
            if (!is_string($actualHash) || !hash_equals($entry['manifest_sha256'], $actualHash)) {
                throw new \RuntimeException('Local extension manifest hash mismatch.');
            }

            $manifest = self::readJson($manifestPath, 262144);
            $normalized = $themes ? ManifestValidator::theme($manifest, $id) : ManifestValidator::plugin($manifest, $id);
            $normalized['manifest_path'] = $manifestPath;
            $normalized['root'] = dirname($manifestPath);
            $loaded[$id] = $normalized;
        }

        return $loaded;
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path, int $maxBytes): array
    {
        $size = filesize($path);
        if ($size === false || $size < 2 || $size > $maxBytes) {
            throw new \RuntimeException('Local extension JSON file size is invalid.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to read local extension JSON file.');
        }
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Local extension JSON is invalid.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('Local extension JSON root must be an object.');
        }
        return $decoded;
    }
}
