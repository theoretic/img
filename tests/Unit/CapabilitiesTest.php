<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Process\Capabilities;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    private TempSite $site;

    protected function setUp(): void
    {
        $this->site = new TempSite();
    }

    protected function tearDown(): void
    {
        $this->site->destroy();
        Capabilities::reset();
    }

    /**
     * Candidate enumeration only — deliberately not diagnose(), which probes
     * every candidate. A failed PATH lookup costs a process spawn, and on
     * Windows that runs into seconds.
     *
     * @return list<string>
     */
    private function tried(string $imagemagickPath): array
    {
        return Capabilities::binaryCandidates(
            $this->site->config(['imagemagickPath' => $imagemagickPath]),
        );
    }

    /**
     * The common production shape: no ext-imagick, ImageMagick installed the
     * ordinary way, nothing configured. Resolution has to go through PATH.
     */
    public function testBareNamesAreTriedWhenNothingIsConfigured(): void
    {
        self::assertSame(['magick', 'convert'], $this->tried(''));
    }

    /**
     * A configured directory that does not hold the binaries must not disable
     * the CLI backend — stale paths outlive upgrades, and configs get copied
     * between hosts.
     */
    public function testPathFallbackSurvivesAWrongConfiguredDirectory(): void
    {
        $tried = $this->tried($this->site->root . '/no-such-directory');

        self::assertContains('magick', $tried);
        self::assertContains('convert', $tried);
    }

    public function testConfiguredDirectoryIsPreferredButDoesNotReplacePath(): void
    {
        $dir = $this->site->root . '/im';
        mkdir($dir, 0o777, true);
        $name = str_starts_with(PHP_OS, 'WIN') ? 'magick.exe' : 'magick';
        file_put_contents($dir . '/' . $name, '');

        $tried = $this->tried($dir);

        self::assertSame($dir . '/' . $name, $tried[0], 'the configured binary is tried first');
        self::assertContains('magick', $tried, 'and PATH is still a fallback');
        self::assertContains('convert', $tried);
    }

    /**
     * Exit status alone is not proof. On Windows the `convert` on the default
     * PATH is system32's filesystem converter; selecting it would have failed
     * every conversion afterwards.
     */
    public function testASelectedBinaryAlwaysIdentifiesItselfAsImageMagick(): void
    {
        Capabilities::reset();
        $config = $this->site->config(['capabilitiesCacheTtl' => 0]);

        $diagnosis = Capabilities::diagnose($config);
        $selected = $diagnosis['state']['cli'];

        if ($selected === null) {
            self::markTestSkipped('no ImageMagick binary on this host');
        }

        $match = null;
        foreach ($diagnosis['cli_candidates'] as $candidate) {
            if ($candidate['tried'] === $selected) {
                $match = $candidate;
                break;
            }
        }

        self::assertNotNull($match, 'the selected binary is not among the candidates');
        self::assertSame(0, $match['exit_code']);
        self::assertTrue($match['is_imagemagick'], "selected {$selected}, which does not report itself as ImageMagick");
    }

    /**
     * The diagnosis carries text produced by a shell, which reports failures in
     * the system codepage. json_encode() returns false on malformed UTF-8, so
     * without sanitising the captured output the debug endpoint answered with
     * an empty body — precisely when it was being used to diagnose something.
     */
    public function testDiagnosisIsAlwaysJsonEncodable(): void
    {
        Capabilities::reset();
        $config = $this->site->config(['capabilitiesCacheTtl' => 0]);

        $json = json_encode(Capabilities::diagnose($config));

        self::assertIsString($json, 'json_encode failed: ' . json_last_error_msg());
        self::assertJson($json);
    }

    /**
     * The marker is what stops a cold host re-probing on every request, and it
     * was never exercised: TempSite forces the TTL to 0 everywhere else.
     */
    public function testMarkerIsWrittenAndReused(): void
    {
        Capabilities::reset();
        $config = $this->site->config(['capabilitiesCacheTtl' => 3600]);

        Capabilities::hasImagick($config);

        $marker = $this->site->absolute('.capabilities.json');
        self::assertFileExists($marker);

        $state = json_decode((string) file_get_contents($marker), true);
        self::assertIsArray($state);
        self::assertArrayHasKey('v', $state);
        self::assertArrayHasKey('cli', $state);

        // A second process reads it back rather than probing again.
        Capabilities::reset();
        self::assertSame($state['cli'], Capabilities::cliBinary($config));
    }

    public function testAMarkerFromAnotherSchemaIsIgnored(): void
    {
        $config = $this->site->config(['capabilitiesCacheTtl' => 3600]);
        file_put_contents(
            $this->site->absolute('.capabilities.json'),
            json_encode(['v' => 1, 'imagick' => true, 'cli' => true]),
        );

        Capabilities::reset();

        // Re-probed rather than trusted: the old shape stored `cli` as a bool,
        // which would otherwise be handed to the CLI backend as a binary name.
        self::assertNotSame(true, Capabilities::cliBinary($config));
    }

    /**
     * A single static memo made the first Config in a process win for every
     * later one, so a multi-site CLI run resolved one site's backend from
     * another site's configuration.
     */
    public function testCapabilitiesAreMemoisedPerConfiguration(): void
    {
        Capabilities::reset();

        $real = $this->site->config(['capabilitiesCacheTtl' => 0]);
        $broken = $this->site->config([
            'capabilitiesCacheTtl' => 0,
            'processor' => 'cli',
            'imagemagickPath' => $this->site->root . '/nowhere',
        ]);

        $first = Capabilities::binaryCandidates($real);
        $second = Capabilities::binaryCandidates($broken);

        self::assertNotSame($first, $second, 'each configuration resolves its own candidates');
    }

    public function testCapturedOutputIsValidUtf8(): void
    {
        // A name that cannot exist, so the shell's own error text is captured.
        $result = Capabilities::run(['definitely-not-a-real-binary-' . bin2hex(random_bytes(4)), '-version']);

        self::assertNotSame(0, $result['rc']);
        foreach (['out', 'err'] as $stream) {
            self::assertTrue(
                !function_exists('mb_check_encoding') || mb_check_encoding($result[$stream], 'UTF-8'),
                "{$stream} is not valid UTF-8",
            );
        }
    }
}
