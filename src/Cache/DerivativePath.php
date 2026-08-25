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
        $inGeometryDir = self::isGeometryDirName(basename($dir));
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
     * Syntax only, and deliberately: the ladder is a statement about what this
     * pipeline will generate *from now on*, not about what is on disk. Real
     * trees are full of `1024x768`, `640x480`, `300x225` and `2880x` from
     * before the ladder existed or from a policy that has since changed, and
     * gating on membership meant the cleaner disowned all of it — so it
     * accumulated permanently, which is the opposite of what a cache cleaner is
     * for.
     *
     * What keeps a product directory called `2x4` safe is therefore **not** the
     * ladder but the corroboration in {@see isDerivative()}: a file here is
     * removed only when the source it would have been generated from is on
     * disk one level up. `2x4/diagram.png` with no `diagram.png` above it is
     * untouched, and the whole-directory deletion that destroyed such a gallery
     * is not something this code can express at all.
     *
     * The residual case is narrow and worth stating: a content directory whose
     * name parses as a geometry, holding an image whose name also exists in the
     * directory above it, is indistinguishable from a cache directory. Do not
     * use `WxH` as a content directory name inside the images tree.
     *
     * Takes no Config on purpose — the answer does not depend on one, and
     * callers that have no pipeline config (an audit pass, a report) need the
     * same definition rather than a private copy of the regex.
     */
    public static function isGeometryDirName(string $name): bool
    {
        return Geometry::fromSegment($name) !== null;
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

    /**
     * Public because the extension list is not private knowledge: every
     * consumer that reasons about these filenames needs the same list, and the
     * three copies that existed before this was exposed had already drifted.
     */
    public static function isImageExtension(string $ext): bool
    {
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * @return list<string>
     */
    public static function imageExtensions(): array
    {
        return self::IMAGE_EXTENSIONS;
    }
}
