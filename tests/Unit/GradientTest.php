<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Preview\Gradient;
use PHPUnit\Framework\TestCase;

final class GradientTest extends TestCase
{
    public function testAFlatImageStaysFlatAtAnyContrast(): void
    {
        $grey = [128, 128, 128];

        $gradient = Gradient::fromCorners([$grey, $grey, $grey, $grey], 3.0);

        self::assertSame(['#808080', '#808080', '#808080', '#808080'], $gradient->colors);
    }

    public function testCornersArePushedAwayFromTheirMean(): void
    {
        // mean 150 on every channel; 1.6 x (±50) = ±80
        $gradient = Gradient::fromCorners([[100, 100, 100], [200, 200, 200], [100, 100, 100], [200, 200, 200]], 1.6);

        self::assertSame(['#464646', '#e6e6e6', '#464646', '#e6e6e6'], $gradient->colors);
    }

    public function testContrastOneKeepsTheSampledColours(): void
    {
        $gradient = Gradient::fromCorners([[1, 2, 3], [4, 5, 6], [7, 8, 9], [250, 251, 252]], 1.0);

        self::assertSame(['#010203', '#040506', '#070809', '#fafbfc'], $gradient->colors);
    }

    public function testChannelsAreClamped(): void
    {
        $gradient = Gradient::fromCorners([[0, 0, 0], [255, 255, 255], [0, 0, 0], [255, 255, 255]], 10.0);

        self::assertSame(['#000000', '#ffffff', '#000000', '#ffffff'], $gradient->colors);
    }

    public function testCssNamesEachCorner(): void
    {
        $gradient = Gradient::fromCorners([[255, 0, 0], [0, 255, 0], [0, 0, 255], [0, 0, 0]], 1.0);

        self::assertSame('--lqip-tl:#ff0000;--lqip-tr:#00ff00;--lqip-bl:#0000ff;--lqip-br:#000000', $gradient->css());
        self::assertSame('--p-tl:#ff0000;--p-tr:#00ff00;--p-bl:#0000ff;--p-br:#000000', $gradient->css('--p'));
    }

    public function testStringRoundTrip(): void
    {
        $gradient = Gradient::fromCorners([[255, 0, 0], [0, 255, 0], [0, 0, 255], [0, 0, 0]], 1.0);

        $again = Gradient::fromString($gradient->toString() . "\n");

        self::assertNotNull($again);
        self::assertSame($gradient->colors, $again->colors);
    }

    public function testDamagedStringsAreRefused(): void
    {
        self::assertNull(Gradient::fromString(''));
        self::assertNull(Gradient::fromString('#ff0000 #00ff00 #0000ff'));
        self::assertNull(Gradient::fromString('#ff0000 #00ff00 #0000ff red'));
        self::assertNull(Gradient::fromString('#FF0000 #00ff00 #0000ff #000000'));
        self::assertNull(Gradient::fromString('#ff0000;x #00ff00 #0000ff #000000'));
    }
}
