<?php

declare(strict_types=1);

namespace Atispro\Img\Cli;

use Atispro\Img\Cache\Cleaner;
use Atispro\Img\Config;
use Atispro\Img\Exception\ConfigException;
use Atispro\Img\Process\Capabilities;

/**
 * Command dispatch for bin/atispro-img.
 */
final class Application
{
    private readonly Config $config;

    public function __construct(string $configPath)
    {
        if (!is_file($configPath)) {
            throw new ConfigException("config not found: {$configPath}");
        }

        /** @var array<string,mixed> $overrides */
        $overrides = require $configPath;

        $this->config = Config::fromArray($overrides);
    }

    /**
     * @param list<string> $arguments
     */
    public function run(string $command, array $arguments): int
    {
        return match ($command) {
            'clear' => $this->clear($arguments),
            'diff-legacy' => $this->diffLegacy($arguments),
            'capabilities' => $this->capabilities(),
            default => $this->unknown($command),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function clear(array $arguments): int
    {
        $dryRun = in_array('--dry-run', $arguments, true);

        // Say out loud which tree is about to be emptied. This is destructive
        // and the path comes from whichever config file was resolved.
        printf("%s: %s\n", $dryRun ? 'would clear' : 'clearing', $this->config->imagesPath);

        $stats = (new Cleaner($this->config))->clean($dryRun, self::line(...));

        printf(
            "%s%d files in %d directories, %s\n",
            $dryRun ? 'would remove ' : 'removed ',
            $stats['files'],
            $stats['dirs'],
            self::bytes($stats['bytes']),
        );

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function diffLegacy(array $arguments): int
    {
        $limit = 0;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--limit=')) {
                $limit = (int) substr($argument, 8);
            }
        }

        $stats = (new LegacyDiff($this->config))->run($limit, self::line(...));

        printf(
            "\nchecked %d: %d unchanged, %d resized, %d redirected, %d unresolvable\n",
            $stats['checked'],
            $stats['match'],
            $stats['resize'],
            $stats['redirect'],
            $stats['gone'],
        );

        // A `gone` row breaks a live page; a `resize` row silently changes what
        // every visitor sees. Both are things a migration gate should stop on,
        // so both fail by default.
        $failOn = 'resize,gone';
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--fail-on=')) {
                $failOn = substr($argument, 10);
            }
        }

        $categories = array_filter(array_map('trim', explode(',', $failOn)));
        foreach ($categories as $category) {
            if (($stats[$category] ?? 0) > 0) {
                return 1;
            }
        }

        return 0;
    }

    private function capabilities(): int
    {
        echo json_encode(
            Capabilities::diagnose($this->config),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ), "\n";

        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "unknown command: {$command}\n");

        return 1;
    }

    private static function line(string $message): void
    {
        echo $message, "\n";
    }

    private static function bytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return $bytes . ' ' . $unit;
            }
            $bytes = (int) ($bytes / 1024);
        }

        return $bytes . ' TB';
    }
}
