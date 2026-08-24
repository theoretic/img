<?php

declare(strict_types=1);

namespace Atispro\Img\Request;

use Atispro\Img\Config;
use Atispro\Img\Exception\NotFoundException;
use Atispro\Img\Filter\Token;

/**
 * The URL grammar, and the only place a request string is allowed to become a
 * filesystem path.
 *
 *   /files/1044/photo.jpg                 serve as-is
 *   /files/1044/photo.jpg.webp            convert jpg -> webp
 *   /files/1044/400x800/photo.jpg         resize to fill, focal-point crop
 *   /files/1044/400x/photo.jpg            width 400, source aspect kept
 *   /files/1044/x800/photo.jpg            height 800
 *   /files/1044/400x800f/photo.jpg        cover the 400x800 box, no crop
 *   /files/1044/400x/photo.dim-25.jpg.avif   filter + convert
 *
 * Parsing is split in two so the cheap half can run first:
 *
 *   canonicalPath()  purely syntactic. Decides whether the request is already
 *                    in canonical form, without touching the disk.
 *   parse()          resolves the source, reads its dimensions, and works out
 *                    the target geometry. Only ever called on a canonical path.
 *
 * That split is what makes the cache bounded: an off-ladder geometry or a
 * non-canonical filter token is answered with a 301 to the canonical URL rather
 * than generating a near-duplicate derivative under a second name.
 */
final class UrlGrammar
{
    /**
     * Extensions recognised as an image, used to tell `photo.jpg.webp`
     * (convert) from `my.photo.jpg` (a filename that simply contains a dot).
     */
    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'tif', 'tiff', 'bmp',
    ];

    /**
     * Canonical form of this request path, or null when it is not an image
     * request at all.
     *
     * @throws NotFoundException when the path fails containment, or when the
     *         geometry is off-ladder under the strict policy.
     */
    public static function canonicalPath(string $request, Config $config): ?string
    {
        $parts = self::inspect($request, $config);

        return $parts === null ? null : self::canonicalFrom($parts, $config);
    }

    /**
     * Validate and tokenise once.
     *
     * Both halves of the public API need the same tokens, and calling them in
     * sequence ran assertSafe(), normalize() and split() two or three times per
     * request. Pipeline uses this directly.
     *
     * Takes the Config because the filter allowlist decides whether a trailing
     * token is a filter or just part of the filename.
     *
     * @return array{dir:string,geometry:Geometry|null,name:string,filter:Token|null,srcExt:string,ext:string}|null
     * @throws NotFoundException when the path fails containment
     */
    public static function inspect(string $request, Config $config): ?array
    {
        self::assertSafe($request);

        return self::split($request, $config);
    }

    /**
     * Canonical form of an already-tokenised request.
     *
     * @param array{dir:string,geometry:Geometry|null,name:string,filter:Token|null,srcExt:string,ext:string} $parts
     * @throws NotFoundException when the geometry is off-ladder under the strict policy
     */
    public static function canonicalFrom(array $parts, Config $config): string
    {
        $geometry = $parts['geometry'];
        if ($geometry !== null && !$geometry->isOnLadder($config)) {
            if ($config->geometryPolicy === Config::GEOMETRY_STRICT) {
                throw new NotFoundException("geometry not on the configured ladder: {$geometry->segment()}");
            }
            if ($config->geometryPolicy === Config::GEOMETRY_REDIRECT) {
                $geometry = $geometry->snapToLadder($config);
            }
        }

        $dir = $parts['dir'];
        if ($geometry !== null) {
            $parent = self::parentDir($dir);
            $dir = $parent === '' ? $geometry->segment() : $parent . '/' . $geometry->segment();
        }

        $filename = self::canonicalFilename($parts);

        return $dir === '' ? $filename : $dir . '/' . $filename;
    }

    /**
     * Canonical filename: name, canonical filter token, source extension, and
     * the target extension only when it differs.
     *
     * @param array{dir:string,geometry:Geometry|null,name:string,filter:Token|null,srcExt:string,ext:string} $parts
     */
    private static function canonicalFilename(array $parts): string
    {
        return $parts['name']
            . ($parts['filter'] !== null ? '.' . $parts['filter']->token() : '')
            . '.' . $parts['srcExt']
            . ($parts['ext'] !== strtolower($parts['srcExt']) ? '.' . $parts['ext'] : '');
    }

    /**
     * Resolve a canonical request into an ImageRequest.
     *
     * @throws NotFoundException when the source is missing, unreadable, too
     *         large to decode, or the target format is not one we write.
     */
    public static function parse(string $request, Config $config): ImageRequest
    {
        $parts = self::inspect($request, $config);
        if ($parts === null) {
            throw new NotFoundException('not an image request');
        }

        return self::resolve($parts, $request, $config);
    }

    /**
     * Resolve already-tokenised parts against the filesystem.
     *
     * @param array{dir:string,geometry:Geometry|null,name:string,filter:Token|null,srcExt:string,ext:string} $parts
     * @throws NotFoundException
     */
    public static function resolve(array $parts, string $request, Config $config): ImageRequest
    {
        $file = $config->imagesPath . '/' . self::normalize($request);
        self::assertContained($file, $config);

        // The allowlist governs what the pipeline WRITES. A plain request for an
        // existing upload writes nothing, so it is exempt: without this, a
        // GIF/TIFF/BMP original — recognised by IMAGE_EXTENSIONS but absent from
        // the encoder table — could never even be served. Transforming one still
        // requires its target format to be writable.
        $writesNothing = $parts['geometry'] === null
            && $parts['filter'] === null
            && $parts['ext'] === strtolower($parts['srcExt']);

        if (!$writesNothing && !$config->allowsFormat($parts['ext'])) {
            throw new NotFoundException("output format not allowed: {$parts['ext']}");
        }

        $dirAbs = $config->imagesPath . ($parts['dir'] === '' ? '' : '/' . $parts['dir']);
        $stem = $parts['name'] . '.' . $parts['srcExt'];

        // Look in the parent directory FIRST when a geometry directory is
        // present. Searching the geometry directory first let an already
        // generated derivative become the source of the next one, compounding
        // quality loss on output that had already been stripped and filtered.
        $candidates = $parts['geometry'] !== null
            ? [self::parentDir($dirAbs) . '/' . $stem, $dirAbs . '/' . $stem]
            : [$dirAbs . '/' . $stem];

        $srcFile = null;
        $srcDir = $dirAbs;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $srcFile = $candidate;
                $srcDir = dirname($candidate);
                break;
            }
        }

        if ($srcFile === null) {
            throw new NotFoundException("source not found: {$stem}");
        }

        // The directory check above stops at the nearest existing ancestor, so
        // a symlink at the final component still points wherever it likes; any
        // file with a valid image header would then be served verbatim by the
        // passthrough branch.
        self::assertResolvedInside($srcFile, $config);

        // ImageMagick reads a trailing `[n]` on a filename as a frame or scene
        // selector, and a leading `-` or `@` as an option or a list file. A real
        // upload called `photo[0].jpg` would therefore make the CLI backend open
        // `photo.jpg` instead and serve one image's content at another's URL.
        $sourceName = basename($srcFile);
        if (preg_match('/[\[\]]/', $sourceName) === 1 || preg_match('/^[-@]/', $sourceName) === 1) {
            throw new NotFoundException("source name is not safe to pass to the encoder: {$sourceName}");
        }

        $info = @getimagesize($srcFile);
        if ($info === false) {
            throw new NotFoundException("unreadable image: {$srcFile}");
        }

        [$srcWidth, $srcHeight] = $info;
        if ($srcWidth < 1 || $srcHeight < 1) {
            throw new NotFoundException("degenerate image dimensions: {$srcFile}");
        }
        if ($srcWidth * $srcHeight > $config->maxPixels) {
            throw new NotFoundException("source exceeds maxPixels: {$srcFile}");
        }

        $targetWidth = 0;
        $targetHeight = 0;
        if ($parts['geometry'] !== null) {
            [$targetWidth, $targetHeight] = $parts['geometry']->resolve($srcWidth, $srcHeight, $config);
        }

        return new ImageRequest(
            srcFile: $srcFile,
            dstFile: self::destination($file, $srcDir, $parts, $targetWidth, $targetHeight, $srcWidth, $srcHeight),
            srcWidth: $srcWidth,
            srcHeight: $srcHeight,
            targetWidth: $targetWidth,
            targetHeight: $targetHeight,
            extension: $parts['ext'],
            srcExtension: strtolower($parts['srcExt']),
            filter: $parts['filter'],
        );
    }

    /**
     * Where the derivative is written. When the requested box already covers the
     * whole source and no crop is involved, the per-geometry subdirectory is
     * dropped and the variant is stored beside the original — so every oversized
     * geometry collapses onto one file instead of one per rung.
     *
     * @param array{dir:string,geometry:Geometry|null,name:string,filter:Token|null,srcExt:string,ext:string} $parts
     */
    private static function destination(
        string $file,
        string $srcDir,
        array $parts,
        int $targetWidth,
        int $targetHeight,
        int $srcWidth,
        int $srcHeight,
    ): string {
        if ($parts['geometry'] === null) {
            return $file;
        }

        $coversWidth = $targetWidth === 0 || $targetWidth >= $srcWidth;
        $coversHeight = $targetHeight === 0 || $targetHeight >= $srcHeight;
        if (!$coversWidth || !$coversHeight) {
            return $file;
        }

        // Both axes cover the source, so the only remaining work is a format
        // change or a filter — neither of which depends on the geometry.
        return $srcDir . '/' . basename($file);
    }

    /**
     * The single-axis canonical path a fit (`f`) request collapses onto once
     * the source is known, or null when the request is not an f geometry.
     *
     * An f request stores nothing under its own name. resolve() zeroes the
     * non-binding axis, so the derivative is exactly what the plain single-axis
     * request of the surviving rung produces — the free axis contributes
     * nothing but a second cache key over identical bytes (800x450f and
     * 600x450f of a landscape source are the same x450 file). canonicalFrom()
     * cannot collapse it: which axis binds depends on the source aspect, which
     * is only known here, after getimagesize(). Pipeline answers with a
     * redirect, so `f` stays a request form without ever becoming a storage
     * form.
     *
     * @param array{dir:string,geometry:Geometry|null,name:string,filter:Token|null,srcExt:string,ext:string} $parts
     */
    public static function coverCanonical(array $parts, ImageRequest $image): ?string
    {
        $geometry = $parts['geometry'];
        if ($geometry === null || $geometry->fit !== Geometry::FIT_COVER) {
            return null;
        }

        // resolve() kept the binding axis and zeroed the other; the redirect
        // carries the REQUESTED rung of the surviving axis, not the clamped
        // value — a clamped value can be off-ladder, and the single-axis
        // request re-applies the same clamp anyway.
        $bound = $image->targetHeight === 0
            ? new Geometry($geometry->width, 0)
            : new Geometry(0, $geometry->height);

        $parent = self::parentDir($parts['dir']);
        $dir = $parent === '' ? $bound->segment() : $parent . '/' . $bound->segment();

        return $dir . '/' . self::canonicalFilename($parts);
    }

    /**
     * Syntactic split of a request path.
     *
     * @return array{dir:string,geometry:Geometry|null,name:string,filter:Token|null,srcExt:string,ext:string}|null
     */
    private static function split(string $request, Config $config): ?array
    {
        $relative = self::normalize($request);
        if ($relative === '') {
            return null;
        }

        $slash = strrpos($relative, '/');
        $dir = $slash === false ? '' : substr($relative, 0, $slash);
        $filename = $slash === false ? $relative : substr($relative, $slash + 1);

        $tokens = explode('.', $filename);
        if (count($tokens) < 2) {
            return null;
        }

        $extRaw = (string) array_pop($tokens);
        $ext = strtolower($extRaw);
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return null;
        }

        // Double extension (photo.jpg.webp) only when the token before the
        // target extension is itself an image extension. Without that check
        // `my.photo.jpg` resolved its source extension to "photo" and 404'd.
        //
        // The source extension keeps its original case: it is part of a real
        // filename on disk, and lower-casing it made `photo.JPG` both
        // unresolvable on a case-sensitive filesystem and the target of a
        // permanent redirect to a URL that could never resolve either.
        $srcExt = $extRaw;
        $previous = end($tokens);
        if ($previous !== false && count($tokens) >= 2 && in_array(strtolower($previous), self::IMAGE_EXTENSIONS, true)) {
            $srcExt = (string) array_pop($tokens);
        }

        // What remains is the name, optionally with a filter token on the end.
        // Only consider one when something would be left over as the name.
        // A name outside the site's allowlist is treated exactly like one that
        // was never registered: it stays part of the filename and the file is
        // looked up as written.
        $filter = null;
        if (count($tokens) >= 2) {
            $candidate = (string) end($tokens);
            $filter = Token::parse($candidate);
            if ($filter !== null && !$config->allowsFilter($filter->name)) {
                $filter = null;
            }
            if ($filter !== null) {
                array_pop($tokens);
            }
        }

        $name = implode('.', $tokens);
        if ($name === '') {
            return null;
        }

        $lastDir = $dir === '' ? '' : basename($dir);

        return [
            'dir' => $dir,
            'geometry' => $lastDir === '' ? null : Geometry::fromSegment($lastDir),
            'name' => $name,
            'filter' => $filter,
            'srcExt' => $srcExt,
            'ext' => $ext,
        ];
    }

    /** Normalised, leading-slash-free request path. */
    public static function normalize(string $request): string
    {
        $relative = str_replace('\\', '/', $request);
        $relative = (string) preg_replace('#/+#', '/', $relative);
        $relative = ltrim($relative, '/');

        // Current-directory segments resolve to the same inode under a
        // different URL string, which the "URL is the cache key" contract
        // forbids. Apache strips them before mod_rewrite ever runs, but the
        // demo router and direct Pipeline callers do not.
        if (str_contains($relative, './')) {
            $relative = implode('/', array_filter(
                explode('/', $relative),
                static fn (string $segment): bool => $segment !== '.',
            ));
        }

        return $relative;
    }

    private static function parentDir(string $dir): string
    {
        $slash = strrpos($dir, '/');

        return $slash === false ? '' : substr($dir, 0, $slash);
    }

    /**
     * Reject traversal before the path is ever built.
     *
     * The request arrives already url-decoded, so both forms are checked: the
     * raw string catches `../`, and the decoded one catches `%2e%2e`. The check
     * is per segment rather than a substring search, so a legitimate filename
     * like `foo..bar.jpg` still resolves.
     *
     * @throws NotFoundException
     */
    private static function assertSafe(string $request): void
    {
        foreach ([$request, rawurldecode($request)] as $form) {
            // Checked on the decoded form too — a literal %00 in the path is
            // a null byte after decoding, same as %2e%2e is a dot segment.
            if (str_contains($form, "\0")) {
                throw new NotFoundException('null byte in request');
            }

            foreach (preg_split('#[/\\\\]#', $form) ?: [] as $segment) {
                if ($segment === '..') {
                    throw new NotFoundException('parent-directory segment in request');
                }
            }
        }
    }

    /**
     * An existing file must resolve inside imagesPath once symlinks are
     * followed. Complements assertContained(), which can only vouch for the
     * directory a not-yet-created file will live in.
     *
     * @throws NotFoundException
     */
    private static function assertResolvedInside(string $file, Config $config): void
    {
        $root = realpath($config->imagesPath);
        $real = realpath($file);

        if ($root === false || $real === false) {
            throw new NotFoundException('path does not resolve');
        }

        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new NotFoundException('resolved path escapes imagesPath');
        }
    }

    /**
     * The resolved path must live inside imagesPath. The file itself need not
     * exist yet, so the check walks up to the nearest existing ancestor and
     * compares that.
     *
     * @throws NotFoundException
     */
    private static function assertContained(string $file, Config $config): void
    {
        $root = realpath($config->imagesPath);
        if ($root === false) {
            throw new NotFoundException('imagesPath does not resolve');
        }

        $probe = dirname($file);
        while (!is_dir($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new NotFoundException('no existing ancestor directory');
            }
            $probe = $parent;
        }

        $real = realpath($probe);
        if ($real === false) {
            throw new NotFoundException('path does not resolve');
        }

        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new NotFoundException('path escapes imagesPath');
        }
    }
}
