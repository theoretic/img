<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Config;
use Atispro\Img\Request\FocalPoint;
use Atispro\Img\Request\ImageRequest;

/**
 * Exactly what to do to an image, decided once and executed identically by
 * either backend.
 *
 * The two backends used to each do their own arithmetic and disagreed:
 *
 *   - imagick forced the computed size while the CLI passed a bare `WxH`,
 *     which ImageMagick reads as fit-inside — so the same URL cached 402x300
 *     on one host and 401x300 on another.
 *   - the crop offset was computed against `resizedSize()`, which rounds up
 *     with ceil(), while `-resize WxH^` uses ImageMagick's own rounding. Where
 *     those differed by a pixel the CLI cropped past the edge of the canvas and
 *     returned an image one pixel narrow.
 *   - the AVIF alpha substitution and 4:4:4 sampling existed only in the CLI
 *     path, so an imagick host silently shipped flattened, chroma-subsampled
 *     AVIF for a transparent source.
 *
 * Every dimension here is absolute and pre-rounded, so both backends perform a
 * plain exact resize followed by an optional crop, and neither gets to apply a
 * rounding rule of its own.
 */
final readonly class EncodePlan
{
    /**
     * @param int $resizeWidth 0 when no resize is needed
     * @param array{0:int,1:int,2:int,3:int}|null $crop [width, height, x, y]
     */
    private function __construct(
        public int $resizeWidth,
        public int $resizeHeight,
        public ?array $crop,
        public string $encodeAs,
        public bool $noChromaSubsampling,
    ) {
    }

    /**
     * @param bool $sourceHasAlpha Whether the source carries transparency; the
     *        backends establish this their own way, but the rule applied to it
     *        is shared so their output cannot diverge.
     */
    public static function for(
        ImageRequest $request,
        Config $config,
        bool $sourceHasAlpha,
        bool $avifAlphaSupported,
    ): self {
        [$resizeWidth, $resizeHeight] = self::resize($request);

        $crop = null;
        if ($resizeWidth !== 0 && $request->needsCrop()) {
            [$x, $y] = $request->cropOffset($resizeWidth, $resizeHeight, FocalPoint::read($request->srcFile, $config->iptcClassPath));

            // Never ask for more than the canvas actually holds.
            $cropWidth = min($request->targetWidth, $resizeWidth);
            $cropHeight = min($request->targetHeight, $resizeHeight);

            $crop = [$cropWidth, $cropHeight, $x, $y];
        }

        $encodeAs = $request->extension;
        if ($encodeAs === 'avif' && $sourceHasAlpha && !$avifAlphaSupported) {
            // This build flattens alpha in AVIF. WebP carries it, and the
            // Content-Type is read from the bytes, so the browser is unaffected.
            $encodeAs = 'webp';
        }

        return new self(
            resizeWidth: $resizeWidth,
            resizeHeight: $resizeHeight,
            crop: $crop,
            encodeAs: $encodeAs,
            noChromaSubsampling: $encodeAs === 'avif',
        );
    }

    /** An intermediate encode for an external encoder: geometry only. */
    public function asFormat(string $encodeAs): self
    {
        return new self(
            resizeWidth: $this->resizeWidth,
            resizeHeight: $this->resizeHeight,
            crop: $this->crop,
            encodeAs: $encodeAs,
            noChromaSubsampling: false,
        );
    }

    public function needsResize(): bool
    {
        return $this->resizeWidth !== 0 && $this->resizeHeight !== 0;
    }

    /**
     * Absolute pixel size to resize to before any crop. Both axes are always
     * resolved here — a free axis is derived with round(), which is the rule
     * ImageMagick itself applies to `-resize 400x`.
     *
     * @return array{0:int,1:int}
     */
    private static function resize(ImageRequest $request): array
    {
        $targetWidth = $request->targetWidth;
        $targetHeight = $request->targetHeight;

        if ($targetWidth === 0 && $targetHeight === 0) {
            return [0, 0];
        }

        if ($request->srcWidth < 1 || $request->srcHeight < 1) {
            return [0, 0];
        }

        $aspect = $request->srcWidth / $request->srcHeight;

        // One free axis: derive it, keeping the source aspect.
        if ($targetWidth === 0) {
            return [max(1, (int) round($targetHeight * $aspect)), $targetHeight];
        }

        if ($targetHeight === 0) {
            return [$targetWidth, max(1, (int) round($targetWidth / $aspect))];
        }

        // Both axes, and a crop follows: fill the box, overshooting one axis.
        if ($request->needsCrop()) {
            return $request->resizedSize();
        }

        // Both axes, no crop: fit inside the box.
        $scale = min($targetWidth / $request->srcWidth, $targetHeight / $request->srcHeight);

        return [
            max(1, (int) round($request->srcWidth * $scale)),
            max(1, (int) round($request->srcHeight * $scale)),
        ];
    }
}
