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
     * The reported defect. A 400x800 placeholder against a landscape source:
     * deciding by width alone under-fills the box vertically, because the
     * delivered image keeps the source aspect.
     */
    public function testCoverFitPicksTheBindingAxis(): void
    {
        $config = $this->config();

        // Box is taller than the source is => height binds.
        [$w, $h] = (new Geometry(400, 800, Geometry::FIT_COVER))->resolve(2000, 1500, $config);
        self::assertSame([0, 800], [$w, $h]);

        // Derived width covers the box comfortably.
        self::assertGreaterThanOrEqual(400, (int) round(800 * (2000 / 1500)));

        // Box is wider than the source is => width binds.
        [$w, $h] = (new Geometry(1600, 400, Geometry::FIT_COVER))->resolve(3000, 6000, $config);
        self::assertSame([1600, 0], [$w, $h]);
    }

    public function testCoverFitDegradesToTheSourceWhenItCannotCover(): void
    {
        $config = $this->config();

        // 400x800 box, 800x200 source: height binds, but there are only 200 rows
        // to give, so the answer is the whole source rather than an upscale.
        [$w, $h] = (new Geometry(400, 800, Geometry::FIT_COVER))->resolve(800, 200, $config);
        self::assertSame([0, 200], [$w, $h]);
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
        $geometry = new Geometry(400, 800);

        self::assertTrue($geometry->needsCrop(400, 800, 2000, 1500));
        self::assertFalse($geometry->needsCrop(400, 800, 1000, 2000), 'same 1:2 aspect');
        self::assertFalse($geometry->needsCrop(400, 0, 2000, 1500), 'one free axis never crops');
    }
}
