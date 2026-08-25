<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Cache\Cleaner;
use Atispro\Img\Request\Geometry;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

final class CleanerTest extends TestCase
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

    private function populate(): void
    {
        // originals
        $this->write('1044/photo.jpg');
        $this->write('1044/my.photo.jpg');
        $this->write('1044/photo.landscape.jpg');   // real filename, not a filter
        $this->write('1044/notes.txt');

        // uploads that merely LOOK like derivatives — nothing they could have
        // been generated from is present, so they must survive
        $this->write('1044/screenshot.png.jpg');
        $this->write('1044/banner.vintage.png');
        $this->write('2x4/diagram.png');            // content dir, not a geometry dir
        $this->write('1044/export.tmp');

        // derivatives
        $this->write('1044/400x/photo.jpg.webp');
        $this->write('1044/400x/photo.dim-25.jpg');
        $this->write('1044/400x/nested/photo.jpg.webp');
        $this->write('1044/400x800f/photo.jpg');
        $this->write('1044/1024x768/photo.jpg.avif');   // off-ladder, still ours
        $this->write('1044/x800/photo.jpg.avif');
        $this->write('1044/photo.jpg.avif');        // format change beside the original
        $this->write('1044/photo.dim-25.jpg');      // filter beside the original
        $this->write('.capabilities.json');
    }

    private function write(string $relative): void
    {
        $path = $this->site->absolute($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, str_repeat('x', 32));
    }

    public function testRemovesDerivativesAndKeepsOriginals(): void
    {
        $this->populate();

        (new Cleaner($this->site->config()))->clean();

        self::assertTrue($this->site->exists('1044/photo.jpg'));
        self::assertTrue($this->site->exists('1044/my.photo.jpg'));
        self::assertTrue($this->site->exists('1044/notes.txt'));

        self::assertFalse($this->site->exists('1044/400x/photo.jpg.webp'));
        self::assertFalse($this->site->exists('1044/400x800f/photo.jpg'));
        self::assertFalse($this->site->exists('1044/x800/photo.jpg.avif'));
        self::assertFalse($this->site->exists('1044/photo.jpg.avif'));
        self::assertFalse($this->site->exists('1044/photo.dim-25.jpg'));
    }

    /**
     * A filter token only marks a derivative when the un-filtered original is
     * beside it. Otherwise `photo.landscape.jpg` — a perfectly ordinary
     * filename — would be deleted.
     */
    public function testKeepsFilterLookalikesWithNoOriginalBesideThem(): void
    {
        $this->populate();

        (new Cleaner($this->site->config()))->clean();

        self::assertTrue($this->site->exists('1044/photo.landscape.jpg'));
    }

    /**
     * "Two image extensions means we made it" destroyed genuine uploads. A file
     * is only ours when the source it would have come from is actually there.
     */
    public function testKeepsDoubleExtensionUploadsWithNoSource(): void
    {
        $this->populate();

        (new Cleaner($this->site->config()))->clean();

        self::assertTrue($this->site->exists('1044/screenshot.png.jpg'), 'no screenshot.png exists, so this is an upload');
        self::assertTrue($this->site->exists('1044/banner.vintage.png'), 'no banner.png exists, so this is an upload');
    }

    /**
     * Any directory whose name looked like WxH used to be deleted recursively,
     * originals and all — so a product gallery called `2x4` was destroyed.
     *
     * The ladder used to be the thing that spared this directory. It no longer
     * is: corroboration is, and it is the stronger guarantee of the two, since
     * it holds for `2x4` and `1024x768` alike rather than for whichever
     * geometries happen to be configured today.
     */
    public function testKeepsContentDirectoriesThatMerelyLookLikeGeometry(): void
    {
        $this->populate();

        (new Cleaner($this->site->config()))->clean();

        self::assertTrue($this->site->exists('2x4/diagram.png'));
    }

    /**
     * The ladder says what this pipeline will generate next, not what is on
     * disk. A tree carries geometries from before the ladder existed, and while
     * membership gated this they were disowned and accumulated forever.
     */
    public function testRemovesDerivativesInOffLadderGeometryDirectories(): void
    {
        $this->populate();

        self::assertFalse(
            (new Geometry(1024, 768, ''))->isOnLadder($this->site->config()),
            'the fixture geometry must be off-ladder or this test proves nothing',
        );

        (new Cleaner($this->site->config()))->clean();

        self::assertFalse($this->site->exists('1044/1024x768/photo.jpg.avif'));
        self::assertTrue($this->site->exists('1044/photo.jpg'), 'the source it was made from stays');
    }

    /** A user's file that happens to end .tmp is not ours to delete. */
    public function testKeepsRecentTemporaryFiles(): void
    {
        $this->populate();

        (new Cleaner($this->site->config()))->clean();

        self::assertTrue($this->site->exists('1044/export.tmp'));
    }

    /**
     * The grammar always puts the geometry segment immediately before the
     * filename, so nothing nested deeper was ever generated by this pipeline.
     * Deleting a geometry directory wholesale — as the previous implementation
     * did — would have taken this with it.
     */
    public function testKeepsUnrelatedContentNestedInsideAGeometryDirectory(): void
    {
        $this->populate();

        (new Cleaner($this->site->config()))->clean();

        self::assertTrue($this->site->exists('1044/400x/nested/photo.jpg.webp'), 'not a derivative location');
        self::assertFalse($this->site->exists('1044/400x/photo.jpg.webp'), 'but its own derivatives still go');
    }

    /**
     * Files inside a geometry directory are removed with the directory, so they
     * must not also be counted on their own — otherwise --dry-run promises to
     * remove more than the real run reports.
     */
    public function testDryRunCountsMatchTheRealRun(): void
    {
        $this->populate();
        $cleaner = new Cleaner($this->site->config());

        $dry = $cleaner->clean(true);
        $real = $cleaner->clean(false);

        self::assertSame($dry['files'], $real['files'], 'file counts diverge');
        self::assertSame($dry['dirs'], $real['dirs'], 'directory counts diverge');
        self::assertSame($dry['bytes'], $real['bytes'], 'byte totals diverge');
    }

    public function testDryRunRemovesNothing(): void
    {
        $this->populate();

        (new Cleaner($this->site->config()))->clean(true);

        self::assertTrue($this->site->exists('1044/400x/photo.jpg.webp'));
        self::assertTrue($this->site->exists('1044/photo.jpg.avif'));
    }
}
