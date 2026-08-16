<?php

declare(strict_types=1);

namespace Atispro\Img\Cache;

use Atispro\Img\Config;
use Atispro\Img\Filter\Token;
use Atispro\Img\Request\Geometry;

/**
 * Decides whether a path is something this pipeline generated.
 *
 * One rule, used by both the cleaner (may I delete this?) and the writer (may I
 * overwrite this?): a file is ours only when a plausible **source** for it
 * actually exists. `photo.jpg.webp` beside `photo.jpg` is a conversion we made;
 * the very same name with no `photo.jpg` beside it is somebody's upload and is
 * left alone.
 *
 * That corroboration is what the previous rules lacked. "Two image extensions
 * means we made it" destroyed a genuine `screenshot.png.jpg`, and "the directory
 * name looks like WxH" recursively deleted a content directory called `2x4`,
 * originals included.
 *
 * Residual ambiguity, stated plainly: a file named `photo.png.jpg` sitting
 * beside a real `photo.png` is indistinguishable from the conversion of it, and
 * is treated as ours. `name.<imgext>.<imgext>` beside `name.<imgext>` is the
 * conversion namespace — the URL grammar reserves it, and uploads must not use
 * that shape. Everything outside it is protected.
 */
final class DerivativePath
{
    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'tif', 'tiff', 'bmp',
    ];

    /**
     * True when $path is a derivative, i.e. a source it could have been
     * generated from is present on disk.
     */
    public static function isDerivative(string $path, Config $config): bool
    {
        $filename = basename($path);
        $dir = dirname($path);

        $tokens = explode('.', $filename);
        if (count($tokens) < 2) {
            return false;
        }

        $ext = strtolower((string) array_pop($tokens));
        if (!self::isImageExtension($ext)) {
            return false;
        }

        // Inside a geometry directory the source lives one level up; otherwise
        // it would have to sit beside the file.
        $inGeometryDir = self::isGeometryDirName(basename($dir), $config);
        $sourceDir = $inGeometryDir ? dirname($dir) : $dir;

        foreach (self::sourceCandidates($tokens, $ext, $inGeometryDir) as $candidate) {
            $source = $sourceDir . '/' . $candidate;
            if ($source !== $path && is_file($source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a directory name denotes a geometry cache directory rather than
     * ordinary content.
     *
     * The ladder check is what keeps a product directory named `2x4` or `10x`
     * from being mistaken for one; per-file corroboration in isDerivative()
     * then covers the rest.
     */
    public static function isGeometryDirName(string $name, Config $config): bool
    {
        $geometry = Geometry::fromSegment($name);
        if ($geometry === null) {
            return false;
        }

        // With the ladder unenforced any integer geometry is legitimate, so the
        // membership test would reject real cache directories.
        if ($config->geometryPolicy === Config::GEOMETRY_OFF) {
            return true;
        }

        return $geometry->isOnLadder($config);
    }

    /**
     * Filenames this one could have been generated from, most specific first.
     *
     * @param list<string> $tokens Filename tokens with the target extension already removed.
     * @return list<string>
     */
    private static function sourceCandidates(array $tokens, string $ext, bool $inGeometryDir): array
    {
        $candidates = [];

        // photo.jpg.webp -> photo.jpg
        $previous = end($tokens);
        if ($previous !== false && count($tokens) >= 2 && self::isImageExtension(strtolower($previous))) {
            $withoutTarget = $tokens;
            $srcExt = (string) array_pop($withoutTarget);
            $candidates[] = implode('.', $withoutTarget) . '.' . $srcExt;

            // photo.dim-25.jpg.webp -> photo.jpg
            $stripped = self::stripFilter($withoutTarget);
            if ($stripped !== null) {
                $candidates[] = implode('.', $stripped) . '.' . $srcExt;
            }
        }

        // photo.dim-25.jpg -> photo.jpg
        $stripped = self::stripFilter($tokens);
        if ($stripped !== null) {
            $candidates[] = implode('.', $stripped) . '.' . $ext;
        }

        // 400x/photo.jpg -> ../photo.jpg  (a plain resize keeps the whole name)
        if ($inGeometryDir) {
            $candidates[] = implode('.', $tokens) . '.' . $ext;
        }

        return array_values(array_filter(
            array_unique($candidates),
            static fn (string $candidate): bool => $candidate !== '' && !str_starts_with($candidate, '.'),
        ));
    }

    /**
     * Drop a trailing registered filter token, or null when there is not one.
     *
     * @param list<string> $tokens
     * @return list<string>|null
     */
    private static function stripFilter(array $tokens): ?array
    {
        if (count($tokens) < 2) {
            return null;
        }

        if (Token::parse((string) end($tokens)) === null) {
            return null;
        }

        array_pop($tokens);

        return $tokens;
    }

    private static function isImageExtension(string $ext): bool
    {
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }
}
