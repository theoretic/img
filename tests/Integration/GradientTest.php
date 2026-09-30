<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Integration;

use Atispro\Img\Exception\ProcessException;
use Atispro\Img\Preview\Gradient;
use Atispro\Img\Process\Capabilities;
use Atispro\Img\Tests\Support\TempSite;
use PHPUnit\Framework\TestCase;

/**
 * Corner sampling against every backend this host has. The two must agree to
 * the unit: a site's cached previews were made by whichever one it had then.
 */
final class GradientTest extends TestCase
{
    private const QUADRANTS = [[200, 40, 40], [40, 200, 40], [40, 40, 200], [240, 240, 240]];

    private TempSite $site;

    protected function setUp(): void
    {
        $this->site = new TempSite();
    }

    protected function tearDown(): void
    {
        $this->site->destroy();
    }

    /** @return list<string> */
    private function backends(): array
    {
        $config = $this->site->config();
        $out = [];
        if (Capabilities::hasImagick($config)) {
            $out[] = 'imagick';
        }
        if (Capabilities::hasCli($config)) {
            $out[] = 'cli';
        }
        if ($out === []) {
            self::markTestSkipped('no image backend available on this host');
        }

        return $out;
    }

    public function testEachQuadrantBecomesItsCorner(): void
    {
        $file = $this->site->absolute($this->site->quadrants('1044/q.png', 40, 30, self::QUADRANTS));

        foreach ($this->backends() as $backend) {
            $gradient = Gradient::fromFile($file, $this->site->config(['processor' => $backend]), 1.0);

            self::assertSame(['#c82828', '#28c828', '#2828c8', '#f0f0f0'], $gradient->colors, $backend);
        }
    }

    public function testBackendsAgreeOnARealGradient(): void
    {
        $file = $this->site->absolute($this->site->jpeg('1044/photo.jpg', 300, 200));

        $results = [];
        foreach ($this->backends() as $backend) {
            $results[$backend] = Gradient::fromFile($file, $this->site->config(['processor' => $backend]))->colors;
        }

        self::assertCount(1, array_unique(array_map('serialize', $results)), var_export($results, true));
    }

    public function testAnAnimationIsSampledFromItsFirstFrame(): void
    {
        $file = $this->site->absolute($this->site->gif('1044/anim.gif', 40, 30));

        foreach ($this->backends() as $backend) {
            $gradient = Gradient::fromFile($file, $this->site->config(['processor' => $backend]));

            self::assertCount(4, $gradient->colors, $backend);
        }
    }

    public function testAnUnreadableFileIsAProcessError(): void
    {
        $file = $this->site->root . '/files/not-an-image.png';
        file_put_contents($file, 'plain text');

        foreach ($this->backends() as $backend) {
            try {
                Gradient::fromFile($file, $this->site->config(['processor' => $backend]));
                self::fail("{$backend} sampled a text file");
            } catch (ProcessException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
