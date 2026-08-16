<?php

declare(strict_types=1);

namespace Atispro\Img\Process;

use Atispro\Img\Config;
use Atispro\Img\Exception\BackendException;
use Atispro\Img\Exception\ProcessException;
use Atispro\Img\Request\ImageRequest;
use Imagick;

/**
 * Native ext-imagick backend. Preferred when available: no process spawn.
 *
 * Geometry comes from {@see EncodePlan} rather than being worked out here, so
 * this and the CLI backend cannot drift apart.
 */
final readonly class ImagickProcessor implements ProcessorInterface
{
    public function __construct(private Config $config)
    {
    }

    public function process(ImageRequest $request, string $outputFile, string $encodeAs): void
    {
        $this->applyLimits();

        try {
            $im = new Imagick($request->srcFile);
        } catch (\Throwable $e) {
            throw new ProcessException("imagick could not open {$request->srcFile}: {$e->getMessage()}", 0, $e);
        }

        try {
            $im->autoOrientImage();

            // Asked before stripImage(), which discards the alpha information
            // the format decision depends on.
            $hasAlpha = $this->hasAlpha($im);

            $im->stripImage();

            $plan = EncodePlan::for($request, $this->config, $hasAlpha, Capabilities::avifAlpha($this->config));
            if ($encodeAs !== $request->extension) {
                // An intermediate for an external encoder.
                $plan = $plan->asFormat($encodeAs);
            }

            if ($plan->needsResize()) {
                // bestfit off: the plan's dimensions are already absolute, and
                // letting Imagick re-fit them would reintroduce its own rounding.
                $im->thumbnailImage($plan->resizeWidth, $plan->resizeHeight, false, false);
            }

            if ($plan->crop !== null) {
                [$cropWidth, $cropHeight, $x, $y] = $plan->crop;
                $im->cropImage($cropWidth, $cropHeight, $x, $y);
                $im->setImagePage(0, 0, 0, 0); // +repage
            }

            $im->adaptiveSharpenImage($this->config->sharpen['radius'], $this->config->sharpen['sigma']);

            foreach ($request->filterPipeline() as $op) {
                [$method, $args] = $op['imagick'];
                $im->{$method}(...$args);
            }

            $im->setImageFormat($plan->encodeAs);

            $format = $this->config->format($plan->encodeAs);
            if (isset($format['quality'])) {
                $im->setImageCompressionQuality((int) $format['quality']);
            }
            if (!empty($format['interlace'])) {
                $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            }
            if (isset($format['method']) && $plan->encodeAs === 'webp') {
                $im->setOption('webp:method', (string) $format['method']);
            }
            if ($plan->noChromaSubsampling) {
                // 4:4:4 — matches the CLI backend, and keeps hard colour edges
                // in screenshots and logos from bleeding.
                $im->setSamplingFactors(['1x1', '1x1', '1x1']);
            }

            if (!$im->writeImage($outputFile)) {
                throw new ProcessException("imagick failed to write {$outputFile}");
            }
        } catch (ProcessException $e) {
            throw $e;
        } catch (\Error $e) {
            // A missing method or a class that will not load says the build is
            // broken, not this image — that is what justifies demoting it.
            throw new BackendException("imagick build is unusable: {$e->getMessage()}", 0, $e);
        } catch (\Throwable $e) {
            throw new ProcessException("imagick failed on {$request->srcFile}: {$e->getMessage()}", 0, $e);
        } finally {
            $im->clear();
            $im->destroy();
        }
    }

    /** True when the image carries a non-opaque alpha channel. */
    private function hasAlpha(Imagick $im): bool
    {
        try {
            if (!$im->getImageAlphaChannel()) {
                return false;
            }

            // An alpha channel that is fully opaque does not need protecting.
            return $im->getImageProperty('opaque') !== 'true';
        } catch (\Throwable) {
            // Unknown: assume there is alpha, so the safer format is chosen.
            return true;
        }
    }

    /**
     * Bound what a single conversion can consume. Without this a large blur
     * radius or a decompression-bomb source is unbounded CPU and RAM — and
     * getimagesize() only reads the header, so the pixel count is not a
     * sufficient guard on its own.
     */
    private function applyLimits(): void
    {
        $map = [
            'area' => Imagick::RESOURCETYPE_AREA,
            'memory' => Imagick::RESOURCETYPE_MEMORY,
            'map' => Imagick::RESOURCETYPE_MAP,
            'disk' => Imagick::RESOURCETYPE_DISK,
            'time' => Imagick::RESOURCETYPE_TIME,
        ];

        foreach ($this->config->limits as $name => $value) {
            if (!isset($map[$name])) {
                continue;
            }

            try {
                Imagick::setResourceLimit($map[$name], self::toInt($value));
            } catch (\Throwable) {
                // An unsupported resource type on this build is not fatal.
            }
        }
    }

    /**
     * Expand the suffixed forms ImageMagick's own `-limit` accepts into the
     * plain integer the extension wants.
     */
    private static function toInt(string $value): int
    {
        if (!preg_match('/^\s*([\d.]+)\s*([A-Za-z]*)\s*$/', $value, $m)) {
            return 0;
        }

        $number = (float) $m[1];

        $factor = match (strtolower($m[2])) {
            'kp' => 1_000,
            'mp' => 1_000_000,
            'gp' => 1_000_000_000,
            'kib', 'kb', 'k' => 1024,
            'mib', 'mb', 'm' => 1024 ** 2,
            'gib', 'gb', 'g' => 1024 ** 3,
            default => 1,
        };

        return (int) ($number * $factor);
    }
}
