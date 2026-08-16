<?php

declare(strict_types=1);

namespace Atispro\Img\Exception;

/**
 * A backend failed to produce the derivative. Carries the backend's own
 * diagnostics, which the legacy implementations discarded — a failed convert
 * used to surface as an opaque 404 with nothing in the log.
 */
final class ProcessException extends ImgException
{
}
