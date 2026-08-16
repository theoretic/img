<?php

declare(strict_types=1);

namespace Atispro\Img\Filter;

/**
 * The filter token embedded in a filename — the `dim-25` in
 * `photo.dim-25.jpg.avif`.
 *
 * A segment is only consumed as a filter when the parsed name is in the
 * registry. That protects real filenames that happen to end in digits: an
 * unregistered token stays part of the name and the file is looked up as
 * written.
 *
 * Parameters are clamped to the registry's declared range and quantised to its
 * step, then the token is re-rendered in canonical form. The canonical form is
 * what bounds the cache: without it, blur0,2 / blur0,2.1 / blur0,2.11 are three
 * distinct derivatives of one image that look identical.
 *
 * Parameters are whole numbers. They cannot be anything else: '.' separates
 * filename segments, so `photo.blur0,4.5.jpg` is split before this class ever
 * sees it. Every step in the registry is integral for that reason.
 */
final readonly class Token
{
    /**
     * @param list<float> $params Clamped and quantised.
     */
    public function __construct(
        public string $name,
        public array $params,
    ) {
    }

    /**
     * Interpret a filename segment as `filterName[numericParams]`.
     * Returns null when the name is not registered.
     */
    public static function parse(string $segment): ?self
    {
        // The name is letters and underscores only — no hyphen — so a leading
        // minus in the parameters is not swallowed by the name.
        // e.g. dim-20 => name "dim", params [-20]
        // D so $ cannot match before a trailing newline: without it a segment
        // ending %0a passes this "syntactic" gate and the newline travels on
        // into the canonical filename.
        if (!preg_match('/^([a-z][a-z_]*)([-\d.,]*)$/iD', $segment, $m)) {
            return null;
        }

        $name = strtolower($m[1]);
        if (!Registry::exists($name)) {
            return null;
        }

        $raw = $m[2] !== ''
            ? array_map('floatval', explode(',', trim($m[2], ',')))
            : [];

        return new self($name, self::normalize($name, $raw));
    }

    /**
     * Canonical rendering. Parameters that are still at their default are
     * dropped from the right, so `blur0,2` and a bare `blur` collapse onto the
     * same cache entry.
     */
    public function token(): string
    {
        $spec = Registry::spec($this->name);
        $params = $this->params;

        for ($i = count($params) - 1; $i >= 0; $i--) {
            if ($params[$i] !== $spec[$i]['default']) {
                break;
            }
            array_pop($params);
        }

        return $params === []
            ? $this->name
            : $this->name . implode(',', array_map(self::num(...), $params));
    }

    /**
     * @return list<array{imagick:array{0:string,1:list<mixed>},cli:list<string>}>
     */
    public function pipeline(): array
    {
        return Registry::pipeline($this->name, $this->params);
    }

    /**
     * Fill in defaults, clamp to range, quantise to step. Parameters beyond the
     * declared arity are dropped rather than passed through.
     *
     * @param list<float> $raw
     * @return list<float>
     */
    private static function normalize(string $name, array $raw): array
    {
        $out = [];
        foreach (Registry::spec($name) as $i => $param) {
            $value = $raw[$i] ?? $param['default'];
            $value = max($param['min'], min($param['max'], $value));

            if ($param['step'] > 0) {
                $value = round($value / $param['step']) * $param['step'];
            }

            $out[] = $value;
        }

        return $out;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
