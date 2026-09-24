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

    if (!is_file($filesDir . '/chroma.png')) {
        demo_chroma($filesDir . '/chroma.png', 1600, 1000, false);
    }
    if (!is_file($filesDir . '/opaque.png')) {
        demo_chroma($filesDir . '/opaque.png', 1600, 1000, true);
    }

    $sources = [];
    foreach (['mars.jpg', 'parrot.jpeg', 'pano.jpg', 'tower.jpg', 'square.jpg', 'tiny.png', 'logo.png', 'chroma.png', 'opaque.png'] as $name) {
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
        'chroma.png' => 'red text and hairlines on white — 4:2:0 bleeds them',
        'opaque.png' => 'the same, RGBA with every pixel opaque — a ProcessWire variation',
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

/**
 * Saturated detail on white: red and blue text and one-pixel lines — the
 * content of a site screenshot, and exactly what chroma subsampling smears.
 * With $rgba the file is written with an alpha channel that is fully opaque,
 * the way ProcessWire writes every PNG variation.
 */
function demo_chroma(string $path, int $width, int $height, bool $rgba): void
{
    $im = imagecreatetruecolor($width, $height);
    imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($im, 255, 255, 255));

    $red = (int) imagecolorallocate($im, 220, 20, 40);
    $blue = (int) imagecolorallocate($im, 30, 60, 230);
    $grey = (int) imagecolorallocate($im, 90, 90, 90);

    // A header strip of menu-like red words, as on the screenshot this came from.
    $words = ['Portfolio', 'Services', 'About', 'Contact'];
    foreach ($words as $i => $word) {
        demo_text($im, $word, 40 + $i * 360, 200, 4, $red);
    }
    imagefilledrectangle($im, 0, 290, $width - 1, 297, $grey);

    // Hairlines, alternating colour, at a pitch the downscale has to resolve.
    for ($x = 60; $x < $width - 60; $x += 24) {
        imageline($im, $x, 330, $x, 660, $x % 48 === 12 ? $red : $blue);
    }

    // Body text in both colours, two sizes.
    for ($row = 0; $row < 4; $row++) {
        demo_text($im, 'Hard colour edges on white', 60, 700 + $row * 70, 3, $row % 2 ? $blue : $red);
    }

    demo_label($im, sprintf('%d x %d %s', $width, $height, $rgba ? 'RGBA' : 'RGB'), $width, $height);

    if ($rgba) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
    }
    imagepng($im, $path);
}

/** GD's bitmap font, scaled up with nearest-neighbour so its edges stay hard. */
function demo_text(\GdImage $im, string $text, int $x, int $y, int $scale, int $colour): void
{
    $font = 5;
    $w = imagefontwidth($font) * strlen($text);
    $h = imagefontheight($font);

    $strip = imagecreatetruecolor($w, $h);
    $white = (int) imagecolorallocate($strip, 255, 255, 255);
    imagefilledrectangle($strip, 0, 0, $w, $h, $white);
    imagecolortransparent($strip, $white);
    [$r, $g, $b] = [($colour >> 16) & 255, ($colour >> 8) & 255, $colour & 255];
    imagestring($strip, $font, 0, 0, $text, (int) imagecolorallocate($strip, $r, $g, $b));

    imagecopyresized($im, $strip, $x, $y, 0, 0, $w * $scale, $h * $scale, $w, $h);
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
