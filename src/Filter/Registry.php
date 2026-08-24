<?php

declare(strict_types=1);

namespace Atispro\Img\Filter;

/**
 * The filter registry: name => parameter spec + the ops it expands to.
 *
 * Each op carries BOTH an `imagick` method call and a `cli` argv fragment, so
 * the two backends stay in step from one definition. Historically they did not:
 *
 *   sepia          `-sepia-tone 80%` is a percentage, while
 *                  sepiaToneImage(80.0) is a QUANTUM value — 80 out of 65535 on
 *                  a Q16 build, i.e. ~0.12%, effectively a no-op. The same URL
 *                  rendered completely differently depending on which backend
 *                  happened to generate it, and the result was then cached.
 *   darken/lighten a negative parameter built the literal argv `--10x0` /
 *                  `+-10x0`, which ImageMagick rejects — so `darken-10` was a
 *                  404 on CLI and a valid lighten on imagick.
 *   dim            colorizeImage()'s opacity argument changed meaning between
 *                  imagick 3.4.x and 3.5+/IM7.
 *
 * All three are fixed below. Parameters are also clamped and quantised, because
 * they are part of the cache key: free-form floats meant blur0,2 / blur0,2.1 /
 * blur0,2.11 were unlimited distinct derivatives of the same image.
 */
final class Registry
{
    /** @var array<string,array{params:list<array{default:float,min:float,max:float,step:float}>,ops:callable}>|null */
    private static ?array $definitions = null;

    private static ?float $quantum = null;

    public static function exists(string $name): bool
    {
        return isset(self::definitions()[strtolower($name)]);
    }

    /**
     * Parameter spec for a filter, or an empty list when it takes none.
     *
     * @return list<array{default:float,min:float,max:float,step:float}>
     */
    public static function spec(string $name): array
    {
        return self::definitions()[strtolower($name)]['params'] ?? [];
    }

    /**
     * Expand a filter into its ops.
     *
     * @param list<float> $params Already normalised by Token::normalizeParams().
     * @return list<array{imagick:array{0:string,1:list<mixed>},cli:list<string>}>
     */
    public static function pipeline(string $name, array $params): array
    {
        $definition = self::definitions()[strtolower($name)] ?? null;
        if ($definition === null) {
            return [];
        }

        /** @var list<array{imagick:array{0:string,1:list<mixed>},cli:list<string>}> $ops */
        $ops = ($definition['ops'])($params);

        return $ops;
    }

    /**
     * @return array<string,array{params:list<array{default:float,min:float,max:float,step:float}>,ops:callable}>
     */
    private static function definitions(): array
    {
        return self::$definitions ??= self::build();
    }

    /**
     * @return array<string,array{params:list<array{default:float,min:float,max:float,step:float}>,ops:callable}>
     */
    private static function build(): array
    {
        $percent = static fn (float $default): array => [
            'default' => $default, 'min' => 0.0, 'max' => 200.0, 'step' => 1.0,
        ];
        // Multi-parameter filters get a coarser step, because their parameter
        // spaces multiply: at step 1 modulate admitted 201^3 ≈ 8.1M canonical
        // tokens per image, every one a distinct derivative the pipeline would
        // happily generate. Step 10 keeps 21^3 ≈ 9k — still far more looks than
        // any design uses — and an off-step value 301-snaps to the nearest one
        // through the same canonicalisation that handles off-ladder geometry.
        $percentCoarse = static fn (float $default): array => [
            'default' => $default, 'min' => 0.0, 'max' => 200.0, 'step' => 10.0,
        ];
        $amount = static fn (float $default): array => [
            'default' => $default, 'min' => -100.0, 'max' => 100.0, 'step' => 1.0,
        ];
        // Every step is integral on purpose. A fractional step would need a
        // decimal in the canonical token, and '.' separates filename segments —
        // `photo.blur0,4.5.jpg` splits into four parts and the filter is lost.
        $radius = static fn (float $default): array => [
            'default' => $default, 'min' => 0.0, 'max' => 20.0, 'step' => 1.0,
        ];

        return [
            // brightness, saturation, hue — all 100 = unchanged
            'modulate' => [
                'params' => [$percentCoarse(100.0), $percentCoarse(100.0), $percentCoarse(100.0)],
                'ops' => static fn (array $p): array => [[
                    'imagick' => ['modulateImage', [$p[0], $p[1], $p[2]]],
                    'cli' => ['-modulate', self::num($p[0]) . ',' . self::num($p[1]) . ',' . self::num($p[2])],
                ]],
            ],

            // darken — 0 = original, 100 = black. A negative amount lightens.
            'darken' => [
                'params' => [$amount(25.0)],
                'ops' => static fn (array $p): array => self::brightness(-$p[0]),
            ],

            // lighten — 0 = original, 100 = white. A negative amount darkens.
            'lighten' => [
                'params' => [$amount(25.0)],
                'ops' => static fn (array $p): array => self::brightness($p[0]),
            ],

            // dim — blend toward white (positive) or black (negative)
            'dim' => [
                'params' => [$amount(0.0)],
                'ops' => static function (array $p): array {
                    $color = $p[0] >= 0 ? 'white' : 'black';
                    $pct = abs($p[0]);

                    return [[
                        // The third argument pins the modern (non-legacy) opacity
                        // interpretation, which is what -colorize does.
                        'imagick' => ['colorizeImage', [$color, $pct / 100, false]],
                        'cli' => ['-fill', $color, '-colorize', self::num($pct)],
                    ]];
                },
            ],

            // brightness only — percent, 100 = unchanged
            'brighten' => [
                'params' => [$percent(110.0)],
                'ops' => static fn (array $p): array => [[
                    'imagick' => ['modulateImage', [$p[0], 100.0, 100.0]],
                    'cli' => ['-modulate', self::num($p[0]) . ',100,100'],
                ]],
            ],

            // desaturate — residual saturation %, 0 = full grayscale
            'grayscale' => [
                'params' => [$percent(0.0)],
                'ops' => static fn (array $p): array => [[
                    'imagick' => ['modulateImage', [100.0, $p[0], 100.0]],
                    'cli' => ['-modulate', '100,' . self::num($p[0]) . ',100'],
                ]],
            ],

            // sepia — threshold percent
            'sepia' => [
                'params' => [$percent(80.0)],
                'ops' => static fn (array $p): array => self::sepia($p[0]),
            ],

            // gaussian blur — radius, sigma
            'blur' => [
                'params' => [$radius(0.0), $radius(2.0)],
                'ops' => static fn (array $p): array => [[
                    'imagick' => ['blurImage', [$p[0], $p[1]]],
                    'cli' => ['-blur', self::num($p[0]) . 'x' . self::num($p[1])],
                ]],
            ],

            // edge-aware blur — same parameters
            'softblur' => [
                'params' => [$radius(0.0), $radius(2.0)],
                'ops' => static fn (array $p): array => [[
                    'imagick' => ['adaptiveBlurImage', [$p[0], $p[1]]],
                    'cli' => ['-adaptive-blur', self::num($p[0]) . 'x' . self::num($p[1])],
                ]],
            ],

            // multi-step: warm vintage look
            'vintage' => [
                'params' => [],
                'ops' => static fn (array $p): array => [
                    [
                        'imagick' => ['modulateImage', [100.0, 60.0, 100.0]],
                        'cli' => ['-modulate', '100,60,100'],
                    ],
                    ...self::sepia(70.0),
                    [
                        'imagick' => ['contrastImage', [true]],
                        'cli' => ['-contrast'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Brightness shift shared by darken/lighten. `%+g` keeps the sign correct in
     * both directions, which is what the hand-built '-' . $p . 'x0' string got
     * wrong for negative input.
     *
     * @return list<array{imagick:array{0:string,1:list<mixed>},cli:list<string>}>
     */
    private static function brightness(float $amount): array
    {
        return [[
            'imagick' => ['brightnessContrastImage', [$amount, 0.0]],
            'cli' => ['-brightness-contrast', sprintf('%+g', $amount) . 'x0'],
        ]];
    }

    /**
     * Sepia, with the percentage converted to quantum units for imagick so both
     * backends produce the same image.
     *
     * @return list<array{imagick:array{0:string,1:list<mixed>},cli:list<string>}>
     */
    private static function sepia(float $percent): array
    {
        return [[
            'imagick' => ['sepiaToneImage', [$percent / 100 * self::quantum()]],
            'cli' => ['-sepia-tone', self::num($percent) . '%'],
        ]];
    }

    /**
     * Quantum range of the local ImageMagick build. Q16 (65535) is both the
     * common case and a safe fallback when the extension is absent — the imagick
     * ops are only ever executed on a host that has it.
     */
    private static function quantum(): float
    {
        if (self::$quantum !== null) {
            return self::$quantum;
        }

        $quantum = 65535.0;
        if (class_exists('Imagick')) {
            $range = \Imagick::getQuantumRange();
            if ($range['quantumRangeLong'] > 0) {
                $quantum = (float) $range['quantumRangeLong'];
            }
        }

        return self::$quantum = $quantum;
    }

    /** Format a float for an ImageMagick argument without a trailing `.0`. */
    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
