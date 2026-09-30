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
final readonly class CliProcessor implements ProcessorInterface, CornerSampler
{
    /** Source formats that can carry an alpha channel, and so are worth probing. */
    private const ALPHA_SOURCES = ['png', 'webp', 'gif', 'avif', 'heic', 'heif', 'tif', 'tiff'];

    public function __construct(private Config $config)
    {
    }

    public function process(ImageRequest $request, string $outputFile, string $encodeAs): void
    {
        $bin = Capabilities::cliBinary($this->config);
        if ($bin === null) {
            throw new BackendException('no runnable ImageMagick binary');
        }

        // The alpha probe costs a process spawn, so it is only made for a
        // source that can carry alpha at all. Its answer does two jobs: AVIF on
        // a build that flattens alpha falls back to WebP, and a channel that is
        // present but fully opaque — every ProcessWire PNG variation has one —
        // is dropped. Left in, it is encoded as a separate AVIF alpha plane
        // that says nothing, at about a tenth of a small file.
        $avifAlphaSupported = Capabilities::avifAlpha($this->config);
        $probed = in_array($request->srcExtension, self::ALPHA_SOURCES, true);
        $sourceHasAlpha = $probed && $this->hasAlpha($bin, $request->srcFile);

        $plan = EncodePlan::for(
            $request,
            $this->config,
            $sourceHasAlpha,
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

        if ($probed && !$sourceHasAlpha) {
            array_push($args, '-alpha', 'off');
        }

        if ($plan->needsResize()) {
            if ($this->config->resizeFilter !== '') {
                array_push($args, '-filter', $this->config->resizeFilter);
            }
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
            // 4:4:4. The AVIF coder goes through libheif, which ignores
            // -sampling-factor: that spelling wrote 4:2:0 all along, and red
            // text in screenshots bled into the background at small sizes.
            array_push($args, '-define', 'heic:chroma=444');
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

    public function corners(string $srcFile): array
    {
        $bin = Capabilities::cliBinary($this->config);
        if ($bin === null) {
            throw new BackendException('no runnable ImageMagick binary');
        }

        $args = [$bin];
        foreach ($this->config->limits as $name => $value) {
            array_push($args, '-limit', $name, $value);
        }
        // [0]: the first frame of an animation. Box: each corner is the plain
        // average of its quadrant. Alpha goes after the resize, which has
        // already weighted transparent pixels out.
        array_push($args, $srcFile . '[0]', '-auto-orient', '-filter', 'Box', '-resize', '2x2!', '-alpha', 'off', '-depth', '8', 'txt:-');

        $result = Capabilities::run($args);
        if ($result['rc'] !== 0) {
            throw new ProcessException(sprintf(
                'convert exited %d sampling %s: %s',
                $result['rc'],
                $srcFile,
                trim($result['err']) !== '' ? trim($result['err']) : trim($result['out']),
            ));
        }

        // "0,0: (29298,47802,63222)  #72BAF6  srgb(114,186,246)"
        preg_match_all('/^(\d),(\d):.*?#([0-9A-Fa-f]{6})/m', $result['out'], $m, PREG_SET_ORDER);
        $byPosition = [];
        foreach ($m as [, $x, $y, $hex]) {
            $byPosition["{$x},{$y}"] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        }

        $corners = [];
        foreach (['0,0', '1,0', '0,1', '1,1'] as $position) {
            if (!isset($byPosition[$position])) {
                throw new ProcessException("unexpected ImageMagick output sampling {$srcFile}: " . trim($result['out']));
            }
            $corners[] = array_map('intval', $byPosition[$position]);
        }

        /** @var array{0:array{int,int,int},1:array{int,int,int},2:array{int,int,int},3:array{int,int,int}} $corners */
        return $corners;
    }

    /**
     * True when the source carries a non-opaque alpha channel. False for no
     * alpha channel, and for one that is fully opaque.
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
