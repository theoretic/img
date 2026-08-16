<?php

declare(strict_types=1);

namespace Atispro\Img\Exception;

/**
 * Configuration is missing a required key or carries an unusable value.
 * Always a deployment error, never a request error.
 */
final class ConfigException extends ImgException
{
}
