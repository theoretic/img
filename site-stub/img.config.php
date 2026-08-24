<?php

/**
 * Per-site configuration: only what differs from the package defaults.
 * See Atispro\Img\Config::defaults() for everything that can be set here.
 */

declare(strict_types=1);

/**
 * The CLI SAPI hardcodes $_SERVER['DOCUMENT_ROOT'] to an empty string, and no
 * environment variable can override it. Without the fallback every documented
 * command — `atispro-img clear`, `diff-legacy`, `capabilities` — resolves
 * imagesPath to "/site/assets/files" and dies with "not a directory".
 *
 * This file is deployed at webroot/api/img/img.config.php, so two levels up is
 * the webroot. Adjust the depth if you put it somewhere else.
 */
$root = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__, 2);

return [
    'imagesPath' => "{$root}/site/assets/files",
    'publicBase' => '/site/assets/files',

    // Off-ladder geometry handling. 'redirect' (the default) answers with a 301
    // to the nearest canonical rung, which bounds the derivative cache without
    // breaking clients that still emit arbitrary sizes. Move a site to 'strict'
    // once its adaptive-media build is known to snap to the same ladders.
    // 'geometryPolicy' => 'strict',

    // Must match `sizes` in @atispro/core adaptive-media.js — ONE ladder shared
    // by both axes there, so widths and heights must be identical here too.
    // These are the package defaults, spelled out for reference:
    // 'widths'  => [200, 300, 400, 450, 600, 800, 1000, 1200, 1600, 2000, 2400, 3000],
    // 'heights' => [200, 300, 400, 450, 600, 800, 1000, 1200, 1600, 2000, 2400, 3000],

    // Filter names this site's URLs may invoke. Every registered parameter
    // combination is a distinct cache entry, so an unused filter is pure attack
    // surface: a site using no filters should say [], one using two should list
    // the two. Unset means every registered filter (the compatible default).
    // 'filters' => ['darken', 'blur'],

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
