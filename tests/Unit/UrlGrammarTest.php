<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Config;
use Atispro\Img\Exception\NotFoundException;
use Atispro\Img\Request\UrlGrammar;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

final class UrlGrammarTest extends TestCase
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

    // ---------------------------------------------------------------- traversal

    public function testRejectsParentDirectorySegments(): void
    {
        $this->expectException(NotFoundException::class);
        UrlGrammar::canonicalPath('../../../../etc/passwd.jpg', $this->site->config());
    }

    public function testRejectsPercentEncodedTraversal(): void
    {
        $this->expectException(NotFoundException::class);
        UrlGrammar::canonicalPath('%2e%2e/%2e%2e/secret.jpg', $this->site->config());
    }

    public function testRejectsBackslashTraversal(): void
    {
        $this->expectException(NotFoundException::class);
        UrlGrammar::canonicalPath('..\\..\\secret.jpg', $this->site->config());
    }

    public function testRejectsNullByte(): void
    {
        $this->expectException(NotFoundException::class);
        UrlGrammar::canonicalPath("1044/photo.jpg\0.txt", $this->site->config());
    }

    /**
     * The containment check has to be per segment: a blanket search for '..'
     * also rejects perfectly ordinary filenames.
     */
    public function testDoubleDotsInsideAFilenameAreFine(): void
    {
        $this->site->jpeg('1044/photo..thing.jpg', 100, 100);

        $image = UrlGrammar::parse('1044/photo..thing.jpg', $this->site->config());
        self::assertSame($this->site->absolute('1044/photo..thing.jpg'), $image->srcFile);
    }

    public function testRejectsPathsOutsideImagesPath(): void
    {
        $outside = $this->site->root . '/outside';
        mkdir($outside, 0o777, true);
        file_put_contents($outside . '/secret.jpg', 'x');

        $this->expectException(NotFoundException::class);
        UrlGrammar::parse('../outside/secret.jpg', $this->site->config());
    }

    // ------------------------------------------------------------------ grammar

    /**
     * Splitting on '.' and taking the second-to-last token as the source
     * extension resolved `my.photo.jpg` to a source extension of "photo".
     * ProcessWire uploads legitimately contain dots.
     */
    public function testDottedSourceFilenamesResolve(): void
    {
        $this->site->jpeg('1044/my.photo.jpg', 200, 100);
        $config = $this->site->config();

        $image = UrlGrammar::parse('1044/my.photo.jpg', $config);
        self::assertSame($this->site->absolute('1044/my.photo.jpg'), $image->srcFile);
        self::assertSame('jpg', $image->srcExtension);
        self::assertSame('jpg', $image->extension);

        $converted = UrlGrammar::parse('1044/my.photo.jpg.webp', $config);
        self::assertSame($this->site->absolute('1044/my.photo.jpg'), $converted->srcFile);
        self::assertSame('webp', $converted->extension);
    }

    public function testDoubleExtensionSelectsTheSource(): void
    {
        $this->site->jpeg('1044/photo.jpg', 200, 100);

        $image = UrlGrammar::parse('1044/photo.jpg.avif', $this->site->config());
        self::assertSame($this->site->absolute('1044/photo.jpg'), $image->srcFile);
        self::assertSame('avif', $image->extension);
        self::assertSame('jpg', $image->srcExtension);
    }

    /**
     * The single-extension filter form was documented but never worked — the
     * filter name was consumed as the source extension.
     */
    public function testSingleExtensionFilterForm(): void
    {
        $this->site->jpeg('1044/photo.jpg', 200, 100);

        $image = UrlGrammar::parse('1044/photo.grayscale.jpg', $this->site->config());
        self::assertNotNull($image->filter);
        self::assertSame('grayscale', $image->filter->name);
        self::assertSame($this->site->absolute('1044/photo.jpg'), $image->srcFile);
    }

    public function testDoubleExtensionFilterForm(): void
    {
        $this->site->jpeg('1044/photo.jpg', 200, 100);

        $image = UrlGrammar::parse('1044/photo.dim-25.jpg.avif', $this->site->config());
        self::assertNotNull($image->filter);
        self::assertSame('dim', $image->filter->name);
        self::assertSame([-25.0], $image->filter->params);
    }

    public function testUnregisteredTokenStaysPartOfTheName(): void
    {
        $this->site->jpeg('1044/photo.landscape.jpg', 200, 100);

        $image = UrlGrammar::parse('1044/photo.landscape.jpg', $this->site->config());
        self::assertNull($image->filter);
        self::assertSame($this->site->absolute('1044/photo.landscape.jpg'), $image->srcFile);
    }

    public function testNonImageExtensionIsNotAnImageRequest(): void
    {
        self::assertNull(UrlGrammar::canonicalPath('1044/notes.txt', $this->site->config()));
    }

    /**
     * Overrides merge into the defaults rather than replacing them, so a format
     * is removed from the allowlist by naming it explicitly as null — not by
     * omitting it, which used to take the whole table with it.
     */
    public function testOutputFormatIsAllowlisted(): void
    {
        $this->site->jpeg('1044/photo.jpg', 200, 100);
        $config = $this->site->config(['formats' => ['webp' => null]]);

        $this->expectException(NotFoundException::class);
        UrlGrammar::parse('1044/photo.jpg.webp', $config);
    }

    /** Setting one format's quality must not drop the others. */
    public function testOverridingOneFormatKeepsTheRest(): void
    {
        $this->site->jpeg('1044/photo.jpg', 200, 100);
        $config = $this->site->config(['formats' => ['avif' => ['quality' => 40]]]);

        self::assertTrue($config->allowsFormat('webp'));
        self::assertTrue($config->allowsFormat('jpg'));
        self::assertSame(40, $config->format('avif')['quality']);

        // And the nested merge keeps sibling keys.
        self::assertSame(4, $config->format('webp')['method']);
    }

    // ------------------------------------------------------------ source lookup

    /**
     * Searching the geometry directory first let an already-generated
     * derivative become the source of the next one, so quality loss compounded
     * on output that had already been sharpened, stripped and filtered.
     */
    public function testSourceIsTakenFromTheParentNotTheGeometryDirectory(): void
    {
        $this->site->jpeg('1044/photo.jpg', 2000, 1500);
        $this->site->jpeg('1044/400x/photo.jpg', 400, 300);

        $image = UrlGrammar::parse('1044/400x/photo.jpg.webp', $this->site->config());

        self::assertSame($this->site->absolute('1044/photo.jpg'), $image->srcFile);
        self::assertSame(2000, $image->srcWidth);
    }

    public function testSourceExceedingMaxPixelsIsRefused(): void
    {
        $this->site->jpeg('1044/photo.jpg', 400, 400);
        $config = $this->site->config(['maxPixels' => 1000]);

        $this->expectException(NotFoundException::class);
        UrlGrammar::parse('1044/photo.jpg', $config);
    }

    // ------------------------------------------------------------ canonical form

    public function testOnLadderRequestIsAlreadyCanonical(): void
    {
        $config = $this->site->config();

        self::assertSame('1044/400x800/photo.jpg.avif', UrlGrammar::canonicalPath('1044/400x800/photo.jpg.avif', $config));
        self::assertSame('1044/400x800f/photo.jpg', UrlGrammar::canonicalPath('/1044/400x800f/photo.jpg', $config));
    }

    public function testOffLadderGeometryCanonicalisesUpwards(): void
    {
        $config = $this->site->config();

        self::assertSame('1044/800x1000/photo.jpg', UrlGrammar::canonicalPath('1044/641x801/photo.jpg', $config));
        self::assertSame('1044/3000x3000/photo.jpg', UrlGrammar::canonicalPath('1044/4500x3000/photo.jpg', $config));
    }

    public function testStrictPolicyRefusesOffLadderGeometry(): void
    {
        $config = $this->site->config(['geometryPolicy' => Config::GEOMETRY_STRICT]);

        $this->expectException(NotFoundException::class);
        UrlGrammar::canonicalPath('1044/641x801/photo.jpg', $config);
    }

    public function testOffPolicyLeavesGeometryAlone(): void
    {
        $config = $this->site->config(['geometryPolicy' => Config::GEOMETRY_OFF]);

        self::assertSame('1044/641x801/photo.jpg', UrlGrammar::canonicalPath('1044/641x801/photo.jpg', $config));
    }

    public function testFilterTokenIsCanonicalisedToo(): void
    {
        $config = $this->site->config();

        self::assertSame('1044/photo.blur.jpg.avif', UrlGrammar::canonicalPath('1044/photo.blur0,2.jpg.avif', $config));
    }

    /**
     * Canonicalising twice must change nothing. Without this, a request could
     * be 301'd — with max-age=604800 — to a URL that itself redirects, or worse
     * to one that cannot resolve at all: `0x0` rendered back as the segment
     * `x`, which the parser then refused.
     */
    public function testCanonicalisationIsIdempotent(): void
    {
        $this->site->jpeg('1044/photo.jpg', 800, 600);
        $config = $this->site->config();

        $inputs = [
            '1044/photo.jpg',
            '1044/400x/photo.jpg',
            '1044/400x800/photo.jpg',
            '1044/400x800f/photo.jpg',
            '1044/641x801/photo.jpg',
            '1044/0x0/photo.jpg',
            '1044/000x/photo.jpg',
            '1044/400xf/photo.jpg',
            '1044/x/photo.jpg',
            '1044/10x10/photo.jpg',
            '1044/photo.blur0,2.jpg',
            '1044/photo.jpg.webp',
        ];

        foreach ($inputs as $input) {
            $once = UrlGrammar::canonicalPath($input, $config);
            if ($once === null) {
                continue;
            }

            self::assertSame($once, UrlGrammar::canonicalPath($once, $config), "not idempotent for {$input}");
        }
    }

    /** A geometry with no constraint at all is an ordinary directory name. */
    public function testZeroGeometryIsNotAGeometry(): void
    {
        $config = $this->site->config();

        // Left as a plain directory, so it resolves against a real path or 404s
        // honestly — rather than redirecting forever to the unparseable "x".
        self::assertSame('1044/0x0/photo.jpg', UrlGrammar::canonicalPath('1044/0x0/photo.jpg', $config));
        self::assertSame('1044/000x/photo.jpg', UrlGrammar::canonicalPath('1044/000x/photo.jpg', $config));
    }

    /**
     * Cover fit needs a box to cover; on one axis it resolves identically to
     * the plain form, so keeping the letter would give one image two cache keys.
     */
    public function testCoverFitOnASingleAxisCanonicalisesToThePlainForm(): void
    {
        $config = $this->site->config();

        self::assertSame('1044/400x/photo.jpg', UrlGrammar::canonicalPath('1044/400xf/photo.jpg', $config));
        self::assertSame('1044/x800/photo.jpg', UrlGrammar::canonicalPath('1044/x800f/photo.jpg', $config));
    }

    /**
     * Lower-casing the source extension made this both unresolvable on a
     * case-sensitive filesystem and the target of a permanent redirect to a URL
     * that could not resolve either.
     */
    public function testUppercaseSourceExtensionResolvesAndDoesNotRedirect(): void
    {
        $this->site->jpeg('1044/photo.JPG', 400, 300);
        $config = $this->site->config();

        self::assertSame('1044/photo.JPG', UrlGrammar::canonicalPath('1044/photo.JPG', $config));

        $image = UrlGrammar::parse('1044/photo.JPG', $config);
        self::assertSame($this->site->absolute('1044/photo.JPG'), $image->srcFile);

        $converted = UrlGrammar::parse('1044/photo.JPG.webp', $config);
        self::assertSame($this->site->absolute('1044/photo.JPG'), $converted->srcFile);
        self::assertSame('webp', $converted->extension);
    }

    /**
     * ImageMagick reads a trailing [n] as a frame selector, so this name would
     * make the CLI backend open photo.jpg and serve its content here.
     */
    public function testSourceNamesUnsafeForTheEncoderAreRefused(): void
    {
        $this->site->jpeg('1044/photo[0].jpg', 200, 100);

        $this->expectException(NotFoundException::class);
        UrlGrammar::parse('1044/photo[0].jpg', $this->site->config());
    }

    public function testRepeatedSlashesCollapse(): void
    {
        $config = $this->site->config();

        self::assertSame('1044/400x/photo.jpg', UrlGrammar::canonicalPath('1044///400x//photo.jpg', $config));
    }
}
