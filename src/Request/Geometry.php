<?php

declare(strict_types=1);

namespace Atispro\Img\Request;

use Atispro\Img\Config;

/**
 * The geometry directory of a request path — the `400x800` in
 * `/files/1044/400x800/photo.jpg` — plus the rules that turn it into concrete
 * target dimensions for a given source image.
 *
 * Two fit modes:
 *
 *   400x800   crop  — resize to fill the box, then focal-point crop to exactly
 *                     800x400. The delivered aspect is the box aspect.
 *   400x800f  cover — resize so the box is covered with the SOURCE aspect kept
 *                     and nothing cropped. Only one axis ends up constrained;
 *                     which one depends on how the box aspect compares to the
 *                     source aspect, so it can only be decided here, where the
 *                     source dimensions are known.
 *
 * The cover mode exists because the client cannot make that comparison: it
 * knows the placeholder box but not the source image. Deciding by width alone —
 * which is what adaptive-media.js did — under-fills every box that is taller
 * than the source is. A 380x800 placeholder against an 800x200 source asked for
 * /400x/ and got back 400x100.
 */
final readonly class Geometry
{
    /** Resize to fill, then crop to the exact box. */
    public const FIT_CROP = '';

    /** Resize to cover the box, source aspect kept, no crop. */
    public const FIT_COVER = 'f';

    /** Aspect ratios within this relative distance are treated as equal. */
    private const ASPECT_EPSILON = 0.01;

    public function __construct(
        public int $width,
        public int $height,
        public string $fit = self::FIT_CROP,
    ) {
    }

    /**
     * Parse a path segment. Returns null when the segment is not a geometry
     * directory at all — in which case it is just an ordinary directory name.
     */
    public static function fromSegment(string $segment): ?self
    {
        // D anchors $ at the true end of the string; without it a trailing
        // newline slips through this "syntactic" gate.
        if (!preg_match('/^(\d*)x(\d*)([a-z]?)$/D', $segment, $m)) {
            return null;
        }

        $width = (int) $m[1];
        $height = (int) $m[2];

        // No constraint at all is not a geometry. Covers "x" and also "0x0" and
        // "000x", which would otherwise render back as "x" — a segment this
        // very method refuses, so the request would 301, permanently and
        // cached, to a URL that 404s.
        if ($width === 0 && $height === 0) {
            return null;
        }

        if ($m[3] !== '' && $m[3] !== self::FIT_COVER) {
            return null;
        }

        // Cover fit only means anything with a box to cover. On a single axis
        // it resolves identically to the plain form, so keeping the letter
        // would give one image two cache keys for the same bytes.
        $fit = ($width === 0 || $height === 0) ? self::FIT_CROP : $m[3];

        return new self($width, $height, $fit);
    }

    /** The canonical directory name for this geometry. */
    public function segment(): string
    {
        return ($this->width ?: '') . 'x' . ($this->height ?: '') . $this->fit;
    }

    /**
     * True when both axes sit on the configured ladders. An unconstrained axis
     * (0) is always acceptable.
     */
    public function isOnLadder(Config $config): bool
    {
        if ($this->width !== 0 && !in_array($this->width, $config->widths, true)) {
            return false;
        }

        return $this->height === 0 || in_array($this->height, $config->heights, true);
    }

    /**
     * Nearest canonical geometry: each axis rounded UP to the next ladder rung,
     * so the result never has fewer pixels than was asked for.
     */
    public function snapToLadder(Config $config): self
    {
        return new self(
            self::snap($this->width, $config->widths),
            self::snap($this->height, $config->heights),
            $this->fit,
        );
    }

    /**
     * Concrete target dimensions for this source image. Either axis may come
     * back 0, meaning "derive it from the source aspect".
     *
     * @return array{0:int,1:int}
     */
    public function resolve(int $srcWidth, int $srcHeight, Config $config): array
    {
        $w = $this->width;
        $h = $this->height;

        if ($this->fit === self::FIT_COVER && $w > 0 && $h > 0 && $srcHeight > 0) {
            // Keep the source aspect and cover the box: whichever axis needs the
            // larger scale factor is the binding one, and the other is dropped so
            // the backend derives it proportionally.
            $boxAspect = $w / $h;
            $srcAspect = $srcWidth / $srcHeight;

            if ($boxAspect >= $srcAspect) {
                $h = 0;
            } else {
                $w = 0;
            }
        }

        return $this->clamp($w, $h, $srcWidth, $srcHeight, $config);
    }

    /**
     * True when the resolved box has a different aspect from the source and a
     * crop is therefore needed. Cover mode never crops.
     */
    public function needsCrop(int $targetWidth, int $targetHeight, int $srcWidth, int $srcHeight): bool
    {
        if ($targetWidth === 0 || $targetHeight === 0 || $srcWidth === 0 || $srcHeight === 0) {
            return false;
        }

        $srcAspect = $srcWidth / $srcHeight;
        $tgtAspect = $targetWidth / $targetHeight;

        return abs(($tgtAspect - $srcAspect) / $srcAspect) >= self::ASPECT_EPSILON;
    }

    /**
     * Bring the box inside both the source's own size and the configured hard
     * ceilings.
     *
     * When both axes are constrained this scales the box down PROPORTIONALLY.
     * Clamping the axes independently — which is what the legacy code did —
     * silently changes the requested aspect, and everything downstream
     * (needsCrop, the resize geometry, the crop offset) then works off an aspect
     * nobody asked for. A 4000x4000 request against a 3000x1000 source came back
     * as 2400x1000, i.e. 2.4:1 instead of 1:1.
     *
     * @return array{0:int,1:int}
     */
    private function clamp(int $w, int $h, int $srcWidth, int $srcHeight, Config $config): array
    {
        if ($w > 0 && $h > 0) {
            $k = min(
                1.0,
                $srcWidth / $w,
                $srcHeight / $h,
                $config->widthMax / $w,
                $config->heightMax / $h,
            );

            if ($k < 1.0) {
                $w = max(1, (int) floor($w * $k));
                $h = max(1, (int) floor($h * $k));
            }

            return [$w, $h];
        }

        if ($w > 0) {
            $w = min($w, $srcWidth, $config->widthMax);
        }
        if ($h > 0) {
            $h = min($h, $srcHeight, $config->heightMax);
        }

        return [$w, $h];
    }

    /**
     * @param list<int> $ladder
     */
    private static function snap(int $size, array $ladder): int
    {
        if ($size <= 0) {
            return 0;
        }

        foreach ($ladder as $rung) {
            if ($rung >= $size) {
                return $rung;
            }
        }

        return $ladder[count($ladder) - 1];
    }
}
