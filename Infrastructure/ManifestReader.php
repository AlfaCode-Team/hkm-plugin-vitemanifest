<?php

declare(strict_types=1);

namespace Plugins\ViteManifest\Infrastructure;

use Plugins\ViteManifest\Exceptions\ViteManifestNotFoundException;

/**
 * Loads and caches Vite build manifests. Replaces the old `Manifest.php`, which
 * hard-coded `public_path('dist/manifest.json')` and `app()->environment()`.
 * This reads a manifest by ABSOLUTE path (supplied by ViteConfig) and caches it
 * per-path. The cache is process-static (a manifest is a deploy-time artifact,
 * so it is safe to share across requests / OpenSwoole coroutines).
 *
 * @phpstan-type Chunk array{file: string, src?: string, isEntry?: bool, imports?: list<string>, css?: list<string>, integrity?: string}
 */
final class ManifestReader
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * mtime+size the cached entry was read at, so a redeployed manifest is
     * picked up without restarting the worker.
     *
     * @var array<string, array{int, int}>
     */
    private static array $stamp = [];

    /**
     * Return the decoded manifest at $path, cached by path.
     *
     * @return array<string, mixed>
     * @throws ViteManifestNotFoundException when the file is missing/invalid.
     */
    public function load(string $path): array
    {
        // Cached FOREVER previously, with no invalidation. Under a resident
        // worker (Swoole) a zero-downtime redeploy writes a new manifest with
        // new content hashes, but the worker kept emitting the old URLs — so
        // every asset 404'd until someone restarted the process, and the site
        // rendered unstyled and scriptless with nothing obviously wrong.
        //
        // Stat-invalidate on mtime AND size: mtime alone has one-second
        // granularity, so two writes inside the same second could otherwise be
        // missed. Same rule the environment loader's cache uses.
        $current = self::stampOf($path);

        if (isset(self::$cache[$path]) && (self::$stamp[$path] ?? null) === $current) {
            return self::$cache[$path];
        }

        if (!is_file($path)) {
            throw new ViteManifestNotFoundException("Vite manifest not found at: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new ViteManifestNotFoundException("Vite manifest is not valid JSON at: {$path}");
        }

        self::$stamp[$path] = self::stampOf($path);

        return self::$cache[$path] = $decoded;
    }

    /** @return array{int, int} mtime and size, or [0, 0] when absent. */
    private static function stampOf(string $path): array
    {
        $stat = @stat($path);

        return $stat === false ? [0, 0] : [(int) $stat['mtime'], (int) $stat['size']];
    }

    /**
     * Resolve a single chunk (manifest entry) by key.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function chunk(array $manifest, string $key): array
    {
        if (!isset($manifest[$key]) || !is_array($manifest[$key])) {
            throw new ViteManifestNotFoundException("Unable to locate file in Vite manifest: {$key}");
        }
        return $manifest[$key];
    }

    /** Drop the cache — tests only. */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
