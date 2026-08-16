<?php

declare(strict_types=1);

namespace Atispro\Img;

use Atispro\Img\Exception\ConfigException;

/**
 * Whole-pipeline configuration. Built once from a per-site array of overrides
 * merged over the defaults; never derived from request data.
 */
final class Config
{
    /** Off-ladder geometry is redirected (301) to the nearest canonical rung. */
    public const GEOMETRY_REDIRECT = 'redirect';

    /** Off-ladder geometry is refused outright. */
    public const GEOMETRY_STRICT = 'strict';

    /** Legacy: any integer geometry is honoured. Unbounded cache key space. */
    public const GEOMETRY_OFF = 'off';

    /**
     * @param string $imagesPath Root holding both originals and generated derivatives.
     * @param string $publicBase URL prefix mapping onto $imagesPath, without a trailing slash.
     * @param list<int> $widths Allowed width rungs, ascending.
     * @param list<int> $heights Allowed height rungs, ascending.
     * @param array<string,array<string,mixed>> $formats extension => encoder settings.
     * @param array{radius:float,sigma:float} $sharpen Applied after resize/crop.
     * @param array<string,string> $limits ImageMagick resource limits, name => value.
     * @param array<string,array{bin:string,args:list<string>,from:list<string>}> $externalEncoders
     *        Target extension => external encoder invoked instead of ImageMagick's own coder.
     */
    private function __construct(
        public readonly string $imagesPath,
        public readonly string $publicBase,
        public readonly int $dirPermission,
        public readonly int $widthMax,
        public readonly int $heightMax,
        public readonly array $widths,
        public readonly array $heights,
        public readonly string $geometryPolicy,
        public readonly int $maxPixels,
        public readonly array $formats,
        public readonly array $sharpen,
        public readonly array $limits,
        public readonly string $imagemagickPath,
        public readonly array $externalEncoders,
        public readonly string $processor,
        public readonly int $capabilitiesCacheTtl,
        public readonly int $negativeCacheTtl,
        public readonly string $iptcClassPath,
        public readonly bool $debug,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'imagesPath' => null,

            // URL prefix that maps onto imagesPath. Only used to build the
            // Location header when an off-ladder request is redirected to its
            // canonical form.
            'publicBase' => '/site/assets/files',

            'dirPermission' => 0o755,

            // Hard ceilings. Raised from the legacy 2400/1280, which sat below
            // the client's own ladder and silently truncated tall requests.
            'widthMax' => 3000,
            'heightMax' => 3000,

            // Allowed geometry rungs — the cache key space. One ladder for both
            // axes: a cover-fit request constrains width and height together, so
            // separate ladders meant a perfectly ordinary box like 1024x1024
            // was off-ladder on one axis and got redirected. Mirrors `sizes` in
            // @atispro/core adaptive-media.js; keep the two in step.
            // 450 is the 4:3 partner of the 600 rung: the client snaps an
            // aspect-derived axis too, so without it a declared 4:3 arrived as
            // a square.
            'widths' => [200, 300, 400, 450, 600, 800, 1000, 1200, 1600, 2000, 2400, 3000],
            'heights' => [200, 300, 400, 450, 600, 800, 1000, 1200, 1600, 2000, 2400, 3000],

            // What to do with a geometry that is not on the ladders. 'redirect'
            // bounds the cache without breaking clients that still emit
            // arbitrary sizes: they get a 301 to the canonical rung and refetch.
            // Switch a site to 'strict' once its client is known to snap.
            'geometryPolicy' => self::GEOMETRY_REDIRECT,

            // Decompression-bomb guard: getimagesize() only reads the header, so
            // the pixel count has to be checked before any decode is attempted.
            'maxPixels' => 50_000_000,

            'formats' => [
                'jpeg' => ['quality' => 70, 'interlace' => true],
                'jpg' => ['quality' => 70, 'interlace' => true],
                'png' => ['quality' => 90],
                'webp' => ['quality' => 70, 'method' => 4],
                'avif' => ['quality' => 55],
                'heic' => ['quality' => 55],
                'heif' => ['quality' => 55],
            ],

            // radius 0 lets ImageMagick pick; sigma controls strength.
            'sharpen' => ['radius' => 0, 'sigma' => 0.7],

            // Passed to `-limit` / Imagick::setResourceLimit. Bounds the damage a
            // single hostile request (huge blur radius, bomb source) can do.
            'limits' => [
                'area' => '256MP',
                'memory' => '512MiB',
                'map' => '1GiB',
                'time' => '30',
            ],

            'imagemagickPath' => str_starts_with(PHP_OS, 'WIN')
                ? 'D:/portable/imagemagick/'
                : '',

            // Optional per-format external encoders, e.g. cavif for PNG sources:
            //   'avif' => ['bin' => '/usr/local/bin/cavif',
            //              'args' => ['--quality', '55'],
            //              'from' => ['png']],
            // 'from' gates on the SOURCE extension. The legacy da.vinci
            // implementation gated on a config flag instead, so on every
            // non-Windows host it appended a cavif call to jpg->webp conversions
            // too, spawning a stray process and writing a spurious .avif sibling.
            'externalEncoders' => [],

            // 'auto' picks imagick -> cli -> null.
            'processor' => 'auto',

            // Seconds the on-disk capability probe is trusted; 0 disables caching.
            'capabilitiesCacheTtl' => 3600,

            // How long a probe that found NOTHING is trusted. Short, so a host
            // that gains a binary or an extension recovers quickly — but not
            // zero, because re-probing costs a process spawn per candidate.
            'negativeCacheTtl' => 60,

            // Reader for the IPTC focal point, require_once'd when a crop needs
            // it. Explicit configuration rather than a DOCUMENT_ROOT-derived
            // path, because this one gets included. Empty disables the lookup
            // and every crop is centred.
            'iptcClassPath' => ($_SERVER['DOCUMENT_ROOT'] ?? '') === ''
                ? ''
                : $_SERVER['DOCUMENT_ROOT'] . '/site/shared/classes/IPTC.class.php',

            // Enables ?debug=1 and ?debug=capabilities. Never leave on in production:
            // the capabilities payload reports disable_functions and absolute paths.
            'debug' => false,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    public static function fromArray(array $overrides): self
    {
        $c = self::merge(self::defaults(), $overrides);

        $imagesPath = self::normalizeDir(self::requireString($c, 'imagesPath'));
        if (!is_dir($imagesPath)) {
            throw new ConfigException("imagesPath is not a directory: {$imagesPath}");
        }

        $policy = (string) $c['geometryPolicy'];
        if (!in_array($policy, [self::GEOMETRY_REDIRECT, self::GEOMETRY_STRICT, self::GEOMETRY_OFF], true)) {
            throw new ConfigException("unknown geometryPolicy: {$policy}");
        }

        $processor = (string) $c['processor'];
        if (!in_array($processor, ['auto', 'imagick', 'cli'], true)) {
            throw new ConfigException("unknown processor: {$processor}");
        }

        $sharpen = (array) $c['sharpen'];

        return new self(
            imagesPath: $imagesPath,
            publicBase: rtrim((string) $c['publicBase'], '/'),
            dirPermission: (int) $c['dirPermission'],
            widthMax: (int) $c['widthMax'],
            heightMax: (int) $c['heightMax'],
            widths: self::normalizeLadder((array) $c['widths'], 'widths'),
            heights: self::normalizeLadder((array) $c['heights'], 'heights'),
            geometryPolicy: $policy,
            maxPixels: (int) $c['maxPixels'],
            formats: self::normalizeFormats((array) $c['formats']),
            sharpen: [
                'radius' => (float) ($sharpen['radius'] ?? 0),
                'sigma' => (float) ($sharpen['sigma'] ?? 0.7),
            ],
            limits: array_map('strval', (array) $c['limits']),
            imagemagickPath: rtrim((string) $c['imagemagickPath'], '/\\'),
            externalEncoders: self::normalizeExternalEncoders((array) $c['externalEncoders']),
            processor: $processor,
            capabilitiesCacheTtl: (int) $c['capabilitiesCacheTtl'],
            negativeCacheTtl: (int) $c['negativeCacheTtl'],
            iptcClassPath: (string) $c['iptcClassPath'],
            debug: (bool) $c['debug'],
        );
    }

    /**
     * Encoder settings for a target extension, or an empty array when the
     * extension is not one we are willing to write.
     *
     * @return array<string,mixed>
     */
    public function format(string $extension): array
    {
        return $this->formats[strtolower($extension)] ?? [];
    }

    /**
     * Output-format allowlist. Without this the requested extension flows
     * straight into the ImageMagick output coder, which happily writes .mvg,
     * .svg, .html or .txt.
     */
    public function allowsFormat(string $extension): bool
    {
        return isset($this->formats[strtolower($extension)]);
    }

    /**
     * External encoder for this target extension, given the source extension,
     * or null when ImageMagick's own coder should be used.
     *
     * @return array{bin:string,args:list<string>,from:list<string>}|null
     */
    public function externalEncoderFor(string $extension, string $srcExtension): ?array
    {
        $enc = $this->externalEncoders[strtolower($extension)] ?? null;
        if ($enc === null) {
            return null;
        }

        return in_array(strtolower($srcExtension), $enc['from'], true) ? $enc : null;
    }

    /**
     * Identity of every setting that can change output bytes. Folded into the
     * cache key by Pipeline, so a config edit invalidates stale derivatives
     * without anyone having to remember to clear them.
     */
    public function hash(): string
    {
        return substr(hash('xxh128', serialize([
            $this->widthMax,
            $this->heightMax,
            $this->formats,
            $this->sharpen,
            $this->externalEncoders,
        ])), 0, 16);
    }

    /**
     * Merge overrides over defaults, one level into the keyed sub-arrays.
     *
     * A shallow merge contradicts the "only what differs" contract the site
     * config documents: a site adding `formats.avif.quality` replaced the whole
     * formats table, dropping jpeg, png and webp from the output allowlist and
     * 404ing every non-AVIF request.
     *
     * Only the map-shaped keys are merged. Ladders are lists, and a site that
     * sets `widths` means to replace them, not to append to them.
     *
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function merge(array $defaults, array $overrides): array
    {
        $deep = ['formats', 'limits', 'sharpen', 'externalEncoders'];

        $merged = array_merge($defaults, $overrides);

        foreach ($deep as $key) {
            if (!isset($overrides[$key]) || !is_array($overrides[$key]) || !is_array($defaults[$key] ?? null)) {
                continue;
            }

            $merged[$key] = $overrides[$key];

            foreach ($defaults[$key] as $name => $value) {
                if (!array_key_exists($name, $overrides[$key])) {
                    $merged[$key][$name] = $value;
                    continue;
                }

                // One more level, so formats.webp.quality can be set without
                // discarding formats.webp.method.
                if (is_array($value) && is_array($overrides[$key][$name])) {
                    $merged[$key][$name] = array_merge($value, $overrides[$key][$name]);
                }
            }

            // A deep merge alone would make an entry impossible to remove, and
            // dropping a format from the output allowlist is a legitimate thing
            // for a site to want. Null says "take this one away".
            $merged[$key] = array_filter(
                $merged[$key],
                static fn (mixed $value): bool => $value !== null,
            );
        }

        return $merged;
    }

    /**
     * @param array<string,mixed> $c
     */
    private static function requireString(array $c, string $key): string
    {
        $value = $c[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ConfigException("config key '{$key}' is required and must be a non-empty string");
        }

        return $value;
    }

    private static function normalizeDir(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param array<mixed,mixed> $ladder
     * @return list<int>
     */
    private static function normalizeLadder(array $ladder, string $key): array
    {
        $out = array_values(array_unique(array_map('intval', $ladder)));
        sort($out);

        foreach ($out as $rung) {
            if ($rung <= 0) {
                throw new ConfigException("{$key} must contain positive integers only");
            }
        }

        if ($out === []) {
            throw new ConfigException("{$key} must not be empty");
        }

        return $out;
    }

    /**
     * @param array<mixed,mixed> $formats
     * @return array<string,array<string,mixed>>
     */
    private static function normalizeFormats(array $formats): array
    {
        $out = [];
        foreach ($formats as $ext => $settings) {
            $out[strtolower((string) $ext)] = (array) $settings;
        }

        return $out;
    }

    /**
     * @param array<mixed,mixed> $encoders
     * @return array<string,array{bin:string,args:list<string>,from:list<string>}>
     */
    private static function normalizeExternalEncoders(array $encoders): array
    {
        $out = [];
        foreach ($encoders as $ext => $spec) {
            $spec = (array) $spec;
            $bin = (string) ($spec['bin'] ?? '');
            if ($bin === '') {
                throw new ConfigException("externalEncoders['{$ext}'] needs a 'bin'");
            }
            $out[strtolower((string) $ext)] = [
                'bin' => $bin,
                'args' => array_values(array_map('strval', (array) ($spec['args'] ?? []))),
                'from' => array_values(array_map(
                    static fn (mixed $e): string => strtolower((string) $e),
                    (array) ($spec['from'] ?? []),
                )),
            ];
        }

        return $out;
    }
}
