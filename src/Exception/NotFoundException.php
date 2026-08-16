<?php

declare(strict_types=1);

namespace Atispro\Img\Exception;

/**
 * The request does not resolve to a servable source image — missing file,
 * unreadable file, or a path that failed containment.
 */
final class NotFoundException extends ImgException
{
}
