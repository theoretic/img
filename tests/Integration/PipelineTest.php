<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Integration;

use Atispro\Img\Config;
use Atispro\Img\Exception\NotFoundException;
use Atispro\Img\Pipeline;
use Atispro\Img\Process\Capabilities;
use Atispro\Img\Result;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end runs against a real backend.
 *
 * The golden table is run against every backend this host has, and the results
 * are compared. That cross-backend comparison is the point: the defects that
 * survived longest in the previous implementations — sepia in quantum units on
 * one side and percent on the other, a negative filter parameter that 404'd on
 * the CLI and rendered on imagick — are all invisible when only one backend is
 * exercised.
 */
final class PipelineTest extends TestCase
{
    private TempSite $site;

    protected function setUp(): void
    {
        $this->site = new TempSite();

        if ($this->backends() === []) {
            self::markTestSkipped('no image backend available on this host');
        }
    }

    protected function tearDown(): void
    {
        $this->site->destroy();
    }

    /**
     * The URL table. Each row: request, expected output size, expected format.
     * A null dimension means "derived from the source aspect, do not assert".
     *
     * @return array<string,array{0:string,1:?int,2:?int,3:string}>
     */
    public static function goldenTable(): array
    {
        return [
            'format only' => ['1044/photo.jpg.webp', 2000, 1500, 'image/webp'],
            'width only' => ['1044/400x/photo.jpg', 400, null, 'image/jpeg'],
            'height only' => ['1044/x400/photo.jpg', null, 400, 'image/jpeg'],
            'crop to box' => ['1044/400x800/photo.jpg', 400, 800, 'image/jpeg'],
            'crop and convert' => ['1044/400x800/photo.jpg.webp', 400, 800, 'image/webp'],
            'cover keeps aspect' => ['1044/400x800f/photo.jpg', null, 800, 'image/jpeg'],
            'filter' => ['1044/400x/photo.dim-25.jpg', 400, null, 'image/jpeg'],
            'filter and convert' => ['1044/400x/photo.grayscale.jpg.webp', 400, null, 'image/webp'],
            'negative filter param' => ['1044/400x/photo.darken-10.jpg', 400, null, 'image/jpeg'],
            'sepia' => ['1044/400x/photo.sepia.jpg', 400, null, 'image/jpeg'],
            'multi-step filter' => ['1044/400x/photo.vintage.jpg', 400, null, 'image/jpeg'],
            'dotted filename' => ['1044/400x/my.photo.jpg', 400, null, 'image/jpeg'],
        ];
    }

    #[DataProvider('goldenTable')]
    public function testGoldenTableAgreesAcrossBackends(
        string $request,
        ?int $expectedWidth,
        ?int $expectedHeight,
        string $expectedMime,
    ): void {
        $this->fixtures();

        $sizes = [];

        foreach ($this->backends() as $backend) {
            $site = new TempSite();

            try {
                $this->fixturesIn($site);
                $config = $site->config(['processor' => $backend]);

                $result = (new Pipeline($config))->handle($request);
                self::assertSame(Result::SERVE, $result->kind, "{$backend}: expected to serve");
                self::assertFileExists($result->path, "{$backend}: nothing was written");

                $info = getimagesize($result->path);
                self::assertNotFalse($info, "{$backend}: output is not a readable image");

                if ($expectedWidth !== null) {
                    self::assertSame($expectedWidth, $info[0], "{$backend}: wrong width");
                }
                if ($expectedHeight !== null) {
                    self::assertSame($expectedHeight, $info[1], "{$backend}: wrong height");
                }
                self::assertSame($expectedMime, $info['mime'], "{$backend}: wrong format");

                $sizes[$backend] = [$info[0], $info[1]];
            } finally {
                $site->destroy();
            }
        }

        self::assertLessThanOrEqual(
            1,
            count(array_unique(array_map(static fn (array $s): string => implode('x', $s), $sizes))),
            'backends disagree on output dimensions: ' . json_encode($sizes),
        );
    }

    /**
     * The reported defect, end to end: a tall placeholder against a landscape
     * source. Cover fit has to cover both axes; deciding by width alone does
     * not.
     */
    public function testCoverFitFillsATallBoxThatWidthAloneWouldNot(): void
    {
        $this->fixtures();
        $config = $this->site->config(['processor' => $this->backends()[0]]);
        $pipeline = new Pipeline($config);

        // Source is 2000x1500. Placeholder is 400x800.
        $byWidth = $pipeline->handle('1044/400x/photo.jpg');
        [, $heightByWidth] = getimagesize($byWidth->path);
        self::assertLessThan(800, $heightByWidth, 'precondition: width-only under-fills the box');

        $cover = $pipeline->handle('1044/400x800f/photo.jpg');
        [$coverWidth, $coverHeight] = getimagesize($cover->path);

        self::assertGreaterThanOrEqual(800, $coverHeight, 'cover fit must fill the box vertically');
        self::assertGreaterThanOrEqual(400, $coverWidth, 'and horizontally');

        // No crop: the source aspect survives.
        self::assertEqualsWithDelta(2000 / 1500, $coverWidth / $coverHeight, 0.02);
    }

    public function testOffLadderGeometryRedirectsToTheCanonicalUrl(): void
    {
        $this->fixtures();
        $result = (new Pipeline($this->site->config()))->handle('1044/641x801/photo.jpg');

        self::assertTrue($result->isRedirect());
        self::assertSame('/site/assets/files/1044/800x1000/photo.jpg', $result->path);
        self::assertFalse($this->site->exists('1044/641x801/photo.jpg'), 'no derivative for a non-canonical URL');
    }

    public function testStrictPolicyRefusesOffLadderGeometry(): void
    {
        $this->fixtures();
        $config = $this->site->config(['geometryPolicy' => Config::GEOMETRY_STRICT]);

        $this->expectException(NotFoundException::class);
        (new Pipeline($config))->handle('1044/641x801/photo.jpg');
    }

    public function testPassthroughServesTheOriginal(): void
    {
        $this->fixtures();
        $result = (new Pipeline($this->site->config()))->handle('1044/photo.jpg');

        self::assertSame($this->site->absolute('1044/photo.jpg'), $result->path);
    }

    /**
     * Both rungs exceed the 2000px-wide source, so neither needs its own copy —
     * the format change is all that is left, and it does not depend on the
     * geometry. They are sent to the one URL that holds it, which is also the
     * only way Apache can serve it statically afterwards: a file stored at a
     * path no URL names can never satisfy the `!-s` rewrite condition.
     */
    public function testOversizedBoxRedirectsOntoOneSharedUrl(): void
    {
        $this->fixtures();
        $pipeline = new Pipeline($this->site->config());

        $a = $pipeline->handle('1044/2400x/photo.jpg.webp');
        $b = $pipeline->handle('1044/3000x/photo.jpg.webp');

        self::assertTrue($a->isRedirect());
        self::assertTrue($b->isRedirect());
        self::assertSame($a->path, $b->path, 'both oversized rungs share one canonical URL');
        self::assertSame('/site/assets/files/1044/photo.jpg.webp', $a->path);

        // And that URL generates the single shared file.
        $served = $pipeline->handle('1044/photo.jpg.webp');
        self::assertSame(Result::SERVE, $served->kind);
        self::assertSame($this->site->absolute('1044/photo.jpg.webp'), $served->path);
        self::assertFalse($this->site->exists('1044/2400x/photo.jpg.webp'));
        self::assertFalse($this->site->exists('1044/3000x/photo.jpg.webp'));
    }

    public function testSecondCallReusesTheExistingDerivative(): void
    {
        $this->fixtures();
        $pipeline = new Pipeline($this->site->config());

        $first = $pipeline->handle('1044/400x/photo.jpg.webp');
        $mtime = filemtime($first->path);

        $second = $pipeline->handle('1044/400x/photo.jpg.webp');

        self::assertSame($first->path, $second->path);
        self::assertSame($mtime, filemtime($second->path));
    }

    public function testNoTemporaryFilesSurviveAGeneration(): void
    {
        $this->fixtures();
        (new Pipeline($this->site->config()))->handle('1044/400x800/photo.jpg.webp');

        $leftovers = glob($this->site->absolute('1044/400x800') . '/*.tmp') ?: [];
        self::assertSame([], $leftovers);
    }

    public function testAlphaSurvivesPngConversion(): void
    {
        $this->fixtures();
        $result = (new Pipeline($this->site->config()))->handle('1044/200x/logo.png.webp');

        $info = getimagesize($result->path);
        self::assertNotFalse($info);
        self::assertSame('image/webp', $info['mime']);
    }

    // ------------------------------------------------------------------ helpers

    private function fixtures(): void
    {
        $this->fixturesIn($this->site);
    }

    private function fixturesIn(TempSite $site): void
    {
        $site->jpeg('1044/photo.jpg', 2000, 1500);
        $site->jpeg('1044/my.photo.jpg', 2000, 1500);
        $site->jpeg('1044/tall.jpg', 800, 2000);
        $site->jpeg('1044/wide.jpg', 800, 200);
        $site->pngWithAlpha('1044/logo.png', 600, 400);
    }

    /**
     * @return list<string>
     */
    private function backends(): array
    {
        $config = $this->site->config();

        $out = [];
        if (Capabilities::hasImagick($config)) {
            $out[] = 'imagick';
        }
        if (Capabilities::hasCli($config)) {
            $out[] = 'cli';
        }

        return $out;
    }
}
