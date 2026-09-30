<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Exception\BackendException;
use Atispro\Img\Exception\ProcessException;

/**
 * A backend that can shrink a source to 2x2 and report the four colours: the
 * raw material of {@see \Atispro\Img\Preview\Gradient}.
 *
 * Both backends box-filter the first frame, after auto-orientation, so the same
 * file gives the same corners on either one. Transparent pixels are weighted out
 * by the resize, so a logo on a transparent ground reports the logo's colours.
 */
interface CornerSampler
{
    /**
     * @return array{0:array{int,int,int},1:array{int,int,int},2:array{int,int,int},3:array{int,int,int}}
     *         sRGB 0-255, in the order top-left, top-right, bottom-left, bottom-right
     * @throws ProcessException when this particular file could not be read
     * @throws BackendException when the backend itself is unusable on this host
     */
    public function corners(string $srcFile): array;
}
