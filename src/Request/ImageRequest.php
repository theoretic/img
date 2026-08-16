<?php

declare(strict_types=1);

namespace Atispro\Img\Request;

use Atispro\Img\Filter\Token;

/**
 * One resolved request: which source file, which derivative to write, and the
 * geometry to get from one to the other. Immutable, and built only by
 * UrlGrammar.
 */
final readonly class ImageRequest
{
    /**
     * @param int $targetWidth 0 = unconstrained on this axis
     * @param int $targetHeight 0 = unconstrained on this axis
     */
    public function __construct(
        public string $srcFile,
        public string $dstFile,
        public int $srcWidth,
        public int $srcHeight,
        public int $targetWidth,
        public int $targetHeight,
        public string $extension,
        public string $srcExtension,
        public ?Token $filter = null,
    ) {
    }

    /** Nothing to do — source and destination are the same file, no filter. */
    public function isPassthrough(): bool
    {
        return $this->srcFile === $this->dstFile && $this->filter === null;
    }

    /** Will an actual crop happen, as opposed to a plain resize or a format change? */
    public function needsCrop(): bool
    {
        if ($this->targetWidth === 0 || $this->targetHeight === 0 || $this->srcHeight === 0) {
            return false;
        }

        $srcAspect = $this->srcWidth / $this->srcHeight;
        $tgtAspect = $this->targetWidth / $this->targetHeight;

        return abs(($tgtAspect - $srcAspect) / $srcAspect) >= 0.01;
    }

    /**
     * Intermediate size that fills the target box with the source aspect kept.
     * A 0 means "let the backend derive this axis".
     *
     * @return array{0:int,1:int}
     */
    public function resizedSize(): array
    {
        if ($this->targetWidth === 0) {
            return [0, $this->targetHeight];
        }
        if ($this->targetHeight === 0) {
            return [$this->targetWidth, 0];
        }

        $srcAspect = $this->srcWidth / $this->srcHeight;
        $tgtAspect = $this->targetWidth / $this->targetHeight;

        return $tgtAspect >= $srcAspect
            ? [$this->targetWidth, (int) ceil($this->targetWidth / $srcAspect)]
            : [(int) ceil($this->targetHeight * $srcAspect), $this->targetHeight];
    }

    /**
     * Crop offset within the resized image, biased toward the focal point.
     *
     * @param array{x:string,y:string} $focalPoint
     * @return array{0:int,1:int}
     */
    public function cropOffset(int $resizedWidth, int $resizedHeight, array $focalPoint): array
    {
        $fx = (float) str_replace('%', '', $focalPoint['x']);
        $fy = (float) str_replace('%', '', $focalPoint['y']);

        $x = (int) round($resizedWidth * $fx / 100 - $this->targetWidth / 2);
        $y = (int) round($resizedHeight * $fy / 100 - $this->targetHeight / 2);

        return [
            max(0, min($x, $resizedWidth - $this->targetWidth)),
            max(0, min($y, $resizedHeight - $this->targetHeight)),
        ];
    }

    /**
     * Ops contributed by the URL filter, empty when there is none.
     *
     * @return list<array{imagick:array{0:string,1:list<mixed>},cli:list<string>}>
     */
    public function filterPipeline(): array
    {
        return $this->filter?->pipeline() ?? [];
    }
}
