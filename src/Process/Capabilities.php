<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Config;

/**
 * Which backends actually work on this host.
 *
 * Probing costs a process spawn, so the result is memoised per request and
 * persisted to a marker file under imagesPath for `capabilitiesCacheTtl`
 * seconds. markImagickUnhealthy() flips the extension off when a runtime call
 * fails, so the rest of the request — and subsequent ones — skip a backend that
 * is present but broken.
 *
 * The marker carries a schema version. Three different shapes of this file have
 * shipped across the fleet ('cli' as a bool in some, as a binary path in
 * others, with and without 'avifAlpha'), all under the same filename; reading
 * one variant's marker with another variant's code handed a boolean `true` to
 * the CLI backend as the name of the binary to run.
 */
final class Capabilities
{
    private const MARKER = '.capabilities.json';

    /** 3: avifAlpha became tri-state, null meaning "not probed yet". */
    private const SCHEMA = 3;

    /**
     * Wall-clock ceiling for any subprocess. Generous enough for a large AVIF
     * encode, short enough that a wedged binary cannot hold a worker forever.
     */
    private const PROCESS_TIMEOUT_SECONDS = 60;

    /**
     * Keyed by configuration, not global. A single static made the first
     * load() in a process win for every later Config, so a multi-site CLI run
     * resolved one site's backend from another site's imagemagickPath.
     *
     * @var array<string,array{v:int,imagick:bool,cli:string|null,avifAlpha:bool|null}>
     */
    private static array $memo = [];

    public static function hasImagick(Config $config): bool
    {
        return self::load($config)['imagick'];
    }

    public static function hasCli(Config $config): bool
    {
        return self::load($config)['cli'] !== null;
    }

    /** The ImageMagick binary that actually runs here, or null. */
    public static function cliBinary(Config $config): ?string
    {
        return self::load($config)['cli'];
    }

    /**
     * True when the local ImageMagick can store an alpha channel in AVIF.
     *
     * Probed on first use rather than during the capability load, and then
     * remembered in the marker: it costs two process spawns, and a site that
     * never serves AVIF should never pay them.
     */
    public static function avifAlpha(Config $config): bool
    {
        $state = self::load($config);

        if ($state['avifAlpha'] !== null) {
            return $state['avifAlpha'];
        }

        $state['avifAlpha'] = $state['cli'] !== null && self::probeAvifAlpha($state['cli']);

        self::$memo[self::memoKey($config)] = $state;
        if ($config->capabilitiesCacheTtl > 0) {
            self::write($config, $state);
        }

        return $state['avifAlpha'];
    }

    public static function markImagickUnhealthy(Config $config): void
    {
        $state = self::load($config);
        if (!$state['imagick']) {
            return;
        }

        $state['imagick'] = false;
        self::$memo[self::memoKey($config)] = $state;
        self::write($config, $state);
    }

    /** Discard the per-request memo. Tests only. */
    public static function reset(): void
    {
        self::$memo = [];
    }

    private static function memoKey(Config $config): string
    {
        return $config->imagesPath . '|' . $config->imagemagickPath . '|' . $config->processor;
    }

    /**
     * Environment snapshot for the debug endpoint. Reports enough to diagnose a
     * host where nothing works, which on shared hosting is usually
     * disable_functions rather than a missing ImageMagick.
     *
     * @return array<string,mixed>
     */
    public static function diagnose(Config $config): array
    {
        $candidates = [];
        foreach (self::binaryCandidates($config) as $bin) {
            $viaPath = !str_contains($bin, '/') && !str_contains($bin, '\\');
            $result = self::run([$bin, '-version']);
            $candidates[] = [
                'tried' => $bin,
                'source' => $viaPath ? 'PATH' : 'imagemagickPath',
                'file_exists' => $viaPath ? null : is_file($bin),
                'exit_code' => $result['rc'],
                'is_imagemagick' => stripos($result['out'] . $result['err'], 'ImageMagick') !== false,
                'reported' => strtok(trim($result['out'] . $result['err']), "\n") ?: null,
            ];
        }

        return [
            'state' => self::load($config),
            'marker_file' => self::markerPath($config),
            'marker_exists' => is_file(self::markerPath($config)),
            'php_os' => PHP_OS,
            'imagick_loaded' => extension_loaded('imagick'),
            'imagick_class' => class_exists('Imagick'),
            'disable_functions' => ini_get('disable_functions'),
            'proc_open_exists' => function_exists('proc_open'),
            'exec_exists' => function_exists('exec'),
            'shell_exec_exists' => function_exists('shell_exec'),
            'imagemagickPath' => $config->imagemagickPath,
            'cli_candidates' => $candidates,
        ];
    }

    /**
     * Run a command without a shell, returning exit code and stdout.
     *
     * Falls back proc_open -> exec -> shell_exec, because shared hosts routinely
     * disable one or two of them through disable_functions.
     *
     * @param list<string> $argv
     * @return array{rc:int,out:string,err:string}
     */
    public static function run(array $argv): array
    {
        if (function_exists('proc_open')) {
            $proc = @proc_open(
                $argv,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );

            if (is_resource($proc)) {
                return self::pump($proc, $pipes);
            }
        }

        $command = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';

        if (function_exists('exec')) {
            $lines = [];
            $rc = 1;
            @exec($command, $lines, $rc);
            $out = self::utf8(implode("\n", $lines));

            return ['rc' => $rc, 'out' => $out, 'err' => $rc === 0 ? '' : $out];
        }

        if (function_exists('shell_exec')) {
            $out = @shell_exec($command);
            $ok = $out !== null && $out !== false;

            return ['rc' => $ok ? 0 : 1, 'out' => self::utf8((string) $out), 'err' => $ok ? '' : 'shell_exec failed'];
        }

        return ['rc' => -1, 'out' => '', 'err' => 'no way to execute a subprocess (disable_functions)'];
    }

    /**
     * Read both pipes concurrently, under a deadline, then reap.
     *
     * Draining stdout to EOF before touching stderr deadlocks: once the child
     * fills the stderr pipe buffer it blocks writing, while the parent is still
     * blocked reading stdout. ImageMagick emits a warning per frame on some
     * inputs, so a single noisy conversion hung the request until the FPM
     * timeout — holding the store lock, and taking every concurrent requester
     * of that derivative down with it.
     *
     * The deadline covers the other half: `-limit time` bounds ImageMagick's
     * own work, not a binary that never gets as far as producing output.
     *
     * @param resource $proc
     * @param array<int,resource> $pipes
     * @return array{rc:int,out:string,err:string}
     */
    private static function pump($proc, array $pipes): array
    {
        $buffers = [1 => '', 2 => ''];
        $open = [];

        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                stream_set_blocking($pipes[$fd], false);
                $open[$fd] = $pipes[$fd];
            }
        }

        $deadline = microtime(true) + self::PROCESS_TIMEOUT_SECONDS;
        $timedOut = false;

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $read = $open;
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, (int) $remaining, 0);
            if ($ready === false) {
                break;
            }

            if ($ready === 0) {
                continue;
            }

            foreach ($read as $stream) {
                $fd = array_search($stream, $open, true);
                if ($fd === false) {
                    continue;
                }

                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$fd]);
                    }

                    continue;
                }

                $buffers[$fd] .= $chunk;
            }
        }

        foreach ($open as $stream) {
            fclose($stream);
        }

        if ($timedOut) {
            proc_terminate($proc);
            proc_close($proc);

            return [
                'rc' => -1,
                'out' => self::utf8($buffers[1]),
                'err' => self::utf8($buffers[2]) . "\n(timed out after " . self::PROCESS_TIMEOUT_SECONDS . 's)',
            ];
        }

        return [
            'rc' => proc_close($proc),
            'out' => self::utf8($buffers[1]),
            'err' => self::utf8($buffers[2]),
        ];
    }

    /**
     * Force captured output to valid UTF-8.
     *
     * A shell reports failures in the system's own codepage — "not recognized
     * as an internal or external command" comes back in OEM bytes on a
     * localised Windows. That text ends up in json_encode() for the debug
     * endpoint and in exception messages, and json_encode() returns false
     * outright on malformed UTF-8: the endpoint answered with an empty body
     * rather than a diagnosis, exactly when someone was trying to diagnose
     * something.
     *
     * Everything worth reading here — "ImageMagick", version numbers, paths —
     * is ASCII, so dropping what will not decode loses nothing that matters.
     */
    private static function utf8(string $text): string
    {
        if ($text === '' || (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8'))) {
            return $text;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                return $converted;
            }
        }

        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
    }

    /**
     * @return array{v:int,imagick:bool,cli:string|null,avifAlpha:bool|null}
     */
    private static function load(Config $config): array
    {
        $key = self::memoKey($config);

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $cached = self::readMarker($config);
        if ($cached !== null) {
            return self::$memo[$key] = $cached;
        }

        $state = [
            'v' => self::SCHEMA,
            'imagick' => self::probeImagick(),
            'cli' => self::probeCli($config),
            // Not probed here: it costs two more spawns and only matters when
            // AVIF is actually requested, which on most sites is never.
            'avifAlpha' => null,
        ];

        self::$memo[$key] = $state;

        // Persist either way, but see readMarker(): an all-negative result is
        // trusted only briefly. Never persisting it meant a host with no
        // ImageMagick at all re-probed on every single request, and a failed
        // PATH lookup costs a process spawn per candidate — enough to make each
        // request take seconds. Caching it for the full TTL is the opposite
        // mistake: one transient failure during a deploy would wedge the site
        // into 404s for an hour.
        if ($config->capabilitiesCacheTtl > 0) {
            self::write($config, $state);
        }

        return $state;
    }

    /**
     * @return array{v:int,imagick:bool,cli:string|null,avifAlpha:bool|null}|null
     */
    private static function readMarker(Config $config): ?array
    {
        if ($config->capabilitiesCacheTtl <= 0) {
            return null;
        }

        $file = self::markerPath($config);
        if (!is_file($file)) {
            return null;
        }

        $cached = json_decode((string) file_get_contents($file), true);
        if (!is_array($cached) || ($cached['v'] ?? null) !== self::SCHEMA) {
            return null;
        }

        // A result with nothing working is trusted only for negativeCacheTtl,
        // so a host that recovers — a binary installed, an extension enabled —
        // picks it up quickly, while a host that genuinely has neither is not
        // re-probing on every request.
        $works = ($cached['imagick'] ?? false) || ($cached['cli'] ?? null) !== null;
        $ttl = $works
            ? $config->capabilitiesCacheTtl
            : min($config->capabilitiesCacheTtl, $config->negativeCacheTtl);

        if ((time() - (int) filemtime($file)) >= $ttl) {
            return null;
        }

        // array_key_exists, not isset: avifAlpha is legitimately null until
        // something asks for it, and isset() would reject the whole marker.
        if (!isset($cached['imagick']) || !array_key_exists('cli', $cached) || !array_key_exists('avifAlpha', $cached)) {
            return null;
        }

        $cli = $cached['cli'];
        if ($cli !== null && !is_string($cli)) {
            return null;
        }

        return [
            'v' => self::SCHEMA,
            'imagick' => (bool) $cached['imagick'],
            'cli' => $cli,
            'avifAlpha' => $cached['avifAlpha'] === null ? null : (bool) $cached['avifAlpha'],
        ];
    }

    /**
     * @param array{v:int,imagick:bool,cli:string|null,avifAlpha:bool|null} $state
     */
    private static function write(Config $config, array $state): void
    {
        $file = self::markerPath($config);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, $config->dirPermission, true);
        }

        // SUBSTITUTE, checked, and via a rename: an unchecked json_encode
        // returning false writes a zero-byte marker, which reads back as "no
        // marker" and sends the host into re-probing — several process spawns —
        // on every single request, the exact failure the cache exists to avoid.
        $json = json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $json) === false) {
            return;
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);
        }
    }

    private static function markerPath(Config $config): string
    {
        return $config->imagesPath . '/' . self::MARKER;
    }

    private static function probeImagick(): bool
    {
        // Deliberately not gated on autoOrientImage: ImagickProcessor catches
        // Errors at runtime and demotes itself, which covers older builds
        // without disqualifying them up front.
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    /**
     * The first candidate that answers `-version`, or null.
     *
     * Probing each one matters: IM6 ships only `convert`, so a host that
     * returned `magick` unchecked failed every conversion forever.
     */
    private static function probeCli(Config $config): ?string
    {
        foreach (self::binaryCandidates($config) as $bin) {
            if (self::isImageMagick($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * A binary is only accepted when `-version` both exits 0 and identifies
     * itself as ImageMagick.
     *
     * Exit status alone is not enough. On Windows, `convert` on the default
     * PATH is the filesystem conversion tool in system32, not this one; any
     * host where that returned 0 would have had it selected and then failed
     * every single conversion.
     */
    private static function isImageMagick(string $bin): bool
    {
        $result = self::run([$bin, '-version']);
        if ($result['rc'] !== 0) {
            return false;
        }

        return stripos($result['out'] . $result['err'], 'ImageMagick') !== false;
    }

    /**
     * Binaries to try, in order: whatever a configured directory holds, then
     * the bare names for PATH resolution.
     *
     * The PATH entries are always appended, never skipped. A configured path
     * that is wrong — stale after an upgrade, or copied from another host's
     * config — must not disable the CLI backend outright while a perfectly
     * good ImageMagick sits on PATH. That is the common production shape: no
     * ext-imagick, binaries installed normally, nothing configured.
     *
     * Pure enumeration — no process is started here, so callers that only need
     * the candidate list do not pay for probing.
     *
     * @return list<string>
     */
    public static function binaryCandidates(Config $config): array
    {
        $path = $config->imagemagickPath;
        $suffix = str_starts_with(PHP_OS, 'WIN') ? '.exe' : '';

        $out = [];

        if ($path !== '') {
            foreach (['magick', 'convert'] as $name) {
                $candidate = "{$path}/{$name}{$suffix}";
                if (is_file($candidate)) {
                    $out[] = $candidate;
                }
            }
        }

        foreach (['magick', 'convert'] as $name) {
            $out[] = $name;
        }

        return $out;
    }

    /**
     * Encode a semi-transparent pixel to AVIF and read it back. Older
     * IM/libheif coders flatten alpha silently, and the CLI backend needs to
     * know so it can pick an alpha-capable format instead.
     */
    private static function probeAvifAlpha(string $bin): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'avifcap');
        if ($tmp === false) {
            return false;
        }

        self::run([$bin, '-size', '2x2', 'xc:rgba(255,0,0,0.5)', '-alpha', 'set', 'avif:' . $tmp]);
        $out = self::run([$bin, $tmp, '-format', '%[opaque]', 'info:'])['out'];
        // Read before unlinking: the verdict needs the container, not just the
        // coder's opinion of its own output.
        $magic = (string) @file_get_contents($tmp, false, null, 0, 16);
        @unlink($tmp);

        return self::readsAvifAlpha($magic, $out);
    }

    /**
     * The verdict of probeAvifAlpha(), split out so it can be tested without a
     * binary on the host.
     *
     * Both halves are load-bearing:
     *
     *   - the magic check is the failure the caller actually fears, a coder
     *     that answers an AVIF request with some other container under the
     *     .avif name.
     *   - `%[opaque]` is capitalised by ImageMagick 7 ("False") and not by 6
     *     ("false"). A case-sensitive comparison here answered "no alpha
     *     support" on every IM 7 host, which silently downgraded every
     *     alpha-sourced AVIF to WebP for as long as the marker lived.
     *
     * An unsupported property answers empty, which correctly reads as no alpha.
     *
     * @param string $magic  first bytes of the encoded file
     * @param string $opaque raw `%[opaque]` output
     */
    public static function readsAvifAlpha(string $magic, string $opaque): bool
    {
        return str_contains($magic, 'ftypavif') && strtolower(trim($opaque)) === 'false';
    }
}
