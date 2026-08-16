<?php

/**
 * Populates demo/files with source images.
 *
 * The two photographs in samples/ are copied in as-is. Everything else is a
 * calibration image: a grid, a centre crosshair and four distinctly coloured
 * corner blocks, so that a crop is not just plausible-looking but readable —
 * you can see which corner survived and by how much.
 */

declare(strict_types=1);

/**
 * @return array<string,array{0:int,1:int,2:string}> filename => [width, height, description]
 */
function demo_sources(string $filesDir, string $samplesDir): array
{
    // Without this the page dies on "Call to undefined function
    // imagecreatetruecolor()" — an uncatchable Error with nothing to say about
    // what is actually missing.
    if (!extension_loaded('gd')) {
        throw new RuntimeException(
            'The demo generates its calibration images with GD, which is not loaded. '
            . 'Enable ext-gd, or drop your own images into demo/files/.',
        );
    }

    if (!is_dir($filesDir)) {
        mkdir($filesDir, 0o777, true);
    }

    foreach (['mars.jpg', 'parrot.jpeg'] as $photo) {
        $from = $samplesDir . '/' . $photo;
        $to = $filesDir . '/' . $photo;
        if (is_file($from) && !is_file($to)) {
            copy($from, $to);
        }
    }

    $generated = [
        'pano.jpg' => [2400, 400],
        'tower.jpg' => [400, 1600],
        'tiny.png' => [120, 90],
        'square.jpg' => [1000, 1000],
    ];

    foreach ($generated as $name => [$w, $h]) {
        $path = $filesDir . '/' . $name;
        if (!is_file($path)) {
            demo_calibration($path, $w, $h);
        }
    }

    if (!is_file($filesDir . '/logo.png')) {
        demo_alphaLogo($filesDir . '/logo.png', 512, 512);
    }

    $sources = [];
    foreach (['mars.jpg', 'parrot.jpeg', 'pano.jpg', 'tower.jpg', 'square.jpg', 'tiny.png', 'logo.png'] as $name) {
        $path = $filesDir . '/' . $name;
        if (!is_file($path)) {
            continue;
        }

        $info = getimagesize($path);
        if ($info === false) {
            continue;
        }

        $sources[$name] = [$info[0], $info[1], demo_describe($name, $info[0], $info[1])];
    }

    return $sources;
}

function demo_describe(string $name, int $w, int $h): string
{
    $aspect = $w / $h;
    $shape = match (true) {
        $aspect >= 3 => 'extreme landscape',
        $aspect > 1.05 => 'landscape',
        $aspect > 0.95 => 'square',
        $aspect > 0.34 => 'portrait',
        default => 'extreme portrait',
    };

    $extra = match ($name) {
        'mars.jpg' => 'photograph, large',
        'parrot.jpeg' => 'photograph, small — most boxes exceed it',
        'logo.png' => 'transparent background',
        'tiny.png' => 'smaller than every ladder rung',
        default => 'calibration grid',
    };

    return sprintf('%s %.3f:1 — %s', $shape, $aspect, $extra);
}

/** A grid with a centre crosshair and four coloured corners. */
function demo_calibration(string $path, int $width, int $height): void
{
    $im = imagecreatetruecolor($width, $height);

    // Background: a diagonal gradient, so any resample is visible as banding.
    for ($x = 0; $x < $width; $x++) {
        $shade = (int) (40 + 120 * $x / max(1, $width - 1));
        imagefilledrectangle($im, $x, 0, $x, $height - 1, (int) imagecolorallocate($im, $shade, 60, 150 - (int) ($shade / 2)));
    }

    $grid = (int) imagecolorallocatealpha($im, 255, 255, 255, 90);
    for ($x = 0; $x < $width; $x += 100) {
        imageline($im, $x, 0, $x, $height - 1, $grid);
    }
    for ($y = 0; $y < $height; $y += 100) {
        imageline($im, 0, $y, $width - 1, $y, $grid);
    }

    // Corner blocks — which ones survive tells you exactly how a crop landed.
    $block = max(24, (int) (min($width, $height) / 8));
    $corners = [
        [0, 0, imagecolorallocate($im, 235, 64, 52)],                                    // TL red
        [$width - $block, 0, imagecolorallocate($im, 250, 200, 40)],                      // TR yellow
        [0, $height - $block, imagecolorallocate($im, 60, 200, 90)],                       // BL green
        [$width - $block, $height - $block, imagecolorallocate($im, 240, 240, 250)],       // BR white
    ];
    foreach ($corners as [$x, $y, $colour]) {
        imagefilledrectangle($im, (int) $x, (int) $y, (int) $x + $block, (int) $y + $block, (int) $colour);
    }

    // Centre crosshair.
    $centre = (int) imagecolorallocate($im, 255, 0, 128);
    $cx = intdiv($width, 2);
    $cy = intdiv($height, 2);
    $arm = max(12, (int) (min($width, $height) / 6));
    imagefilledrectangle($im, $cx - 2, $cy - $arm, $cx + 2, $cy + $arm, $centre);
    imagefilledrectangle($im, $cx - $arm, $cy - 2, $cx + $arm, $cy + 2, $centre);

    demo_label($im, sprintf('%d x %d', $width, $height), $width, $height);

    str_ends_with($path, '.png') ? imagepng($im, $path) : imagejpeg($im, $path, 90);
}

/** A shape with real transparency, for the alpha-preservation cases. */
function demo_alphaLogo(string $path, int $width, int $height): void
{
    $im = imagecreatetruecolor($width, $height);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));

    imagealphablending($im, true);

    $cx = intdiv($width, 2);
    $cy = intdiv($height, 2);
    imagefilledellipse($im, $cx, $cy, (int) ($width * 0.8), (int) ($height * 0.8), (int) imagecolorallocate($im, 220, 50, 60));
    imagefilledellipse($im, $cx, $cy, (int) ($width * 0.45), (int) ($height * 0.45), (int) imagecolorallocatealpha($im, 0, 0, 0, 127));

    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagepng($im, $path);
}

function demo_label(\GdImage $im, string $text, int $width, int $height): void
{
    $scale = max(2, (int) (min($width, $height) / 90));
    $font = 5;
    $w = imagefontwidth($font) * strlen($text);
    $h = imagefontheight($font);

    $strip = imagecreatetruecolor($w, $h);
    imagefilledrectangle($strip, 0, 0, $w, $h, (int) imagecolorallocate($strip, 0, 0, 0));
    imagestring($strip, $font, 0, 0, $text, (int) imagecolorallocate($strip, 255, 255, 255));

    imagecopyresized($im, $strip, 12, 12, 0, 0, $w * $scale, $h * $scale, $w, $h);
}
