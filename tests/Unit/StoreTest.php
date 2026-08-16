<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Cache\Store;
use Atispro\Img\Exception\ProcessException;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private TempSite $site;

    protected function setUp(): void
    {
        $this->site = new TempSite();
    }

    protected function tearDown(): void
    {
        $this->site->destroy();
    }

    /**
     * The stamp has to exist from the first request. Written lazily — only once
     * some derivative already happened to be on disk — it lands newer than
     * everything around it and the entire cache rebuilds one time for nothing.
     */
    public function testStampIsWrittenOnAColdCache(): void
    {
        $store = new Store($this->site->config());

        self::assertFalse($store->isFresh($this->site->absolute('1044/nothing.jpg')));
        self::assertFileExists($this->site->absolute('.pipeline.json'));
    }

    public function testDerivativesOlderThanTheStampAreStale(): void
    {
        $config = $this->site->config();
        $store = new Store($config);
        $store->isFresh($this->site->absolute('1044/nothing.jpg')); // creates the stamp

        $file = $this->site->absolute('1044/old.webp');
        mkdir(dirname($file), 0o777, true);
        file_put_contents($file, 'stale');
        touch($file, time() - 3600);

        self::assertFalse($store->isFresh($file));

        touch($file, time() + 10);
        clearstatcache(true, $file);
        self::assertTrue($store->isFresh($file));
    }

    public function testEmptyFilesAreNeverFresh(): void
    {
        $store = new Store($this->site->config());

        $file = $this->site->absolute('1044/empty.webp');
        mkdir(dirname($file), 0o777, true);
        file_put_contents($file, '');

        self::assertFalse($store->isFresh($file));
    }

    public function testPublishRenamesIntoPlaceAndLeavesNoTemporaries(): void
    {
        $store = new Store($this->site->config());
        $file = $this->site->absolute('1044/400x/photo.webp');

        $store->publish($file, null, static function (string $temp): void {
            file_put_contents($temp, 'payload');
        });

        self::assertSame('payload', file_get_contents($file));
        self::assertSame([], glob(dirname($file) . '/*.tmp') ?: []);
    }

    /**
     * A backend that fails must not leave a truncated file behind as a
     * permanent cache entry — existence was the only validity check the
     * previous implementations had.
     */
    public function testAFailedWriteLeavesNothingBehind(): void
    {
        $store = new Store($this->site->config());
        $file = $this->site->absolute('1044/400x/photo.webp');

        try {
            $store->publish($file, null, static function (string $temp): void {
                file_put_contents($temp, 'half a fi');
                throw new ProcessException('backend blew up');
            });
            self::fail('the exception should propagate');
        } catch (ProcessException) {
            // expected
        }

        self::assertFileDoesNotExist($file);
        self::assertSame([], glob(dirname($file) . '/*.tmp') ?: []);
    }

    public function testAWriteThatProducesNothingIsRejected(): void
    {
        $store = new Store($this->site->config());
        $file = $this->site->absolute('1044/400x/photo.webp');

        $this->expectException(ProcessException::class);
        $store->publish($file, null, static function (string $temp): void {
            // writes nothing at all
        });
    }

    /**
     * The destination is derived from the request path, so it can land on a
     * genuine upload. Renaming over it is silent, irreversible data loss.
     */
    public function testRefusesToOverwriteAFileItDidNotGenerate(): void
    {
        $store = new Store($this->site->config());

        // An upload that merely looks like a filtered derivative — there is no
        // logo.png beside it, so nothing could have generated it.
        $upload = $this->site->absolute('1044/logo.vintage.png');
        mkdir(dirname($upload), 0o777, true);
        file_put_contents($upload, 'the original upload');

        try {
            $store->publish($upload, null, static function (string $temp): void {
                file_put_contents($temp, 'generated');
            });
            self::fail('publishing over a non-derivative should be refused');
        } catch (ProcessException) {
            // expected
        }

        self::assertSame('the original upload', file_get_contents($upload));
    }

    public function testOverwritesItsOwnDerivatives(): void
    {
        $store = new Store($this->site->config());

        $original = $this->site->absolute('1044/photo.jpg');
        mkdir(dirname($original), 0o777, true);
        file_put_contents($original, 'source');

        // A conversion of it: the source beside it is what proves it is ours.
        // Dated before the source, so it is genuinely due for a rebuild.
        $derivative = $this->site->absolute('1044/photo.jpg.webp');
        file_put_contents($derivative, 'stale');
        touch($derivative, time() - 60);
        clearstatcache();

        $store->publish($derivative, $original, static function (string $temp): void {
            file_put_contents($temp, 'fresh');
        });

        self::assertSame('fresh', file_get_contents($derivative));
    }

    /**
     * Replacing an original in place has to invalidate everything made from it;
     * otherwise the old picture is served for max-age=604800.
     */
    public function testEditingTheSourceMakesTheDerivativeStale(): void
    {
        $store = new Store($this->site->config());

        $source = $this->site->absolute('1044/photo.jpg');
        mkdir(dirname($source), 0o777, true);
        file_put_contents($source, 'v1');

        $derivative = $this->site->absolute('1044/400x/photo.jpg.webp');
        mkdir(dirname($derivative), 0o777, true);
        file_put_contents($derivative, 'derived from v1');
        touch($derivative, time() + 5);

        self::assertTrue($store->isFresh($derivative, $source));

        touch($source, time() + 10);
        clearstatcache();

        self::assertFalse($store->isFresh($derivative, $source), 'an edited source must invalidate');
    }

    public function testPublishSkipsWorkWhenTheFileIsAlreadyFresh(): void
    {
        $store = new Store($this->site->config());

        // A real source, so the destination is recognisably our own derivative.
        $source = $this->site->absolute('1044/photo.webp');
        mkdir(dirname($source), 0o777, true);
        file_put_contents($source, 'source');

        $file = $this->site->absolute('1044/400x/photo.webp');

        $store->publish($file, null, static function (string $temp): void {
            file_put_contents($temp, 'first');
        });

        $called = false;
        $store->publish($file, null, static function (string $temp) use (&$called): void {
            $called = true;
            file_put_contents($temp, 'second');
        });

        self::assertFalse($called, 'a fresh derivative must not be regenerated');
        self::assertSame('first', file_get_contents($file));
    }
}
