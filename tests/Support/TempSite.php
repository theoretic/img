<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Support;

use Atispro\Img\Config;
use Atispro\Img\Process\Capabilities;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A throwaway images tree, so tests exercise the real filesystem paths rather
 * than a mock of them — containment, source resolution and atomic publishing
 * are all filesystem behaviour.
 */
final class TempSite
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/atispro-img-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/files', 0o777, true);

        Capabilities::reset();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    public function config(array $overrides = []): Config
    {
        return Config::fromArray(array_merge([
            'imagesPath' => $this->root . '/files',
            'publicBase' => '/site/assets/files',
            'capabilitiesCacheTtl' => 0,
        ], $overrides));
    }

    /** Create a JPEG of the given size and return its path relative to imagesPath. */
    public function jpeg(string $relative, int $width, int $height): string
    {
        $path = $this->path($relative);
        imagejpeg($this->canvas($width, $height), $path, 92);

        return $relative;
    }

    /** Create a PNG with a transparent quadrant. */
    public function pngWithAlpha(string $relative, int $width, int $height): string
    {
        $path = $this->path($relative);
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = (int) imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent);

        $solid = (int) imagecolorallocatealpha($image, 200, 40, 40, 0);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), $solid);

        imagepng($image, $path);

        return $relative;
    }

    public function absolute(string $relative): string
    {
        return $this->root . '/files/' . ltrim($relative, '/');
    }

    public function exists(string $relative): bool
    {
        return is_file($this->absolute($relative));
    }

    /**
     * @return array{0:int,1:int}
     */
    public function size(string $relative): array
    {
        $info = getimagesize($this->absolute($relative));
        if ($info === false) {
            throw new \RuntimeException("not an image: {$relative}");
        }

        return [$info[0], $info[1]];
    }

    public function destroy(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $info) {
            $info->isDir() ? @rmdir($info->getPathname()) : @unlink($info->getPathname());
        }

        @rmdir($this->root);
    }

    private function path(string $relative): string
    {
        $path = $this->absolute($relative);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return $path;
    }

    private function canvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        // A gradient with a hard edge, so resampling and cropping are visible.
        for ($x = 0; $x < $width; $x++) {
            $shade = (int) (255 * $x / max(1, $width - 1));
            $colour = (int) imagecolorallocate($image, $shade, 128, 255 - $shade);
            imagefilledrectangle($image, $x, 0, $x, $height - 1, $colour);
        }

        $mark = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, max(1, intdiv($width, 10)), max(1, intdiv($height, 10)), $mark);

        return $image;
    }
}
