<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

/**
 * The binary had no coverage at all, which is why `--config path` — the space
 * form — could be silently ignored while `clear` went on to wipe whichever
 * cache the working directory happened to point at.
 */
final class CliTest extends TestCase
{
    private TempSite $site;

    private string $configPath;

    protected function setUp(): void
    {
        $this->site = new TempSite();

        $this->configPath = $this->site->root . '/img.config.php';
        file_put_contents($this->configPath, sprintf(
            "<?php return %s;\n",
            var_export(['imagesPath' => $this->site->root . '/files', 'capabilitiesCacheTtl' => 0], true),
        ));
    }

    protected function tearDown(): void
    {
        $this->site->destroy();
    }

    /**
     * @param list<string> $arguments
     * @return array{code:int,out:string}
     */
    private function cli(array $arguments, ?string $cwd = null): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/atispro-img';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $bin, ...$arguments],
            $descriptors,
            $pipes,
            $cwd ?? $this->site->root,
        );

        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out];
    }

    public function testConfigAcceptsTheSpaceSeparatedForm(): void
    {
        $this->site->jpeg('1044/photo.jpg', 40, 30);

        $result = $this->cli(['clear', '--dry-run', '--config', $this->configPath]);

        self::assertSame(0, $result['code'], $result['out']);
        self::assertStringContainsString($this->site->root . '/files', str_replace('\\', '/', $result['out']));
    }

    public function testConfigAcceptsTheEqualsForm(): void
    {
        $result = $this->cli(['clear', '--dry-run', '--config=' . $this->configPath]);

        self::assertSame(0, $result['code'], $result['out']);
    }

    /**
     * Previously collected into the leftover arguments and ignored, so a typo
     * in a destructive command passed silently.
     */
    public function testUnknownOptionsAreRefused(): void
    {
        $result = $this->cli(['clear', '--dry-runn', '--config=' . $this->configPath]);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('unknown option', $result['out']);
    }

    public function testAMissingConfigPathIsRefusedRatherThanGuessed(): void
    {
        $result = $this->cli(['clear', '--config', $this->site->root . '/nope.php']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('config not found', $result['out']);
    }

    public function testConfigWithoutAPathIsRefused(): void
    {
        $result = $this->cli(['clear', '--config']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('needs a path', $result['out']);
    }

    public function testUnknownCommandFails(): void
    {
        $result = $this->cli(['destroy-everything', '--config=' . $this->configPath]);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('unknown command', $result['out']);
    }

    public function testClearAnnouncesTheTreeItIsAboutToEmpty(): void
    {
        $this->site->jpeg('1044/photo.jpg', 40, 30);
        $this->site->jpeg('1044/400x/photo.jpg', 40, 30);

        $result = $this->cli(['clear', '--config=' . $this->configPath]);

        self::assertSame(0, $result['code'], $result['out']);
        self::assertStringContainsString('clearing', $result['out']);
        self::assertTrue($this->site->exists('1044/photo.jpg'), 'the original survives');
        self::assertFalse($this->site->exists('1044/400x/photo.jpg'), 'the derivative goes');
    }
}
