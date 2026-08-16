<?php

declare(strict_types=1);

namespace Atispro\Img\Cli;

use Atispro\Img\Config;
use Atispro\Img\Exception\ImgException;
use Atispro\Img\Filter\Token;
use Atispro\Img\Process\EncodePlan;
use Atispro\Img\Request\Geometry;
use Atispro\Img\Request\ImageRequest;
use Atispro\Img\Request\UrlGrammar;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Compares what this package would do against what the site's previous
 * implementation already produced.
 *
 * Read-only, and deliberately does not regenerate anything: the encoder
 * settings changed between generations, so the bytes will differ even where
 * nothing is wrong. What matters for a migration is whether a URL still
 * resolves, and whether it still resolves to the same pixel dimensions. Those
 * are predicted from the grammar and compared against the derivative sitting on
 * disk.
 *
 * The interesting rows are:
 *
 *   resize     the new grammar produces different dimensions. Expected on
 *              requests where the old independent per-axis clamp distorted the
 *              aspect ratio.
 *   redirect   the URL is no longer canonical — an off-ladder geometry, or a
 *              filter token that normalises differently.
 *   gone       the source can no longer be resolved, or the output format is
 *              not one this package will write.
 */
final class LegacyDiff
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param int $limit Stop after this many derivatives; 0 for no limit.
     * @param callable(string):void|null $report
     * @return array{checked:int,match:int,resize:int,redirect:int,gone:int}
     */
    public function run(int $limit = 0, ?callable $report = null): array
    {
        $report ??= static fn (string $line) => null;

        $stats = ['checked' => 0, 'match' => 0, 'resize' => 0, 'redirect' => 0, 'gone' => 0];

        foreach ($this->derivatives() as $file) {
            if ($limit > 0 && $stats['checked'] >= $limit) {
                break;
            }

            $stats['checked']++;
            $request = substr($file, strlen($this->config->imagesPath) + 1);
            $request = str_replace('\\', '/', $request);

            [$status, $detail] = $this->inspect($request, $file);
            $stats[$status]++;

            if ($status !== 'match') {
                $report(sprintf('%-9s %s%s', $status, $request, $detail === '' ? '' : '  — ' . $detail));
            }
        }

        return $stats;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function inspect(string $request, string $file): array
    {
        try {
            $canonical = UrlGrammar::canonicalPath($request, $this->config);

            if ($canonical === null) {
                return ['gone', 'not recognised as an image request'];
            }

            if ($canonical !== UrlGrammar::normalize($request)) {
                return ['redirect', '-> ' . $canonical];
            }

            $image = UrlGrammar::parse($request, $this->config);
        } catch (ImgException $e) {
            return ['gone', $e->getMessage()];
        }

        $actual = @getimagesize($file);
        if ($actual === false) {
            return ['gone', 'existing derivative is unreadable'];
        }

        [$expectedWidth, $expectedHeight] = $this->expectedSize($image);

        if ($expectedWidth === $actual[0] && $expectedHeight === $actual[1]) {
            return ['match', ''];
        }

        return ['resize', sprintf('on disk %dx%d, now %dx%d', $actual[0], $actual[1], $expectedWidth, $expectedHeight)];
    }

    /**
     * Final pixel dimensions, taken from the same plan the backends execute.
     *
     * Re-deriving them here was wrong in two ways: it ignored the 1% tolerance
     * in needsCrop(), so a box whose aspect nearly matched the source was
     * predicted as an exact crop when the backends actually fit it inside; and
     * it rounded where resizedSize() rounds up. Both produced `resize` rows for
     * URLs that had not changed at all — noise on precisely the signal this
     * command exists to give.
     *
     * @return array{0:int,1:int}
     */
    private function expectedSize(ImageRequest $image): array
    {
        $plan = EncodePlan::for($image, $this->config, false, true);

        if ($plan->crop !== null) {
            return [$plan->crop[0], $plan->crop[1]];
        }

        if (!$plan->needsResize()) {
            return [$image->srcWidth, $image->srcHeight];
        }

        return [$plan->resizeWidth, $plan->resizeHeight];
    }

    /**
     * Every file that looks like something a previous generation generated.
     *
     * @return list<string>
     */
    private function derivatives(): array
    {
        if (!is_dir($this->config->imagesPath)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->config->imagesPath, FilesystemIterator::SKIP_DOTS),
        );

        $out = [];
        foreach ($iterator as $path => $info) {
            /** @var SplFileInfo $info */
            if (!$info->isFile()) {
                continue;
            }

            $path = (string) $path;
            $inGeometryDir = Geometry::fromSegment(basename(dirname($path))) !== null;

            if ($inGeometryDir || self::looksGenerated($info->getFilename())) {
                $out[] = $path;
            }
        }

        sort($out);

        return $out;
    }

    private static function looksGenerated(string $filename): bool
    {
        $tokens = explode('.', $filename);
        if (count($tokens) < 3) {
            return false;
        }

        array_pop($tokens);
        $previous = strtolower((string) end($tokens));

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'tif', 'tiff', 'bmp'];

        return in_array($previous, $imageExtensions, true) || Token::parse($previous) !== null;
    }
}
