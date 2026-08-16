<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Config;
use Atispro\Img\Exception\BackendException;
use Atispro\Img\Exception\ProcessException;
use Atispro\Img\Request\ImageRequest;

/**
 * ImageMagick CLI backend, used when ext-imagick is absent or has demoted
 * itself. One invocation does the whole chain.
 *
 * Arguments are passed as an argv array with bypass_shell, so no shell ever
 * sees them. Geometry comes from {@see EncodePlan}, so this and the imagick
 * backend produce the same pixels for the same URL.
 */
final readonly class CliProcessor implements ProcessorInterface
{
    public function __construct(private Config $config)
    {
    }

    public function process(ImageRequest $request, string $outputFile, string $encodeAs): void
    {
        $bin = Capabilities::cliBinary($this->config);
        if ($bin === null) {
            throw new BackendException('no runnable ImageMagick binary');
        }

        // The alpha probe costs a process spawn, so only ask when the answer
        // could change anything: AVIF output on a build that flattens it.
        $avifAlphaSupported = Capabilities::avifAlpha($this->config);
        $mayNeedAlpha = $request->extension === 'avif' && !$avifAlphaSupported;

        $plan = EncodePlan::for(
            $request,
            $this->config,
            $mayNeedAlpha && $this->hasAlpha($bin, $request->srcFile),
            $avifAlphaSupported,
        );

        if ($encodeAs !== $request->extension) {
            $plan = $plan->asFormat($encodeAs);
        }

        $args = [$bin];

        foreach ($this->config->limits as $name => $value) {
            array_push($args, '-limit', $name, $value);
        }

        // The source is passed unprefixed so ImageMagick still auto-detects the
        // real format — an extension does not always tell the truth. What makes
        // that safe is UrlGrammar refusing source names containing the frame
        // selector characters ImageMagick would otherwise interpret.
        array_push($args, $request->srcFile, '-auto-orient', '-strip');

        if ($plan->needsResize()) {
            // '!' forces the exact size. The plan already resolved both axes,
            // and a bare WxH would be read as fit-inside — which is how the two
            // backends came to disagree by a pixel.
            array_push($args, '-resize', "{$plan->resizeWidth}x{$plan->resizeHeight}!");
        }

        if ($plan->crop !== null) {
            [$cropWidth, $cropHeight, $x, $y] = $plan->crop;
            array_push(
                $args,
                '-gravity',
                'NorthWest',
                '-crop',
                "{$cropWidth}x{$cropHeight}+{$x}+{$y}",
                '+repage',
            );
        }

        array_push(
            $args,
            '-adaptive-sharpen',
            self::num($this->config->sharpen['radius']) . 'x' . self::num($this->config->sharpen['sigma']),
        );

        foreach ($request->filterPipeline() as $op) {
            array_push($args, ...$op['cli']);
        }

        $format = $this->config->format($plan->encodeAs);
        if (isset($format['quality'])) {
            array_push($args, '-quality', (string) $format['quality']);
        }
        if (!empty($format['interlace'])) {
            array_push($args, '-interlace', 'Plane');
        }
        if (isset($format['method']) && $plan->encodeAs === 'webp') {
            array_push($args, '-define', "webp:method={$format['method']}");
        }
        if ($plan->noChromaSubsampling) {
            array_push($args, '-sampling-factor', '1x1');
        }

        // Name the output coder explicitly. Left to the filename, ImageMagick
        // guesses from the extension, which is both wrong when the alpha
        // fallback fired and a way to reach coders like MVG or MSL.
        $args[] = $plan->encodeAs . ':' . $outputFile;

        $result = Capabilities::run($args);
        if ($result['rc'] !== 0) {
            throw new ProcessException(sprintf(
                'convert exited %d for %s: %s',
                $result['rc'],
                $request->srcFile,
                trim($result['err']) !== '' ? trim($result['err']) : trim($result['out']),
            ));
        }
    }

    /**
     * True when the source carries a non-opaque alpha channel.
     *
     * Only asked when it could change the answer — the probe costs a process
     * spawn, and it only matters for AVIF on a build that cannot store alpha.
     */
    private function hasAlpha(string $bin, string $srcFile): bool
    {
        $result = Capabilities::run([$bin, $srcFile, '-format', '%[opaque]', 'info:']);

        if ($result['rc'] !== 0) {
            // Unknown: assume alpha, so the safer format is chosen.
            return true;
        }

        return strcasecmp(trim($result['out']), 'false') === 0;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
