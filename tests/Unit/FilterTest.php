<?php

declare(strict_types=1);

namespace Atispro\Img\Tests\Unit;

use Atispro\Img\Filter\Registry;
use Atispro\Img\Filter\Token;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    public function testParsesNameAndParameters(): void
    {
        $token = Token::parse('blur2,4');
        self::assertNotNull($token);
        self::assertSame('blur', $token->name);
        self::assertSame([2.0, 4.0], $token->params);
    }

    public function testLeadingMinusBelongsToTheParameterNotTheName(): void
    {
        $token = Token::parse('dim-20');
        self::assertNotNull($token);
        self::assertSame('dim', $token->name);
        self::assertSame([-20.0], $token->params);
    }

    public function testUnregisteredNamesAreNotFilters(): void
    {
        self::assertNull(Token::parse('landscape'), 'a real filename segment must survive');
        self::assertNull(Token::parse('2024'), 'digits alone are not a filter');
        self::assertNull(Token::parse('blur-and-more'));
    }

    public function testMissingParametersFallBackToDefaults(): void
    {
        $token = Token::parse('blur');
        self::assertNotNull($token);
        self::assertSame([0.0, 2.0], $token->params);
    }

    public function testParametersAreClampedToTheDeclaredRange(): void
    {
        $token = Token::parse('blur0,500');
        self::assertNotNull($token);
        self::assertSame(20.0, $token->params[1], 'an unbounded sigma is a CPU denial of service');

        $token = Token::parse('dim-100000');
        self::assertNotNull($token);
        self::assertSame(-100.0, $token->params[0]);
    }

    public function testExcessParametersAreDropped(): void
    {
        $token = Token::parse('blur1,2,3,4,5');
        self::assertNotNull($token);
        self::assertCount(2, $token->params);
    }

    /**
     * Free-form float parameters meant blur0,2 / blur0,2.1 / blur0,2.11 were
     * three separate cache entries for one visually identical image.
     */
    public function testCanonicalTokenCollapsesEquivalentRequests(): void
    {
        self::assertSame('blur', Token::parse('blur0,2')?->token());
        self::assertSame('blur', Token::parse('blur')?->token());
        self::assertSame('blur0,4', Token::parse('blur0,4')?->token());
        self::assertSame('dim-25', Token::parse('dim-25')?->token());
    }

    /**
     * A canonical token has to survive being embedded in a filename, and '.' is
     * what separates filename segments — so no parameter may quantise to a
     * fractional value.
     */
    public function testCanonicalTokensNeverContainADot(): void
    {
        $cases = [
            'blur0,2', 'blur3,7', 'blur0,500', 'softblur1,1', 'dim-25', 'dim99',
            'darken-10', 'lighten100', 'modulate120,80,100', 'grayscale30',
            'sepia80', 'brighten130', 'vintage',
        ];

        foreach ($cases as $case) {
            $token = Token::parse($case);
            self::assertNotNull($token, "{$case} did not parse");
            self::assertStringNotContainsString('.', $token->token(), "{$case} canonicalises with a dot in it");
        }
    }

    public function testFractionalInputQuantisesToAWholeNumber(): void
    {
        // Reachable through adaptiveUrl()-style callers, even though a URL
        // segment cannot express it.
        $token = new Token('blur', [0.0, 2.0]);
        self::assertSame('blur', $token->token());

        self::assertSame([0.0, 4.0], Token::parse('blur0,4')?->params);
    }

    /**
     * The CLI argv was built as '-' . $param . 'x0', so a negative parameter
     * produced the literal '--10x0'. ImageMagick rejects it, the convert exits
     * non-zero and the request 404s — but only on the CLI backend.
     */
    public function testNegativeAmountsProduceValidCliArguments(): void
    {
        $ops = Registry::pipeline('darken', [-10.0]);
        self::assertSame(['-brightness-contrast', '+10x0'], $ops[0]['cli']);

        $ops = Registry::pipeline('darken', [25.0]);
        self::assertSame(['-brightness-contrast', '-25x0'], $ops[0]['cli']);

        $ops = Registry::pipeline('lighten', [-10.0]);
        self::assertSame(['-brightness-contrast', '-10x0'], $ops[0]['cli']);
    }

    /**
     * -sepia-tone takes a percentage; sepiaToneImage() takes a quantum value.
     * Passing the same number to both made the imagick output a near no-op.
     */
    public function testSepiaConvertsPercentToQuantumForImagick(): void
    {
        $ops = Registry::pipeline('sepia', [80.0]);

        self::assertSame(['-sepia-tone', '80%'], $ops[0]['cli']);

        [$method, $args] = $ops[0]['imagick'];
        self::assertSame('sepiaToneImage', $method);
        self::assertGreaterThan(100.0, $args[0], 'a percentage passed straight through would be ~0.1% of range');
    }

    public function testEveryOpDefinesBothBackends(): void
    {
        $cases = [
            'modulate' => [110.0, 90.0, 100.0],
            'darken' => [25.0],
            'lighten' => [25.0],
            'dim' => [-25.0],
            'brighten' => [110.0],
            'grayscale' => [0.0],
            'sepia' => [80.0],
            'blur' => [0.0, 2.0],
            'softblur' => [0.0, 2.0],
            'vintage' => [],
        ];

        foreach ($cases as $name => $params) {
            $ops = Registry::pipeline($name, $params);
            self::assertNotEmpty($ops, "{$name} expands to nothing");

            foreach ($ops as $op) {
                self::assertArrayHasKey('imagick', $op, "{$name} has no imagick op");
                self::assertArrayHasKey('cli', $op, "{$name} has no cli op");
                self::assertNotEmpty($op['cli']);

                foreach ($op['cli'] as $argument) {
                    self::assertIsString($argument, "{$name} emits a non-string argv entry");
                    self::assertNotSame('', $argument);
                }
            }
        }
    }

    public function testVintageIsAPipelineOfThreeOps(): void
    {
        self::assertCount(3, Registry::pipeline('vintage', []));
    }
}
