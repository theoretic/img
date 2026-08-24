<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Http\Response;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    private TempSite $site;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->site = new TempSite();
        $this->sent = [];

        Response::sendHeadersUsing(function (string $header): void {
            $this->sent[] = $header;
        });
    }

    protected function tearDown(): void
    {
        Response::sendHeadersUsing(null);
        $this->site->destroy();
        unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'], $_SERVER['REQUEST_METHOD']);
    }

    private function file(string $contents = 'payload'): string
    {
        $path = $this->site->absolute('photo.jpg');
        file_put_contents($path, $contents);

        return $path;
    }

    private function header(string $name): ?string
    {
        foreach ($this->sent as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function statusLine(): ?string
    {
        foreach ($this->sent as $header) {
            if (str_starts_with($header, 'HTTP/')) {
                return $header;
            }
        }

        return null;
    }

    private function etagFor(string $file): string
    {
        $stat = stat($file);

        return sprintf('"%x-%x"', $stat['size'], $stat['mtime'] * 1000000);
    }

    private function serve(string $file): string
    {
        ob_start();
        Response::serveFile($file);

        return (string) ob_get_clean();
    }

    /**
     * The same file is served by PHP once and then statically by Apache under
     * `FileETag MTime Size`. A tag Apache cannot reproduce means every later
     * revalidation misses and re-downloads the whole image.
     */
    public function testEtagMatchesApachesFileEtagFormat(): void
    {
        $file = $this->file();
        $this->serve($file);

        self::assertSame($this->etagFor($file), $this->header('ETag'));
    }

    public function testMatchingEtagGivesA304WithNoBody(): void
    {
        $file = $this->file();
        $_SERVER['HTTP_IF_NONE_MATCH'] = $this->etagFor($file);

        $body = $this->serve($file);

        self::assertSame('', $body);
        self::assertSame('HTTP/1.1 304 Not Modified', $this->statusLine());
    }

    public function testEtagListAndWeakFormsAreHonoured(): void
    {
        $file = $this->file();
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"something-else", W/' . $this->etagFor($file);

        $this->serve($file);

        self::assertSame('HTTP/1.1 304 Not Modified', $this->statusLine());
    }

    public function testAnEtagIsStillSentOnA304(): void
    {
        $file = $this->file();
        $_SERVER['HTTP_IF_NONE_MATCH'] = $this->etagFor($file);

        $this->serve($file);

        self::assertSame($this->etagFor($file), $this->header('ETag'));
        self::assertNotNull($this->header('Last-Modified'), 'a 304 must still carry the validators');
    }

    /**
     * RFC 9110 §13.1.3: the entity tag decides when both are present. OR-ing
     * them let a client with a stale tag but a recent date keep rendering bytes
     * that had already been replaced.
     */
    public function testEntityTagWinsOverIfModifiedSince(): void
    {
        $file = $this->file();

        $_SERVER['HTTP_IF_NONE_MATCH'] = '"a-stale-tag"';
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT';

        $body = $this->serve($file);

        self::assertSame('payload', $body, 'a non-matching tag must force a full response');
        self::assertNull($this->statusLine(), 'no 304 status line');
    }

    public function testIfModifiedSinceAloneStillGivesA304(): void
    {
        $file = $this->file();
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT';

        $this->serve($file);

        self::assertSame('HTTP/1.1 304 Not Modified', $this->statusLine());
    }

    public function testHeadSendsHeadersButNoBody(): void
    {
        $file = $this->file();
        $_SERVER['REQUEST_METHOD'] = 'HEAD';

        $body = $this->serve($file);

        self::assertSame('', $body);
        self::assertSame((string) filesize($file), $this->header('Content-Length'));
    }

    /**
     * A derivative does not always hold the format its extension claims: the
     * CLI backend writes WebP into a .avif path where the build cannot store
     * alpha in AVIF.
     */
    public function testContentTypeComesFromMagicBytesNotTheExtension(): void
    {
        $path = $this->site->absolute('actually-a-png.avif');
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));

        $this->serve($path);

        self::assertSame('image/png', $this->header('Content-Type'));
    }

    /**
     * The path is request-derived, so it can hold spaces and quotes, which
     * proxies reject.
     */
    public function testRedirectLocationIsPercentEncodedPerSegment(): void
    {
        Response::redirect('/site/assets/files/1044/400x/my photo "1".jpg');

        self::assertSame(
            '/site/assets/files/1044/400x/my%20photo%20%221%22.jpg',
            $this->header('Location'),
        );
        self::assertSame('HTTP/1.1 301 Moved Permanently', $this->statusLine());
    }

    /**
     * A redirect whose validity depends on the source image's current content —
     * the covers-source collapse, the cover-fit binding-axis collapse — must
     * not be cached as permanent: replacing the source flips the target.
     */
    public function testContentDerivedRedirectIsTemporary(): void
    {
        Response::redirect('/site/assets/files/1044/x800/photo.jpg', permanent: false);

        self::assertSame('HTTP/1.1 302 Found', $this->statusLine());
        self::assertSame('public, max-age=3600', $this->header('Cache-Control'));
    }

    /**
     * A comma is legal in a path segment and canonical filter tokens contain
     * one, so encoding it would point the client at a spelling that differs
     * from the canonical path it is meant to land on.
     */
    public function testRedirectKeepsCommasUnencoded(): void
    {
        Response::redirect('/site/assets/files/1044/400x/parrot.blur0,20.jpeg');

        self::assertSame(
            '/site/assets/files/1044/400x/parrot.blur0,20.jpeg',
            $this->header('Location'),
        );
    }

    /**
     * A CR or LF in a filename must not be able to break out into a header of
     * its own. The encoding is what prevents it — there is no separate
     * rejection, and none is needed.
     */
    public function testRedirectCannotInjectAHeader(): void
    {
        Response::redirect("/site/assets/files/1044/photo\r\nX-Injected: 1.jpg");

        $location = (string) $this->header('Location');

        self::assertStringNotContainsString("\r", $location);
        self::assertStringNotContainsString("\n", $location);
        self::assertStringContainsString('%0D%0A', $location);
        self::assertCount(3, $this->sent, 'no extra header was smuggled in');
    }

    public function testNotFoundIsBrieflyCacheable(): void
    {
        Response::notFound();

        self::assertSame('HTTP/1.1 404 Not Found', $this->statusLine());
        self::assertStringContainsString('max-age=60', (string) $this->header('Cache-Control'));
    }

    public function testBusyIsA503WithRetryAfter(): void
    {
        Response::busy();

        self::assertSame('HTTP/1.1 503 Service Unavailable', $this->statusLine());
        self::assertNotNull($this->header('Retry-After'));
    }
}
