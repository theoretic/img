<?php

declare(strict_types=1);

namespace Atispro\Img;

use Atispro\Img\Cache\Store;
use Atispro\Img\Exception\BackendException;
use Atispro\Img\Exception\EncoderException;
use Atispro\Img\Exception\NotFoundException;
use Atispro\Img\Exception\ProcessException;
use Atispro\Img\Process\Capabilities;
use Atispro\Img\Process\CliProcessor;
use Atispro\Img\Process\ExternalEncoder;
use Atispro\Img\Process\ImagickProcessor;
use Atispro\Img\Process\ProcessorFactory;
use Atispro\Img\Process\ProcessorInterface;
use Atispro\Img\Request\ImageRequest;
use Atispro\Img\Request\UrlGrammar;

/**
 * Turns a request path into a file to serve, generating the derivative when it
 * is missing or stale.
 *
 * The whole pipeline is independent of how it was invoked: the site stub uses
 * it to answer an HTTP request, the CLI uses it to warm or verify a cache, and
 * the tests use it directly.
 */
final class Pipeline
{
    /**
     * Bumped whenever a change to this package can change output bytes. Written
     * into the cache stamp, so upgrading the package invalidates derivatives
     * that predate it — the URL is the cache key and cannot carry a version, so
     * staleness is decided by age instead.
     */
    public const VERSION = '1';

    private readonly Store $store;

    public function __construct(private readonly Config $config)
    {
        $this->store = new Store($config);
    }

    /**
     * @throws NotFoundException
     * @throws ProcessException
     */
    public function handle(string $request): Result
    {
        // Tokenised once and reused: asking for the canonical path and then
        // parsing ran the whole validate-and-split twice over.
        $parts = UrlGrammar::inspect($request, $this->config);
        if ($parts === null) {
            throw new NotFoundException('not an image request');
        }

        $canonical = UrlGrammar::canonicalFrom($parts, $this->config);

        if ($canonical !== UrlGrammar::normalize($request)) {
            // Permanent: purely syntactic, so it can never change for this URL.
            return Result::redirect($this->config->publicBase . '/' . $canonical);
        }

        $image = UrlGrammar::resolve($parts, $request, $this->config);

        // When the requested box already covers the whole source, the geometry
        // stops mattering and the derivative is stored beside the original.
        // Send the client to where it actually lives: otherwise the URL names a
        // path that never exists on disk, so Apache's `!-s` rule can never
        // short-circuit and every single request re-enters PHP.
        //
        // Temporary, unlike the canonicalisation above: whether the box covers
        // the source depends on the source's current dimensions, and replacing
        // the image in place must not leave clients holding a 301 for a week.
        $destination = $this->relativePath($image->dstFile);
        if ($destination !== null && $destination !== $canonical) {
            return Result::redirect($this->config->publicBase . '/' . $destination, permanent: false);
        }

        // A fit (`f`) request resolves to a single-axis derivative once the
        // source aspect is known; the free axis is only a second cache key over
        // identical bytes. Collapse it — temporary too, because replacing the
        // source can flip which axis binds. Old derivatives generated before
        // this rule still short-circuit in Apache and never reach here.
        $cover = UrlGrammar::coverCanonical($parts, $image);
        if ($cover !== null && $cover !== $canonical) {
            return Result::redirect($this->config->publicBase . '/' . $cover, permanent: false);
        }

        if ($image->isPassthrough()) {
            return Result::serve($image->srcFile);
        }

        if (!$this->store->isFresh($image->dstFile, $image->srcFile)) {
            $this->store->publish(
                $image->dstFile,
                $image->srcFile,
                fn (string $temp) => $this->write($image, $temp),
            );
        }

        return Result::serve($image->dstFile);
    }

    /**
     * A path expressed relative to imagesPath, or null when it lies outside.
     */
    private function relativePath(string $file): ?string
    {
        $root = $this->config->imagesPath;
        $file = str_replace('\\', '/', $file);

        if (!str_starts_with($file, $root . '/')) {
            return null;
        }

        return substr($file, strlen($root) + 1);
    }

    /**
     * @throws ProcessException
     */
    private function write(ImageRequest $image, string $temp): void
    {
        $processor = ProcessorFactory::make($this->config);
        if ($processor === null) {
            throw new ProcessException('no usable image backend (neither ext-imagick nor an ImageMagick binary)');
        }

        try {
            $this->attempt($processor, $image, $temp);

            return;
        } catch (EncoderException $e) {
            // The backend did its job; the external encoder is what failed.
            // Demoting imagick for this would disable the fast path host-wide
            // because one PNG upset cavif.
            throw $e;
        } catch (BackendException $e) {
            // The build itself is broken — a missing method, a library that
            // will not load. That is worth remembering for the whole TTL.
            self::log('imagick backend is unusable, falling back to CLI: ' . $e->getMessage());
            Capabilities::markImagickUnhealthy($this->config);

            if (!Capabilities::hasCli($this->config) || $this->config->processor !== 'auto') {
                throw $e;
            }
        } catch (ProcessException $e) {
            // A per-image failure: a corrupt upload, a resource limit. Retry on
            // the other backend, but do NOT demote — one bad file requested on a
            // loop would otherwise keep the extension switched off forever,
            // since each demotion refreshes the marker that dates the TTL.
            if (!$processor instanceof ImagickProcessor
                || !Capabilities::hasCli($this->config)
                || $this->config->processor !== 'auto'
            ) {
                throw $e;
            }

            self::log('imagick failed on this image, retrying on CLI: ' . $e->getMessage());
        }

        // A partial file from the failed attempt would otherwise be handed to
        // the retry, and some external encoders refuse to overwrite.
        if (is_file($temp)) {
            @unlink($temp);
        }

        $this->attempt(new CliProcessor($this->config), $image, $temp);
    }

    /**
     * One backend attempt, including the optional external encoder.
     *
     * @throws ProcessException when this image could not be converted
     * @throws BackendException when the backend is unusable on this host
     * @throws EncoderException when a configured external encoder failed
     */
    private function attempt(ProcessorInterface $processor, ImageRequest $image, string $temp): void
    {
        $encoder = $this->config->externalEncoderFor($image->extension, $image->srcExtension);

        if ($encoder === null || !ExternalEncoder::available($encoder['bin'])) {
            if ($encoder !== null) {
                self::log("external encoder {$encoder['bin']} is not runnable; using ImageMagick");
            }

            $processor->process($image, $temp, $image->extension);

            return;
        }

        // The backend produces a lossless intermediate and the external encoder
        // does the final compression.
        $intermediate = $this->store->temp($temp, '.png');

        try {
            $processor->process($image, $intermediate, 'png');

            try {
                ExternalEncoder::run($encoder, $intermediate, $temp);
            } catch (ProcessException $e) {
                // Re-labelled so the demotion logic upstream leaves the image
                // backend alone: it produced a perfectly good intermediate.
                throw new EncoderException($e->getMessage(), 0, $e);
            }
        } finally {
            if (is_file($intermediate)) {
                @unlink($intermediate);
            }
        }
    }

    /**
     * Failures used to be swallowed — two bare catches in the imagick backend, a
     * discarded stderr pipe in the CLI one — so a broken conversion surfaced as
     * an opaque 404 with nothing anywhere to explain it.
     */
    private static function log(string $message): void
    {
        error_log('[atispro-img] ' . $message);
    }
}
