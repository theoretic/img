<?php

/**
 * Per-site configuration: only what differs from the package defaults.
 * See Atispro\Img\Config::defaults() for everything that can be set here.
 */

declare(strict_types=1);

$root = $_SERVER['DOCUMENT_ROOT'];

return [
    'imagesPath' => "{$root}/site/assets/files",
    'publicBase' => '/site/assets/files',

    // Off-ladder geometry handling. 'redirect' (the default) answers with a 301
    // to the nearest canonical rung, which bounds the derivative cache without
    // breaking clients that still emit arbitrary sizes. Move a site to 'strict'
    // once its adaptive-media build is known to snap to the same ladders.
    // 'geometryPolicy' => 'strict',

    // Must match the ladders in @atispro/core adaptive-media.js.
    // 'widths'  => [200, 300, 400, 640, 800, 1024, 1280, 1600, 1920, 2400, 3000],
    // 'heights' => [200, 300, 400, 600, 800, 1000, 1600, 2000],

    // 'processor' => 'auto',

    // Usually leave this alone. Unset means "find magick/convert on PATH",
    // which covers the normal case of ImageMagick installed as a package with
    // no PHP extension. Set it only when the binaries are somewhere PATH does
    // not reach; PATH stays a fallback either way.
    // 'imagemagickPath' => '/opt/imagemagick/bin',

    // Optional external encoder. cavif produces noticeably smaller AVIFs than
    // ImageMagick's own coder; 'from' gates it on the SOURCE format, so nothing
    // else in the pipeline is affected.
    // 'externalEncoders' => [
    //     'avif' => [
    //         'bin'  => '/usr/local/bin/cavif',
    //         'args' => ['--quality', '55'],
    //         'from' => ['png'],
    //     ],
    // ],

    // Enables ?debug=capabilities on direct hits to index.php. Leave off in
    // production — `atispro-img capabilities` reports the same thing.
    // 'debug' => true,
];
