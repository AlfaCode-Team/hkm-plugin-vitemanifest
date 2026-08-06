<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\ViteManifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\ViteManifest\Infrastructure\ManifestReader;

/**
 * Regression cover for VM-02: the manifest was cached per path forever with no
 * invalidation, so a resident worker kept emitting the previous deploy's hashed
 * URLs — every asset 404ing — until the process was restarted.
 */
#[CoversClass(ManifestReader::class)]
final class ManifestCacheTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hkm-vite-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function write(string $file): void
    {
        file_put_contents($this->path, json_encode(['app.js' => ['file' => $file]]));
    }

    public function test_a_redeployed_manifest_is_picked_up(): void
    {
        $reader = new ManifestReader();

        $this->write('assets/app.OLDHASH.js');
        self::assertSame('assets/app.OLDHASH.js', $reader->load($this->path)['app.js']['file']);

        // Simulate a zero-downtime redeploy replacing the manifest in place.
        // Different size AND a bumped mtime, since mtime alone is second-granular.
        $this->write('assets/app.NEWHASH.longer.js');
        touch($this->path, time() + 5);
        clearstatcache(true, $this->path);

        self::assertSame(
            'assets/app.NEWHASH.longer.js',
            $reader->load($this->path)['app.js']['file'],
            'a stale manifest makes every asset URL 404 until the worker restarts',
        );
    }

    public function test_an_unchanged_manifest_is_served_from_cache(): void
    {
        $reader = new ManifestReader();
        $this->write('assets/app.AAA.js');

        $mtime = filemtime($this->path);
        $first  = $reader->load($this->path);

        // Rewrite with the SAME length and restore the mtime, so the stat stamp
        // is identical. The cached copy is served — the documented tradeoff of a
        // stat-based cache, and what makes it cheap on the hot path.
        $this->write('assets/app.BBB.js');
        touch($this->path, (int) $mtime);
        clearstatcache(true, $this->path);

        self::assertSame($first, $reader->load($this->path));
    }

    public function test_a_manifest_deleted_after_caching_is_reported_missing(): void
    {
        $reader = new ManifestReader();
        $this->write('assets/app.HASH.js');
        $reader->load($this->path);

        unlink($this->path);
        clearstatcache(true, $this->path);

        // Better to fail loudly than to keep serving URLs for a deploy that is
        // no longer on disk.
        $this->expectException(\Plugins\ViteManifest\Exceptions\ViteManifestNotFoundException::class);
        $reader->load($this->path);
    }

    public function test_a_missing_manifest_throws(): void
    {
        $this->expectException(\Plugins\ViteManifest\Exceptions\ViteManifestNotFoundException::class);

        (new ManifestReader())->load($this->path);
    }
}
