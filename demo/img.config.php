<?php

/**
 * Demo configuration. Mirrors site-stub/img.config.php, but rooted in demo/
 * instead of a ProcessWire assets tree.
 */

declare(strict_types=1);

return [
    'imagesPath' => __DIR__ . '/files',
    'publicBase' => '/img',

    // Left at the default so the canonicalisation cases below actually redirect
    // rather than 404. Flip to 'strict' to see the other policy.
    // 'geometryPolicy' => 'strict',

    'debug' => true,
];
