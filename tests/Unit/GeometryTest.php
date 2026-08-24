<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Config;
use Atispro\Img\Request\Geometry;
use PHPUnit\Framework\TestCase;

final class GeometryTest extends TestCase
{
    private function config(): Config
    {
        return Config::fromArray(['imagesPath' => sys_get_temp_dir()]);
    }

    public function testParsesEveryGeometryForm(): void
    {
        $both = Geometry::fromSegment('400x800');
        self::assertNotNull($both);
        self::assertSame([400, 800, Geometry::FIT_CROP], [$both->width, $both->height, $both->fit]);

        $width = Geometry::fromSegment('400x');
        self::assertNotNull($width);
        self::assertSame([400, 0], [$width->width, $width->height]);

        $height = Geometry::fromSegment('x800');
        self::assertNotNull($height);
        self::assertSame([0, 800], [$height->width, $height->height]);

        $cover = Geometry::fromSegment('400x800f');
        self::assertNotNull($cover);
        self::assertSame(Geometry::FIT_COVER, $cover->fit);
    }

    public function testRejectsNonGeometrySegments(): void
    {
        self::assertNull(Geometry::fromSegment('x'), 'bare x carries no constraint');
        self::assertNull(Geometry::fromSegment('400X800'), 'uppercase X is a directory name');
        self::assertNull(Geometry::fromSegment('400x800abc'), 'only a single fit letter is allowed');
        self::assertNull(Geometry::fromSegment('400x800z'), 'unknown fit letter');
        self::assertNull(Geometry::fromSegment('photos'));
    }

    public function testSegmentRoundTrips(): void
    {
        foreach (['400x800', '400x', 'x800', '400x800f'] as $segment) {
            $geometry = Geometry::fromSegment($segment);
            self::assertNotNull($geometry);
            self::assertSame($segment, $geometry->segment());
        }
    }

    public function testLadderMembership(): void
    {
        $config = $this->config();

        self::assertTrue((new Geometry(400, 800))->isOnLadder($config));
        self::assertTrue((new Geometry(400, 0))->isOnLadder($config), 'an unconstrained axis is always fine');
        self::assertFalse((new Geometry(641, 800))->isOnLadder($config));
        self::assertFalse((new Geometry(400, 801))->isOnLadder($config));
    }

    public function testSnapRoundsUpToTheNextRung(): void
    {
        $config = $this->config();

        self::assertSame('800x1000', (new Geometry(641, 801))->snapToLadder($config)->segment());
        self::assertSame('200x', (new Geometry(10, 0))->snapToLadder($config)->segment());
        // One ladder for both axes, so the top rung is the same either way.
        self::assertSame('3000x3000', (new Geometry(9999, 9999))->snapToLadder($config)->segment());
        self::assertSame('400x800f', (new Geometry(400, 800, Geometry::FIT_COVER))->snapToLadder($config)->segment());
    }

    /**
     * Fit semantics: the constrained axis is the one that binds an
     * object-fit:contain display of the box — the SMALLER scale factor, the
     * exact pixels the display shows. (Until 24.08.26 this picked the larger
     * one — cover-the-box — which over-delivered for every contain display.)
     */
    public function testFitPicksTheAxisTheContainDisplayBinds(): void
    {
        $config = $this->config();

        // Box (0.5) narrower than the source (1.33): the display letterboxes
        // vertically, width rules the visible size.
        [$w, $h] = (new Geometry(400, 800, Geometry::FIT_COVER))->resolve(2000, 1500, $config);
        self::assertSame([400, 0], [$w, $h]);

        // The delivered height (400 / 1.33 = 300) is what the display shows —
        // NOT the 800 the retired cover semantics would have demanded.
        self::assertLessThan(800, (int) round(400 / (2000 / 1500)));

        // Box (4.0) wider than the source (0.5): pillarboxed, height rules.
        [$w, $h] = (new Geometry(1600, 400, Geometry::FIT_COVER))->resolve(3000, 6000, $config);
        self::assertSame([0, 400], [$w, $h]);
    }

    public function testFitNeverUpscalesPastTheSource(): void
    {
        $config = $this->config();

        // 400x800 box, 800x200 source: width binds the contain display, and
        // 400 columns exist to give. Delivered 400x100 — the display size of
        // that box, and the very output the old cover mode was invented to
        // "fix": it was only ever a defect for a cover expectation.
        [$w, $h] = (new Geometry(400, 800, Geometry::FIT_COVER))->resolve(800, 200, $config);
        self::assertSame([400, 0], [$w, $h]);

        // A box wider than a tiny source on both axes: height binds (box aspect
        // 4 >= source aspect 1), and only 90 rows exist.
        [$w, $h] = (new Geometry(1600, 400, Geometry::FIT_COVER))->resolve(90, 90, $config);
        self::assertSame([0, 90], [$w, $h]);
    }

    public function testCropFitKeepsBothAxes(): void
    {
        $config = $this->config();

        [$w, $h] = (new Geometry(400, 800))->resolve(2000, 1500, $config);
        self::assertSame([400, 800], [$w, $h]);
    }

    /**
     * Clamping each axis on its own silently changed the requested aspect, and
     * every downstream decision then worked off a ratio nobody asked for.
     */
    public function testOversizedBoxIsScaledDownProportionally(): void
    {
        $config = $this->config();

        [$w, $h] = (new Geometry(4000, 4000))->resolve(3000, 1000, $config);

        self::assertSame([1000, 1000], [$w, $h]);
        self::assertEqualsWithDelta(1.0, $w / $h, 0.001, 'the requested 1:1 aspect has to survive the clamp');
    }

    public function testSingleAxisIsClampedToTheSource(): void
    {
        $config = $this->config();

        self::assertSame([800, 0], (new Geometry(3000, 0))->resolve(800, 600, $config));
        self::assertSame([0, 600], (new Geometry(0, 2000))->resolve(800, 600, $config));
    }

    public function testHardCeilingsApply(): void
    {
        $config = Config::fromArray([
            'imagesPath' => sys_get_temp_dir(),
            'widthMax' => 1000,
            'heightMax' => 1000,
        ]);

        [$w, $h] = (new Geometry(3000, 3000))->resolve(9000, 9000, $config);
        self::assertSame([1000, 1000], [$w, $h]);
    }

    public function testNeedsCropOnlyWhenAspectsDiffer(): void
    {
        // Static, and the single implementation: ImageRequest::needsCrop()
        // delegates here, so the epsilon rule cannot drift between the two.
        self::assertTrue(Geometry::needsCrop(400, 800, 2000, 1500));
        self::assertFalse(Geometry::needsCrop(400, 800, 1000, 2000), 'same 1:2 aspect');
        self::assertFalse(Geometry::needsCrop(400, 0, 2000, 1500), 'one free axis never crops');
    }
}
