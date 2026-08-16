<?php

declare(strict_types=1);

namespace Atispro\Img\Exception;

/**
 * Another request is already generating this derivative and did not finish
 * within our patience. The caller should answer 503 with Retry-After rather
 * than block a worker or claim the image does not exist.
 */
final class BusyException extends ImgException
{
}
