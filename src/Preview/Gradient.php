<?php

declare(strict_types=1);

namespace Atispro\Img\Preview;

use Atispro\Img\Config;
use Atispro\Img\Exception\BackendException;
use Atispro\Img\Process\CornerSampler;
use Atispro\Img\Process\ProcessorFactory;

/**
 * A placeholder for an image that is still loading: four corner colours, from
 * which the page's CSS draws a soft gradient.
 *
 * Why four colours and not a tiny image. A 16px copy, blurred, reads as a broken
 * version of the picture. At 2x2 to 4x4 the browsers' upscaling decides what you
 * see: Chrome paints a 2x2 background as one flat colour (its mipmap collapses
 * it) and a 2x2 <img> with a visible seam, and 3x3/4x4 backgrounds lose contrast
 * depending on the scale. Four colours in CSS custom properties render the same
 * everywhere, need no decode and no blur filter, and weigh ~76 bytes of markup.
 *
 * Contrast. The four quadrants of a web page screenshot average out close to
 * each other, so the raw corners are nearly flat. Each is pushed $contrast times
 * further from their mean (1.6 by default, chosen side by side on real case
 * screenshots: 2.2 turned some garish) and clamped to 0-255.
 */
final readonly class Gradient
{
    public const CONTRAST = 1.6;

    /**
     * @param array{0:string,1:string,2:string,3:string} $colors "#rrggbb", top-left, top-right, bottom-left, bottom-right
     */
    private function __construct(public array $colors)
    {
    }

    /**
     * Sample $srcFile with the configured backend.
     *
     * @throws BackendException when no backend on this host can sample
     * @throws \Atispro\Img\Exception\ProcessException when this file could not be read
     */
    public static function fromFile(string $srcFile, Config $config, float $contrast = self::CONTRAST): self
    {
        $sampler = ProcessorFactory::make($config);
        if (!$sampler instanceof CornerSampler) {
            throw new BackendException('no image backend that can sample corners');
        }

        return self::fromCorners($sampler->corners($srcFile), $contrast);
    }

    /**
     * @param array{0:array{int,int,int},1:array{int,int,int},2:array{int,int,int},3:array{int,int,int}} $rgb
     */
    public static function fromCorners(array $rgb, float $contrast = self::CONTRAST): self
    {
        $mean = [0.0, 0.0, 0.0];
        foreach ($rgb as $pixel) {
            for ($i = 0; $i < 3; $i++) {
                $mean[$i] += $pixel[$i] / 4;
            }
        }

        $colors = [];
        foreach ($rgb as $pixel) {
            $hex = '#';
            for ($i = 0; $i < 3; $i++) {
                $value = (int) round($mean[$i] + $contrast * ($pixel[$i] - $mean[$i]));
                $hex .= sprintf('%02x', max(0, min(255, $value)));
            }
            $colors[] = $hex;
        }

        return new self($colors);
    }

    /**
     * Read back what {@see toString()} wrote, for a caller that caches it.
     * Null for anything else, so a damaged cache entry is remade, not trusted.
     */
    public static function fromString(string $value): ?self
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $part) {
            if (!preg_match('/^#[0-9a-f]{6}$/', $part)) {
                return null;
            }
        }

        /** @var array{0:string,1:string,2:string,3:string} $parts */
        return new self($parts);
    }

    /** "#rrggbb #rrggbb #rrggbb #rrggbb": top-left, top-right, bottom-left, bottom-right. */
    public function toString(): string
    {
        return implode(' ', $this->colors);
    }

    /**
     * The custom properties for a style attribute:
     * "--lqip-tl:#…;--lqip-tr:#…;--lqip-bl:#…;--lqip-br:#…". Only hex digits
     * and the given name, so it needs no escaping in an attribute.
     */
    public function css(string $name = '--lqip'): string
    {
        [$tl, $tr, $bl, $br] = $this->colors;

        return "{$name}-tl:{$tl};{$name}-tr:{$tr};{$name}-bl:{$bl};{$name}-br:{$br}";
    }
}
