<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Exception\BackendException;
use Atispro\Img\Exception\ProcessException;
use Atispro\Img\Request\ImageRequest;

/**
 * A backend that can turn a source image into a derivative.
 *
 * Implementations write to $outputFile rather than to $request->dstFile: the
 * caller hands over a temporary path and publishes it atomically once the write
 * has succeeded.
 */
interface ProcessorInterface
{
    /**
     * @param string $encodeAs Target format, which is not always
     *        $request->extension — an external encoder takes over from an
     *        intermediate, and the CLI backend may substitute a format when the
     *        requested one cannot carry alpha here.
     * @throws ProcessException when this particular image could not be converted
     * @throws BackendException when the backend itself is unusable on this host
     */
    public function process(ImageRequest $request, string $outputFile, string $encodeAs): void;
}
