<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Cache\Cleaner;
use Atispro\Img\Cache\DerivativePath;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

/**
 * The animated-AVIF exception to "corroborated by name means ours". The motion
 * clips a site ships are encoded by ffmpeg and cannot be rebuilt here, so a
 * name that happens to sit in the conversion or geometry namespace must not be
 * enough to sweep or overwrite one.
 */
final class DerivativePathTest extends TestCase
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

    public function testAStillConversionBesideItsSourceIsOurs(): void
    {
        $this->plant('1044/clip.png', 'png');
        $this->plant('1044/clip.png.avif', self::ftyp('avif', ['avif', 'mif1', 'miaf']));

        self::assertTrue(DerivativePath::isDerivative($this->site->absolute('1044/clip.png.avif'), $this->site->config()));
    }

    public function testAnAnimatedConversionBesideItsSourceIsNot(): void
    {
        $this->plant('1044/clip.gif', 'gif');
        $this->plant('1044/clip.gif.avif', self::ftyp('avis', ['avif', 'avis', 'msf1', 'miaf']));

        self::assertFalse(DerivativePath::isDerivative($this->site->absolute('1044/clip.gif.avif'), $this->site->config()));
    }

    public function testAnAnimatedFileInAGeometryDirectoryIsNot(): void
    {
        $this->plant('1044/clip.avif', self::ftyp('avis', ['avis']));
        $this->plant('1044/400x/clip.avif', self::ftyp('avis', ['avis']));

        self::assertFalse(DerivativePath::isDerivative($this->site->absolute('1044/400x/clip.avif'), $this->site->config()));
    }

    /** ffmpeg writes `avis` as a compatible brand under an `avif` major brand too. */
    public function testTheSequenceBrandCountsAmongTheCompatibleOnes(): void
    {
        $this->plant('1044/a.avif', self::ftyp('avif', ['avif', 'avis', 'msf1']));
        $this->plant('1044/b.avif', self::ftyp('avif', ['avif', 'mif1']));
        $this->plant('1044/c.avif', 'not an ISOBMFF file at all');

        self::assertTrue(DerivativePath::isAvifSequence($this->site->absolute('1044/a.avif')));
        self::assertFalse(DerivativePath::isAvifSequence($this->site->absolute('1044/b.avif')));
        self::assertFalse(DerivativePath::isAvifSequence($this->site->absolute('1044/c.avif')));
    }

    public function testTheCleanerSweepsTheStillAndKeepsTheAnimation(): void
    {
        $this->plant('1044/clip.png', 'png');
        $this->plant('1044/200x/clip.png.avif', self::ftyp('avif', ['avif', 'mif1']));
        $this->plant('1044/400x/clip.png.avif', self::ftyp('avis', ['avif', 'avis']));

        (new Cleaner($this->site->config()))->clean();

        self::assertFalse($this->site->exists('1044/200x/clip.png.avif'));
        self::assertTrue($this->site->exists('1044/400x/clip.png.avif'));
    }

    private function plant(string $relative, string $bytes): void
    {
        $path = $this->site->absolute($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $bytes);
    }

    /** @param list<string> $compatible */
    private static function ftyp(string $major, array $compatible): string
    {
        $body = $major . "\0\0\0\0" . implode('', $compatible);

        return pack('N', 8 + strlen($body)) . 'ftyp' . $body . str_repeat("\0", 32);
    }
}
