<?php

declare(strict_types=1);

namespace Atispro\Img\Cache;

use Atispro\Img\Config;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Removes what the pipeline generated, leaving everything else alone.
 *
 * A file is deleted only when {@see DerivativePath} can find the source it was
 * generated from. That corroboration matters: the earlier rules deleted any
 * file with two image extensions (destroying a genuine `screenshot.png.jpg`)
 * and recursively deleted any directory whose name looked like `WxH` — which
 * wiped a content directory named `2x4`, originals included.
 *
 * Both modes run identical logic; `$dryRun` only suppresses the unlink and
 * rmdir calls, so the reported totals are equal by construction rather than by
 * a correction applied afterwards.
 *
 * Deliberately CLI-only. The equivalent used to ship as a PHP file inside the
 * assets tree with an explicit `Require all granted`, which put a full cache
 * wipe — and the CPU storm of regenerating everything — behind a plain URL.
 */
final class Cleaner
{
    /** Bookkeeping files the pipeline owns and can rebuild. */
    private const STATE_FILES = ['.pipeline.json', '.capabilities.json'];

    private const LOCK_DIR = '.locks';

    /**
     * Temporary files younger than this may belong to a request that is still
     * running; taking one out from under it turns a live conversion into a 404.
     */
    private const TEMP_GRACE_SECONDS = 600;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param callable(string):void|null $report Called with a line per removal.
     * @return array{files:int,dirs:int,bytes:int}
     */
    public function clean(bool $dryRun = false, ?callable $report = null): array
    {
        $report ??= static fn (string $line) => null;

        $stats = ['files' => 0, 'dirs' => 0, 'bytes' => 0];

        /** @var array<string,int> $entries directory => entries it holds */
        $entries = [];
        /** @var array<string,int> $doomed directory => entries being removed */
        $doomed = [];
        /** @var list<string> $directories */
        $directories = [];

        $now = time();

        foreach ($this->walk() as $info) {
            $path = $info->getPathname();
            $parent = dirname($path);

            $entries[$parent] = ($entries[$parent] ?? 0) + 1;

            if ($info->isDir()) {
                $directories[] = $path;
                $entries[$path] ??= 0;

                continue;
            }

            if (!$this->shouldRemove($path, $info, $now)) {
                continue;
            }

            $size = $info->getSize();
            $report(sprintf('%s file %s (%s)', $dryRun ? 'keep' : 'del ', $path, self::bytes($size)));

            if (!$dryRun) {
                @unlink($path);
            }

            $doomed[$parent] = ($doomed[$parent] ?? 0) + 1;
            $stats['files']++;
            $stats['bytes'] += $size;
        }

        // Deepest first, so a directory is decided only after its children are.
        usort(
            $directories,
            static fn (string $a, string $b): int => substr_count($b, '/') + substr_count($b, '\\')
                <=> substr_count($a, '/') + substr_count($a, '\\'),
        );

        foreach ($directories as $path) {
            $held = $entries[$path] ?? 0;
            $removing = $doomed[$path] ?? 0;

            if ($removing !== $held) {
                continue;
            }

            // Prune only what we emptied, or a cache directory that was already
            // empty — never an unrelated empty content directory.
            if ($removing === 0 && !DerivativePath::isGeometryDirName(basename($path), $this->config)
                && basename($path) !== self::LOCK_DIR) {
                continue;
            }

            $report(sprintf('%s dir  %s', $dryRun ? 'keep' : 'del ', $path));

            if (!$dryRun) {
                @rmdir($path);
            }

            $parent = dirname($path);
            $doomed[$parent] = ($doomed[$parent] ?? 0) + 1;
            $stats['dirs']++;
        }

        return $stats;
    }

    /**
     * Bookkeeping we own, a stale temporary, or a corroborated derivative.
     */
    private function shouldRemove(string $path, SplFileInfo $info, int $now): bool
    {
        $filename = $info->getFilename();

        if (in_array($filename, self::STATE_FILES, true)) {
            return true;
        }

        if (basename(dirname($path)) === self::LOCK_DIR) {
            return true;
        }

        if (str_ends_with($filename, '.tmp')) {
            // Only once nothing can still be writing to it.
            return ($now - (int) $info->getMTime()) > self::TEMP_GRACE_SECONDS;
        }

        return DerivativePath::isDerivative($path, $this->config);
    }

    /**
     * Lazy, so a large assets tree is never materialised. The previous
     * implementation buffered every SplFileInfo into an array and then walked
     * the tree a second time to prune empty directories.
     *
     * @return iterable<SplFileInfo>
     */
    private function walk(): iterable
    {
        if (!is_dir($this->config->imagesPath)) {
            return;
        }

        yield from new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->config->imagesPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
    }

    private static function bytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return $bytes . ' ' . $unit;
            }
            $bytes = (int) ($bytes / 1024);
        }

        return $bytes . ' TB';
    }
}
