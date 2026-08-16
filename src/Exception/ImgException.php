<?php

declare(strict_types=1);

namespace Atispro\Img\Exception;

use RuntimeException;

/**
 * Base for everything this package throws, so a caller can catch the whole
 * package with one clause.
 */
class ImgException extends RuntimeException
{
}
