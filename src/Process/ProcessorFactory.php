<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Config;

/**
 * Backend selection. 'auto' prefers imagick, falls back to the CLI, and gives
 * up rather than guessing.
 */
final class ProcessorFactory
{
    public static function make(Config $config): ?ProcessorInterface
    {
        return match ($config->processor) {
            'imagick' => Capabilities::hasImagick($config) ? new ImagickProcessor($config) : null,
            'cli' => Capabilities::hasCli($config) ? new CliProcessor($config) : null,
            default => self::auto($config),
        };
    }

    private static function auto(Config $config): ?ProcessorInterface
    {
        if (Capabilities::hasImagick($config)) {
            return new ImagickProcessor($config);
        }

        if (Capabilities::hasCli($config)) {
            return new CliProcessor($config);
        }

        return null;
    }
}
