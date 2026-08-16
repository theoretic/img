<?php

declare(strict_types=1);

namespace Atispro\Img\Exception;

/**
 * A configured external encoder failed. The image backend produced its
 * intermediate correctly, so nothing about the backend should be demoted on
 * account of this.
 */
final class EncoderException extends ImgException
{
}
