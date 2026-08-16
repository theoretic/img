<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Exception\ProcessException;

/**
 * Hands the final encode to a dedicated binary — cavif for PNG-sourced AVIF,
 * say, which produces materially smaller files than ImageMagick's own coder.
 *
 * The pipeline writes a lossless intermediate first, then this runs
 * `<bin> [args] <intermediate> -o <output>`.
 *
 * The legacy implementation of this gated on a config flag rather than on the
 * source format, so on every non-Windows host it appended a cavif call to
 * unrelated conversions — a stray process per request and a spurious .avif
 * sibling next to jpg->webp output.
 */
final class ExternalEncoder
{
    /**
     * @param array{bin:string,args:list<string>,from:list<string>} $encoder
     * @throws ProcessException
     */
    public static function run(array $encoder, string $intermediate, string $outputFile): void
    {
        $argv = [$encoder['bin'], ...$encoder['args'], $intermediate, '-o', $outputFile];

        $result = Capabilities::run($argv);
        if ($result['rc'] !== 0 || !is_file($outputFile)) {
            throw new ProcessException(sprintf(
                '%s exited %d: %s',
                $encoder['bin'],
                $result['rc'],
                trim($result['err']) !== '' ? trim($result['err']) : trim($result['out']),
            ));
        }
    }

    /**
     * Whether the configured binary is present and runnable.
     *
     * Memoised per binary: this is asked once per generation, and a request
     * that falls back from one backend to the other asks twice.
     *
     * @var array<string,bool>
     */
    private static array $available = [];

    public static function available(string $bin): bool
    {
        return self::$available[$bin] ??= Capabilities::run([$bin, '--version'])['rc'] === 0;
    }
}
