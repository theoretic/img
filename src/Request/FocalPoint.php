<?php

declare(strict_types=1);

namespace Atispro\Img\Request;

/**
 * Crop centre, read from the source file's IPTC SpecialInstructions field,
 * which may carry JSON like {"focalPoint":{"x":"40%","y":"60%"}}.
 *
 * Read on demand, not during parsing: it costs a file read plus a require of a
 * ProcessWire class, and it only matters when a crop is actually performed. The
 * legacy implementation read it on every request, including 304s and
 * passthroughs.
 */
final class FocalPoint
{
    private const CENTRE = ['x' => '50%', 'y' => '50%'];

    /** @var array<string,array{x:string,y:string}> */
    private static array $memo = [];

    /**
     * @return array{x:string,y:string}
     */
    /**
     * @param string $iptcClassPath Where the site keeps its IPTC reader; empty disables the lookup.
     * @return array{x:string,y:string}
     */
    public static function read(string $srcFile, string $iptcClassPath): array
    {
        return self::$memo[$srcFile] ??= self::extract($srcFile, $iptcClassPath);
    }

    /**
     * @return array{x:string,y:string}
     */
    private static function extract(string $srcFile, string $iptcClassPath): array
    {
        // Taken from configuration rather than $_SERVER['DOCUMENT_ROOT']: this
        // path is require_once'd, and a request-influenced DOCUMENT_ROOT would
        // turn a crop into arbitrary PHP inclusion.
        if ($iptcClassPath === '' || !is_file($iptcClassPath)) {
            return self::CENTRE;
        }

        $iptcClass = $iptcClassPath;

        require_once $iptcClass;

        // Resolved dynamically: IPTC is a ProcessWire class that lives outside
        // this package and may not be present at all.
        $class = 'IPTC';
        if (!class_exists($class) || !defined('IPTC_SPECIAL_INSTRUCTIONS')) {
            return self::CENTRE;
        }

        try {
            $iptc = new $class($srcFile);
            if (!method_exists($iptc, 'getValue')) {
                return self::CENTRE;
            }

            $json = $iptc->getValue(constant('IPTC_SPECIAL_INSTRUCTIONS'));
        } catch (\Throwable) {
            return self::CENTRE;
        }

        if (!is_string($json) || $json === '') {
            return self::CENTRE;
        }

        $data = json_decode($json);
        if (!is_object($data) || !isset($data->focalPoint)) {
            return self::CENTRE;
        }

        $focal = (array) $data->focalPoint;

        return [
            'x' => isset($focal['x']) ? (string) $focal['x'] : self::CENTRE['x'],
            'y' => isset($focal['y']) ? (string) $focal['y'] : self::CENTRE['y'],
        ];
    }
}
