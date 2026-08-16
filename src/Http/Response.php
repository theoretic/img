<?php

declare(strict_types=1);

namespace Atispro\Img\Http;

/**
 * Serving, with conditional-request handling.
 *
 * The ETag is derived from mtime and size rather than a content hash. md5_file()
 * meant reading the whole file to build the header and then reading it again to
 * send it — double the I/O on every hit, including the ones that end in a 304.
 * It also matches what Apache's own `FileETag MTime Size` produces for the same
 * file once it is served statically.
 */
final class Response
{
    private const MAX_AGE = 604800; // 7 days

    /**
     * Where headers go. Swappable because header() is a no-op under the CLI
     * SAPI and headers_list() comes back empty there, which would leave every
     * cache header in this class permanently untested.
     *
     * @var (callable(string):void)|null
     */
    private static $sink = null;

    /**
     * @param (callable(string):void)|null $sink Null restores the real header().
     */
    public static function sendHeadersUsing(?callable $sink): void
    {
        self::$sink = $sink;
    }

    private static function send(string $header): void
    {
        if (self::$sink !== null) {
            (self::$sink)($header);

            return;
        }

        header($header);
    }

    public static function serveFile(string $file): void
    {
        if (!is_file($file)) {
            self::notFound();

            return;
        }

        $stat = stat($file);
        if ($stat === false) {
            self::notFound();

            return;
        }

        // Byte-for-byte what Apache's `FileETag MTime Size` produces, because
        // the same file is served by PHP the first time and statically by
        // Apache every time after. A tag Apache never reproduces means the next
        // revalidation misses and the client re-downloads the whole image.
        $etag = sprintf('"%x-%x"', $stat['size'], $stat['mtime'] * 1000000);
        $lastModified = gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';

        $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH'])
            ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH'])
            : null;
        $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            ? (int) strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE'])
            : 0;

        // RFC 9110 §13.1.3: when both are present the entity tag wins outright.
        // OR-ing them let a client with a stale tag but a recent date keep
        // rendering bytes we had already replaced.
        $notModified = $ifNoneMatch !== null
            ? self::etagMatches($ifNoneMatch, $etag)
            : ($ifModifiedSince > 0 && $ifModifiedSince >= $stat['mtime']);

        self::send('ETag: ' . $etag);
        self::send('Last-Modified: ' . $lastModified);
        self::send('Cache-Control: public, max-age=' . self::MAX_AGE . ', must-revalidate');
        self::send('X-Content-Type-Options: nosniff');

        if ($notModified) {
            self::send('HTTP/1.1 304 Not Modified');

            return;
        }

        self::send('Content-Type: ' . self::mimeType($file));
        self::send('Content-Length: ' . $stat['size']);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }

        readfile($file);
    }

    public static function redirect(string $location): void
    {
        // Percent-encoded per segment, which is also what makes header
        // injection impossible: rawurlencode leaves nothing outside the
        // unreserved set, so a CR or LF in a filename becomes %0D%0A rather
        // than a header break. Spaces and quotes — which proxies reject — go
        // the same way, and a legitimate if peculiar filename still resolves.
        $encoded = implode('/', array_map('rawurlencode', explode('/', $location)));

        // rawurlencode also escapes the sub-delimiters, which are legal in a
        // path segment. The comma matters: filter tokens are written `blur0,20`,
        // so encoding it would send the client to a spelling that differs from
        // the canonical path it is supposed to be arriving at.
        $encoded = str_replace('%2C', ',', $encoded);

        self::send('HTTP/1.1 301 Moved Permanently');
        self::send('Location: ' . $encoded);
        self::send('Cache-Control: public, max-age=' . self::MAX_AGE);
    }

    public static function notFound(): void
    {
        self::send('HTTP/1.1 404 Not Found');

        // Briefly cacheable. Every miss costs a full parse and a getimagesize,
        // so a scan of random names under a real directory is otherwise an
        // unbounded supply of PHP invocations.
        self::send('Cache-Control: public, max-age=60');
    }

    public static function methodNotAllowed(): void
    {
        self::send('HTTP/1.1 405 Method Not Allowed');
        self::send('Allow: GET, HEAD');
    }

    /**
     * Another request is generating this derivative and we would rather not
     * block a worker waiting for it. Explicitly not a 404 — the image exists,
     * it is just not ready.
     */
    public static function busy(int $retryAfter = 2): void
    {
        self::send('HTTP/1.1 503 Service Unavailable');
        self::send('Retry-After: ' . $retryAfter);
        self::send('Cache-Control: no-store');
    }

    /**
     * An If-None-Match may carry a list, and may weaken the tag. Comparing the
     * header verbatim against our tag missed both, so those requests came back
     * as a full 200 every time.
     */
    private static function etagMatches(string $header, string $etag): bool
    {
        if ($header === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Content-Type from magic bytes rather than the filename.
     *
     * A derivative does not always hold the format its extension claims — the
     * CLI backend writes WebP into a .avif path on builds that cannot store
     * alpha in AVIF. Sniffing also sidesteps mime_content_type() reporting AVIF
     * as image/jpeg on some libmagic builds.
     */
    private static function mimeType(string $file): string
    {
        $handle = @fopen($file, 'rb');
        $signature = $handle !== false ? (string) fread($handle, 16) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        switch (true) {
            case str_starts_with($signature, "\xFF\xD8\xFF"):
                return 'image/jpeg';
            case str_starts_with($signature, "\x89PNG\r\n\x1a\n"):
                return 'image/png';
            case str_starts_with($signature, 'GIF8'):
                return 'image/gif';
            case str_starts_with($signature, 'RIFF') && substr($signature, 8, 4) === 'WEBP':
                return 'image/webp';
            case substr($signature, 4, 4) === 'ftyp':
                $brand = substr($signature, 8, 4);

                return ($brand === 'avif' || $brand === 'avis') ? 'image/avif' : 'image/heic';
        }

        $sniffed = function_exists('mime_content_type') ? mime_content_type($file) : false;

        return $sniffed !== false ? $sniffed : 'application/octet-stream';
    }
}
