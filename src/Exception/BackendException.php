<?php

declare(strict_types=1);

namespace Atispro\Img\Exception;

/**
 * The backend itself is unusable on this host — a missing method on an old
 * build, a library that will not load, a binary that is not really ImageMagick.
 *
 * Distinct from a plain ProcessException, which means "this particular image
 * failed". Only this one justifies demoting a backend for every later request;
 * treating a corrupt upload as evidence of a broken build kept the extension
 * switched off host-wide for as long as anything kept requesting that file.
 */
final class BackendException extends ImgException
{
}
