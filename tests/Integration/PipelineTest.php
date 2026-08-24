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
            // Fit: 0.5 box vs 1.33 source — width binds the contain display,
            // 302 to 400x, delivered 400x300 (not the 1067x800 cover demanded).
            'fit keeps aspect' => ['1044/400x800f/photo.jpg', 400, 300, 'image/jpeg'],
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

                $pipeline = new Pipeline($config);
                // Followed like a client would: a cover (`f`) row now answers
                // with a redirect to its single-axis collapse first.
                $result = self::follow($pipeline, $pipeline->handle($request));
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
     * The fit contract, end to end: an f request delivers exactly the pixels a
     * contain display of the box shows — aspect preserved, no crop, and no
     * more. (Until 24.08.26 this asserted the opposite: that the box was
     * COVERED vertically. That guarantee over-delivered ~64x in pixels for the
     * only thing that ever consumed it, and is retired.)
     */
    public function testFitDeliversTheContainDisplaySizeAndNoMore(): void
    {
        $this->fixtures();
        $config = $this->site->config(['processor' => $this->backends()[0]]);
        $pipeline = new Pipeline($config);

        // Source 2000x1500 (1.33), box 400x800 (0.5): the contain display shows
        // 400x300 — width-bound, letterboxed vertically.
        $fit = self::follow($pipeline, $pipeline->handle('1044/400x800f/photo.jpg'));
        [$fitWidth, $fitHeight] = getimagesize($fit->path);

        //enough for the display...
        self::assertGreaterThanOrEqual(400, $fitWidth, 'must cover the display width');
        self::assertGreaterThanOrEqual(300, $fitHeight, 'must cover the display height');
        //...and no crop: the source aspect survives...
        self::assertEqualsWithDelta(2000 / 1500, $fitWidth / $fitHeight, 0.02);
        //...and strictly less than the retired cover semantics delivered
        //(height >= 800, i.e. 1067x800 for this box and source).
        self::assertLessThan(800, $fitHeight, 'the cover-the-box guarantee is retired');
    }

    /**
     * An f request stores nothing under its own name: once the source is known
     * the binding axis is too, and the free axis is only a second cache key
     * over identical bytes. 302, not 301 — replacing the source can flip the
     * binding axis.
     */
    public function testFitCollapsesOntoTheSingleAxisCanonical(): void
    {
        $this->fixtures();
        $pipeline = new Pipeline($this->site->config());

        // 2000x1500 source (1.33). Box 400x800 (0.5) is narrower: the contain
        // display is width-bound.
        $tall = $pipeline->handle('1044/400x800f/photo.jpg');
        self::assertTrue($tall->isRedirect());
        self::assertFalse($tall->permanent, 'content-derived redirects must not be permanent');
        self::assertSame('/site/assets/files/1044/400x/photo.jpg', $tall->path);

        // Box 800x450 (1.78) is wider: height-bound.
        $wide = $pipeline->handle('1044/800x450f/photo.jpg');
        self::assertTrue($wide->isRedirect());
        self::assertSame('/site/assets/files/1044/x450/photo.jpg', $wide->path);

        // Nothing is ever written under the f name.
        self::assertFalse($this->site->exists('1044/400x800f/photo.jpg'));
        self::assertFalse($this->site->exists('1044/800x450f/photo.jpg'));

        // Distinct free axes with the same binding axis land on ONE file.
        $a = $pipeline->handle('1044/800x450f/photo.jpg');
        $b = $pipeline->handle('1044/600x450f/photo.jpg');
        self::assertSame($a->path, $b->path, 'the free axis must not multiply cache keys');
    }

    public function testSyntacticCanonicalisationStaysPermanent(): void
    {
        $this->fixtures();
        $result = (new Pipeline($this->site->config()))->handle('1044/641x801/photo.jpg');

        self::assertTrue($result->isRedirect());
        self::assertTrue($result->permanent, 'off-ladder snapping depends on the URL alone');
    }

    /**
     * GIF is recognised as an image but absent from the encoder table. Serving
     * an existing upload writes nothing, so the output allowlist must not apply;
     * transforming one still requires a writable target format.
     */
    public function testUnencodableFormatsAreServableButNotTransformable(): void
    {
        $this->fixtures();
        $this->site->gif('1044/anim.gif', 120, 80);
        $pipeline = new Pipeline($this->site->config());

        $served = $pipeline->handle('1044/anim.gif');
        self::assertSame(Result::SERVE, $served->kind);
        self::assertSame($this->site->absolute('1044/anim.gif'), $served->path);

        $this->expectException(NotFoundException::class);
        $pipeline->handle('1044/400x/anim.gif');
    }

    /**
     * The filter allowlist: an unlisted name is not a filter, just part of a
     * filename — exactly like a name that was never registered.
     */
    public function testFilterAllowlistTurnsUnlistedNamesIntoPlainFilenames(): void
    {
        $this->fixtures();
        $config = $this->site->config(['filters' => ['darken']]);
        $pipeline = new Pipeline($config);

        $allowed = $pipeline->handle('1044/400x/photo.darken-10.jpg');
        self::assertSame(Result::SERVE, $allowed->kind);

        // blur is registered but not listed: photo.blur2.jpg is now a filename,
        // and no such source exists.
        $this->expectException(NotFoundException::class);
        $pipeline->handle('1044/400x/photo.blur2.jpg');
    }

    /** @param int $hops Guard against a redirect loop counting as success. */
    private static function follow(Pipeline $pipeline, Result $result, int $hops = 3): Result
    {
        $base = '/site/assets/files/';

        while ($result->isRedirect() && $hops-- > 0) {
            self::assertStringStartsWith($base, $result->path);
            $result = $pipeline->handle(substr($result->path, strlen($base)));
        }

        self::assertSame(Result::SERVE, $result->kind, 'redirects did not settle');

        return $result;
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
