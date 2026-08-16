<?php

declare(strict_types=1);

namespace Atispro\Img;

/**
 * What the pipeline decided to do with a request: serve a file, or send the
 * client to the canonical URL for what it asked.
 */
final readonly class Result
{
    public const SERVE = 'serve';

    public const REDIRECT = 'redirect';

    private function __construct(
        public string $kind,
        public string $path,
    ) {
    }

    /** @param string $file Absolute path of the file to send. */
    public static function serve(string $file): self
    {
        return new self(self::SERVE, $file);
    }

    /** @param string $url Canonical URL to redirect to. */
    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, $url);
    }

    public function isRedirect(): bool
    {
        return $this->kind === self::REDIRECT;
    }
}
