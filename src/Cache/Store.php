<?php

declare(strict_types=1);

namespace Atispro\Img\Cache;

use Atispro\Img\Config;
use Atispro\Img\Exception\BusyException;
use Atispro\Img\Exception\ProcessException;
use Atispro\Img\Pipeline;

/**
 * Publishes derivatives, and decides when an existing one is still good.
 *
 * Two problems this solves that the legacy implementations did not:
 *
 * Torn writes. `is_file()` was the only validity check, and the backend wrote
 * straight to the destination — so an interrupted convert left a truncated file
 * that was then served forever. Every write now goes to a temporary file in the
 * same directory and is published with rename(), which is atomic.
 *
 * Thundering herd. Concurrent requests for the same missing derivative all ran
 * their own conversion. The first one in takes an exclusive lock; the others
 * wait for it and then find the file already there.
 */
final class Store
{
    private const STAMP = '.pipeline.json';

    private const LOCK_DIR = '.locks';

    /** How long to wait for another request to finish generating the same file. */
    private const LOCK_WAIT_SECONDS = 10.0;

    /** Temporary files younger than this may still be being written. */
    private const TEMP_GRACE_SECONDS = 600;

    private ?int $stampMtime = null;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * True when this derivative exists and was written by the current version
     * of the package under the current configuration.
     */
    /**
     * @param string|null $sourceFile The original this was generated from, when
     *        there is one. Editing it in place must invalidate the derivative.
     */
    public function isFresh(string $file, ?string $sourceFile = null): bool
    {
        // Read the stamp first, and unconditionally. Checking the file first
        // means a cold cache never reaches this call, so the stamp is not
        // written until some derivative happens to already exist — and then it
        // is newer than everything, and the whole cache rebuilds once.
        $stamp = $this->stampMtime();

        clearstatcache(true, $file);
        // Loose on purpose: filesize() returns false (not 0) when the file
        // vanishes between the two calls, and false === 0 would read a deleted
        // file as fresh.
        if (!is_file($file) || !filesize($file)) {
            return false;
        }

        $mtime = (int) filemtime($file);

        if ($mtime < $stamp) {
            return false;
        }

        // Without this an editor who replaces photo.jpg in place keeps serving
        // the old picture from every derivative, for max-age=604800, with
        // nothing short of a cache clear to shift it.
        //
        // "Older than the source" rather than "not newer", which is the rule
        // make and rsync use. Mtimes have one-second granularity, so a
        // derivative built in the same second as its source compares equal;
        // treating that as stale would regenerate every freshly uploaded image
        // twice, and would loop forever on a source whose mtime is in the
        // future — clock skew across an NFS mount, or timestamps preserved by a
        // copy. The residual gap is an edit landing in the very same second as
        // the build, which the next edit corrects.
        if ($sourceFile !== null && is_file($sourceFile) && $mtime < (int) filemtime($sourceFile)) {
            return false;
        }

        return true;
    }

    /**
     * Run $write against a temporary path and publish the result atomically.
     * If a concurrent request produced the file while we were waiting for the
     * lock, $write is not called at all.
     *
     * @param callable(string):void $write
     * @throws ProcessException
     */
    public function publish(string $file, ?string $sourceFile, callable $write): void
    {
        $this->ensureDir(dirname($file));
        $this->sweepStaleTemporaries(dirname($file));

        $lock = $this->lock($file);

        if ($lock === false) {
            // Somebody else is generating this. Stale bytes beat both a blocked
            // worker and a 404 for a file that is about to exist.
            if (is_file($file) && filesize($file) > 0) {
                return;
            }

            throw new BusyException("another request is still generating {$file}");
        }

        try {
            if ($this->isFresh($file, $sourceFile)) {
                return;
            }

            // Inside the lock, so the answer cannot change between the check
            // and the write it guards.
            $this->assertWritable($file);
            $this->assertUnderCap($file, $sourceFile);

            $temp = $this->temp($file);

            try {
                $write($temp);

                if (!is_file($temp) || filesize($temp) === 0) {
                    throw new ProcessException("backend produced no output for {$file}");
                }

                $this->rename($temp, $file);
            } finally {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Refuse to publish over a file we did not generate.
     *
     * The destination is derived from the request path, so it can land on a
     * genuine upload — `logo.vintage.png` beside `logo.png` parses as a filtered
     * derivative of it. Renaming over that is silent, irreversible data loss.
     *
     * @throws ProcessException
     */
    private function assertWritable(string $file): void
    {
        if (!is_file($file) || !filesize($file)) {
            return;
        }

        if (DerivativePath::isDerivative($file, $this->config)) {
            return;
        }

        throw new ProcessException(
            "refusing to overwrite {$file}: it is not a file this pipeline generated",
        );
    }

    /**
     * The backstop on derivatives-per-source. The URL grammar bounds each
     * dimension of the key space (geometry ladder, filter steps), but their
     * product is still large; this caps what any one source can accumulate in
     * one directory, however the space was reached. Legitimate use — the
     * formats times the filters a site actually employs — sits far below it.
     *
     * @throws ProcessException
     */
    private function assertUnderCap(string $file, ?string $sourceFile): void
    {
        $cap = $this->config->derivativeCap;
        if ($cap <= 0 || $sourceFile === null) {
            return;
        }

        // Every derivative of photo.jpg starts with "photo." — conversions
        // (photo.jpg.webp), filters (photo.blur2.jpg), geometry copies keep the
        // full stem. scandir rather than glob: a filename may contain glob
        // metacharacters, and the directory is small by construction (this very
        // cap bounds it).
        $stem = pathinfo(basename($sourceFile), PATHINFO_FILENAME) . '.';

        $entries = @scandir(dirname($file));
        if ($entries === false) {
            return;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if (str_starts_with($entry, $stem) && !str_ends_with($entry, '.tmp')) {
                $count++;
            }
        }

        if ($count >= $cap) {
            throw new ProcessException(
                "derivative cap ({$cap}) reached for {$stem}* in " . dirname($file),
            );
        }
    }

    /**
     * Windows cannot rename over a file another process holds open, and a
     * concurrent readfile() of the stale derivative does exactly that. Retry
     * briefly, and if it still will not budge keep whatever is already there —
     * serving slightly stale bytes beats 404ing a file that exists.
     *
     * @throws ProcessException
     */
    private function rename(string $temp, string $file): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($temp, $file)) {
                return;
            }

            usleep(20_000 * ($attempt + 1));
        }

        if (is_file($file) && filesize($file) > 0) {
            return;
        }

        throw new ProcessException("could not publish {$file}");
    }

    /**
     * Drop temporary files left behind by a worker that was killed mid-publish.
     * They are non-empty, so Apache would otherwise serve one as a truncated
     * image download.
     */
    private function sweepStaleTemporaries(string $dir): void
    {
        $stale = glob($dir . '/*.tmp');
        if ($stale === false) {
            return;
        }

        $cutoff = time() - self::TEMP_GRACE_SECONDS;
        foreach ($stale as $path) {
            if ((int) @filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }

    /**
     * A sibling temporary path. Same directory, so rename() stays within one
     * filesystem and therefore stays atomic.
     */
    public function temp(string $file, string $suffix = ''): string
    {
        return sprintf('%s.%s%s.tmp', $file, bin2hex(random_bytes(6)), $suffix);
    }

    /**
     * Mtime of the version stamp. Any derivative older than this predates the
     * current package version or configuration and has to be rebuilt.
     *
     * This is how a change to the encoder settings or the sharpen parameters
     * invalidates existing files: the URL is the cache key and cannot carry a
     * version, so the comparison is by age instead. Code changes — the filter
     * registry included — are covered only by a Pipeline::VERSION bump; the
     * config hash cannot see them.
     */
    private function stampMtime(): int
    {
        if ($this->stampMtime !== null) {
            return $this->stampMtime;
        }

        $path = $this->config->imagesPath . '/' . self::STAMP;
        $want = ['version' => Pipeline::VERSION, 'config' => $this->config->hash()];

        $have = is_file($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;

        if (!is_array($have) || ($have['version'] ?? null) !== $want['version'] || ($have['config'] ?? null) !== $want['config']) {
            self::writeJsonAtomically($path, $want);
            clearstatcache(true, $path);
        }

        return $this->stampMtime = is_file($path) ? (int) filemtime($path) : 0;
    }

    /**
     * Exclusive lock for one derivative, or false when the wait ran out.
     *
     * Non-blocking with a bounded wait, deliberately. A plain blocking LOCK_EX
     * pins every concurrent requester of a URL behind one slow conversion, so a
     * modest burst on a cold cache exhausts the worker pool and takes the whole
     * site down rather than just that image.
     *
     * @return resource|null|false null = locking unavailable, proceed anyway
     */
    private function lock(string $file)
    {
        $dir = $this->config->imagesPath . '/' . self::LOCK_DIR;
        $this->ensureDir($dir);

        $path = $dir . '/' . hash('xxh128', $file) . '.lock';

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null; // locking is an optimisation, not a correctness requirement
        }

        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            usleep(25_000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        return false;
    }

    /**
     * @param resource|null $handle
     */
    private function release($handle): void
    {
        if ($handle === null) {
            return;
        }

        // The lock file is deleted, not just unlocked: it used to be left
        // behind forever, one permanent zero-byte inode per derivative ever
        // generated, all in one flat directory.
        //
        // Unlinked after fclose, because Windows refuses to delete a file with
        // an open handle. Two benign races follow. A waiter that already opened
        // this path can flock the orphaned inode while a newcomer creates a
        // fresh file — two writers racing one derivative, which the atomic
        // rename already makes safe. And on Windows the unlink itself fails
        // while a waiter still holds the handle — that waiter's own release
        // deletes it instead.
        $meta = stream_get_meta_data($handle);

        @flock($handle, LOCK_UN);
        fclose($handle);

        if (is_string($meta['uri'] ?? null)) {
            @unlink($meta['uri']);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, $this->config->dirPermission, true);
        }
    }

    /**
     * Write JSON via a temporary file and rename, so a concurrent reader never
     * catches a partial write. SUBSTITUTE because json_encode returns false
     * outright on malformed UTF-8, and file_put_contents would then happily
     * write that false as a zero-byte file — which reads back as "no stamp" and
     * silently rebuilds the entire cache on every request.
     *
     * @param array<string,mixed> $data
     */
    private static function writeJsonAtomically(string $path, array $data): void
    {
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $json) === false) {
            return;
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);
        }
    }
}
