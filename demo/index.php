<?php

declare(strict_types=1);

require __DIR__ . '/_samples.php';
require __DIR__ . '/../vendor/autoload.php';

use Atispro\Img\Cache\Cleaner;
use Atispro\Img\Config;
use Atispro\Img\Process\Capabilities;

$filesDir = __DIR__ . '/files';
$sources = demo_sources($filesDir, dirname(__DIR__) . '/samples');

// A non-image file, so the "not an image request" refusal is demonstrably about
// the grammar and not about the file being absent.
if (!is_file($filesDir . '/notes.txt')) {
    file_put_contents($filesDir . '/notes.txt', "not an image\n");
}

// ---------------------------------------------------------------- cache views

// POST only, deliberately. As a GET link this was silently fired by the
// browser's speculative prefetch and wiped the cache behind our back — which is
// the same shape as the rmthumbs.php problem this package exists to fix.
$config = Config::fromArray(require __DIR__ . '/img.config.php');

// A token, even here. The form is a plain POST with a fixed body, so without
// one any page open in the same browser could wipe the cache with a no-cors
// fetch. Same shape as the rmthumbs.php problem this package exists to fix.
session_start();
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['clear'] ?? '') === 'confirm') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        header('HTTP/1.1 403 Forbidden');
        exit("bad CSRF token\n");
    }

    $stats = (new Cleaner($config))->clean();
    header('Location: ?cleared=' . $stats['files']);
    exit;
}

$capabilities = Capabilities::diagnose($config);

/**
 * @return list<array{path:string,size:int}>
 */
function demo_cacheListing(string $root): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    $out = [];
    foreach ($iterator as $path => $info) {
        if ($info->isFile()) {
            $out[] = ['path' => str_replace('\\', '/', substr((string) $path, strlen($root) + 1)), 'size' => $info->getSize()];
        }
    }

    usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

    return $out;
}

// ------------------------------------------------------------------ the cases

/**
 * kind: image (default) — load it, report the delivered pixels
 *       redirect        — expect a 301 to `to`
 *       refused         — expect a 404
 *
 * expect: [width|null, height|null] — null means "derived, do not assert"
 * type:   expected Content-Type, when it is worth pinning
 */
$sections = [
    [
        'title' => 'Fit modes against a 400×800 placeholder',
        'blurb' => 'The same tall box against sources of every shape. <code>400x/</code> is what the client '
            . 'used to ask for on its own: it constrains the width and lets the height fall where it may, '
            . 'which under-fills the box whenever the source is wider than the box is. <code>400x800f/</code> '
            . 'is the fix — the server compares the box aspect against the source aspect and constrains '
            . 'whichever axis actually binds.',
        'cases' => [
            ['src' => 'mars.jpg', 'path' => '400x/mars.jpg', 'expect' => [400, null], 'note' => 'width only — height lands near 239, nowhere near 800'],
            ['src' => 'mars.jpg', 'path' => 'x800/mars.jpg', 'expect' => [null, 800], 'note' => 'height only'],
            ['src' => 'mars.jpg', 'path' => '400x800/mars.jpg', 'expect' => [400, 800], 'note' => 'crop: exactly the box, centre-weighted'],
            ['src' => 'mars.jpg', 'path' => '400x800f/mars.jpg', 'expect' => [null, 800], 'note' => 'cover: height binds, width ≈1341, nothing cropped'],

            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.jpeg', 'expect' => [400, null], 'note' => 'width only'],
            ['src' => 'parrot.jpeg', 'path' => '400x800/parrot.jpeg', 'expect' => [400, 800], 'note' => 'crop'],
            ['src' => 'parrot.jpeg', 'path' => '400x800f/parrot.jpeg', 'expect' => [null, 800], 'note' => 'cover: height binds, width ≈627'],

            ['src' => 'pano.jpg', 'path' => '400x/pano.jpg', 'expect' => [400, null], 'note' => 'width only — 6:1 source, 67px tall'],
            ['src' => 'pano.jpg', 'path' => '400x800/pano.jpg', 'expect' => [200, 400], 'note' => 'crop, but the box is taller than the source: the whole box shrinks, 1:2 kept'],
            ['src' => 'pano.jpg', 'path' => '400x800f/pano.jpg', 'expect' => [2400, 400], 'note' => 'cover is impossible — only 400 rows exist. Whole source, no upscale'],

            ['src' => 'tower.jpg', 'path' => '400x800/tower.jpg', 'expect' => [400, 800], 'note' => 'crop'],
            ['src' => 'tower.jpg', 'path' => '400x800f/tower.jpg', 'expect' => [400, 1600], 'note' => 'cover: width binds, and it overshoots vertically'],
            ['src' => 'tower.jpg', 'path' => '1600x400/tower.jpg', 'expect' => [400, 100], 'note' => 'landscape box on a 1:4 source — shrinks to fit, 4:1 kept'],

            ['src' => 'square.jpg', 'path' => '400x800/square.jpg', 'expect' => [400, 800], 'note' => 'crop'],
            ['src' => 'square.jpg', 'path' => '400x800f/square.jpg', 'expect' => [800, 800], 'note' => 'cover: height binds on a square source'],
        ],
    ],

    [
        'title' => 'No upscaling, ever',
        'blurb' => 'Every box here exceeds the source on at least one axis. Nothing may come back larger '
            . 'than the source — and when both axes are constrained, the box is scaled down '
            . '<em>proportionally</em> rather than each axis being clamped on its own, so the requested '
            . 'aspect ratio survives.',
        'cases' => [
            ['src' => 'parrot.jpeg', 'path' => '1600x/parrot.jpeg', 'expect' => [700, null], 'note' => 'source is 700 wide; clamped'],
            ['src' => 'parrot.jpeg', 'path' => '1600x1600/parrot.jpeg', 'expect' => [700, 700], 'note' => '1:1 requested, 1:1 delivered'],
            ['src' => 'parrot.jpeg', 'path' => '3000x2000/parrot.jpeg', 'expect' => [700, 466], 'note' => '3:2 requested, 3:2 delivered — independent clamping would have given 700×893'],
            ['src' => 'parrot.jpeg', 'path' => '3000x/parrot.jpeg', 'expect' => [700, null], 'note' => 'top rung, small source'],
            ['src' => 'mars.jpg', 'path' => '3000x/mars.jpg', 'expect' => [3000, null], 'note' => 'top rung, and the source is big enough to honour it'],
            ['src' => 'tiny.png', 'path' => '800x800/tiny.png', 'expect' => [90, 90], 'note' => '120×90 source; the box is ~9× too big'],
            ['src' => 'tiny.png', 'path' => '800x/tiny.png', 'expect' => [120, null], 'note' => 'clamped to the full source width'],
            ['src' => 'tiny.png', 'path' => 'x800/tiny.png', 'expect' => [null, 90], 'note' => 'clamped to the full source height'],
            ['src' => 'pano.jpg', 'path' => '2400x2000/pano.jpg', 'expect' => [480, 400], 'note' => 'width fits exactly, height does not — the 6:5 box shrinks to 480×400'],
            ['src' => 'tower.jpg', 'path' => 'x2000/tower.jpg', 'expect' => [null, 1600], 'note' => 'clamped to the full source height'],
            ['src' => 'logo.png', 'path' => '800x800/logo.png', 'expect' => [512, 512], 'note' => 'square source, square box'],
            ['src' => 'logo.png', 'path' => '1200x1600/logo.png', 'expect' => [384, 512], 'note' => 'a 1200×1600 box against a 512×512 source: 0.75:1 requested, 0.75:1 delivered'],
        ],
    ],

    [
        'title' => 'Format conversion',
        'blurb' => 'The target format is a second extension on the filename. Content-Type comes from the '
            . 'file’s magic bytes rather than its name — which matters for AVIF, because some ImageMagick '
            . 'builds cannot store alpha in it and the pipeline substitutes WebP into the same path.',
        'cases' => [
            ['src' => 'mars.jpg', 'path' => '800x/mars.jpg.webp', 'expect' => [800, null], 'type' => 'image/webp'],
            ['src' => 'mars.jpg', 'path' => '800x/mars.jpg.avif', 'expect' => [800, null], 'type' => 'image/avif'],
            ['src' => 'mars.jpg', 'path' => '800x/mars.jpg.png', 'expect' => [800, null], 'type' => 'image/png'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.jpeg.webp', 'expect' => [400, null], 'type' => 'image/webp'],
            ['src' => 'parrot.jpeg', 'path' => 'parrot.jpeg', 'expect' => [700, 893], 'type' => 'image/jpeg', 'note' => 'passthrough — no geometry, no conversion, the original is served'],
            ['src' => 'logo.png', 'path' => '400x/logo.png.webp', 'expect' => [400, 400], 'type' => 'image/webp', 'note' => 'alpha survives'],
            ['src' => 'logo.png', 'path' => '400x/logo.png.avif', 'expect' => [400, 400], 'note' => 'watch the reported type: WebP here means this build flattens alpha in AVIF'],
            ['src' => 'logo.png', 'path' => '400x/logo.png.jpg', 'expect' => [400, 400], 'type' => 'image/jpeg', 'note' => 'alpha is flattened, as it must be'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.jpeg.svg', 'kind' => 'refused', 'note' => 'not in the output allowlist — ImageMagick would happily have written it'],
        ],
    ],

    [
        'title' => 'Filters',
        'blurb' => 'All ten, on the same source at the same size. <code>darken-10</code> is here on purpose: '
            . 'the previous CLI backend built the literal argument <code>--10x0</code> for a negative amount, '
            . 'which ImageMagick rejects, so that URL was a 404 on one backend and a valid image on the other.',
        'cases' => [
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.jpeg', 'expect' => [400, null], 'note' => 'no filter — the baseline'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.modulate120,80,100.jpeg', 'expect' => [400, null], 'note' => 'brightness, saturation, hue'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.darken25.jpeg', 'expect' => [400, null]],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.darken-10.jpeg', 'expect' => [400, null], 'note' => 'negative amount — lightens'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.lighten25.jpeg', 'expect' => [400, null]],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.dim-25.jpeg', 'expect' => [400, null], 'note' => 'toward black'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.dim40.jpeg', 'expect' => [400, null], 'note' => 'toward white'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.brighten130.jpeg', 'expect' => [400, null]],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.grayscale.jpeg', 'expect' => [400, null]],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.grayscale30.jpeg', 'expect' => [400, null], 'note' => '30% saturation left'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.sepia.jpeg', 'expect' => [400, null], 'note' => 'percent on CLI, quantum units on imagick — one definition, converted'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.blur0,4.jpeg', 'expect' => [400, null]],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.softblur0,4.jpeg', 'expect' => [400, null], 'note' => 'edge-aware'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.vintage.jpeg', 'expect' => [400, null], 'note' => 'three ops in one filter'],
            ['src' => 'logo.png', 'path' => '400x/logo.grayscale.png.webp', 'expect' => [400, 400], 'note' => 'filter plus conversion, alpha intact'],
        ],
    ],

    [
        'title' => 'Canonicalisation',
        'blurb' => 'The URL is the cache key, so anything not already canonical is redirected rather than '
            . 'generated. Off-ladder geometry rounds <em>up</em> to the next rung; filter parameters are '
            . 'clamped, quantised and re-rendered. Without this, one image accumulates near-duplicate '
            . 'derivatives under a limitless set of names. The redirect is followed, so these rows show '
            . 'the image the canonical URL actually returns — and the last two must <em>not</em> redirect.',
        'cases' => [
            ['src' => 'parrot.jpeg', 'path' => '641x801/parrot.jpeg', 'kind' => 'redirect', 'to' => '/img/800x1000/parrot.jpeg', 'expect' => [700, 875], 'note' => 'nearest rung, rounded up on both axes'],
            ['src' => 'mars.jpg', 'path' => '4500x3000/mars.jpg', 'kind' => 'redirect', 'to' => '/img/3000x3000/mars.jpg', 'expect' => [2024, 2024], 'note' => 'above the top rung on both axes'],
            ['src' => 'parrot.jpeg', 'path' => '10x10/parrot.jpeg', 'kind' => 'redirect', 'to' => '/img/200x200/parrot.jpeg', 'expect' => [200, 200], 'note' => 'below the bottom rung'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.blur0,2.jpeg', 'kind' => 'redirect', 'to' => '/img/400x/parrot.blur.jpeg', 'expect' => [400, null], 'note' => 'parameters equal to the defaults are dropped'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.blur0,500.jpeg', 'kind' => 'redirect', 'to' => '/img/400x/parrot.blur0,20.jpeg', 'expect' => [400, null], 'note' => 'sigma clamped to 20 — an unbounded blur is a CPU denial of service. Compare the blur here with the row above'],
            ['src' => 'logo.png', 'path' => '1024x1024/logo.png', 'kind' => 'redirect', 'to' => '/img/logo.png', 'expect' => [512, 512], 'note' => 'two hops: off-ladder → 1200x1200, which then exceeds the 512×512 source with no format change, so it collapses onto the original itself'],
            ['src' => 'mars.jpg', 'path' => '600x600/mars.jpg', 'kind' => 'stable', 'expect' => [600, 600], 'note' => 'already canonical — this row must NOT redirect'],
            ['src' => 'parrot.jpeg', 'path' => '400x/parrot.dim-25.jpeg', 'kind' => 'stable', 'expect' => [400, null], 'note' => 'a filter token already in canonical form'],
        ],
    ],

    [
        'title' => 'Refusals',
        'blurb' => 'Traversal is checked per path segment, on both the raw and the percent-decoded form. '
            . 'The plain <code>../</code> forms are normalised away by the browser before the request is '
            . 'even sent, so the encoded ones are what actually reach the server.',
        'cases' => [
            ['path' => '%2e%2e%2f%2e%2e%2fsecret.jpg', 'kind' => 'refused', 'note' => 'percent-encoded traversal'],
            ['path' => '1044%2f..%2f..%2fsecret.jpg', 'kind' => 'refused', 'note' => 'encoded separators'],
            ['path' => 'notes.txt', 'kind' => 'refused', 'note' => 'the file exists — it is the grammar that refuses it'],
            ['path' => 'nonexistent.jpg', 'kind' => 'refused', 'note' => 'no such source'],
            ['path' => 'parrot.jpeg.exe', 'kind' => 'refused', 'note' => 'not an image extension'],
            ['path' => '400x/parrot.blur0,4.5.jpeg', 'kind' => 'refused', 'note' => 'a fractional parameter cannot be written: “.” separates filename segments, so this is read as a filename and no such file exists. Every filter step is integral for this reason'],
        ],
    ],

    [
        'title' => 'Cache collapse',
        'blurb' => 'When the requested box already covers the whole source, the geometry stops mattering — '
            . 'all that is left is the format change. Those requests share one file beside the original '
            . 'instead of one per rung. Open the cache listing below to see it.',
        'cases' => [
            ['src' => 'parrot.jpeg', 'path' => '2400x/parrot.jpeg.webp', 'expect' => [700, null], 'type' => 'image/webp'],
            ['src' => 'parrot.jpeg', 'path' => '3000x/parrot.jpeg.webp', 'expect' => [700, null], 'type' => 'image/webp', 'note' => 'same stored file as the row above'],
            ['src' => 'parrot.jpeg', 'path' => '2000x/parrot.jpeg.webp', 'expect' => [700, null], 'type' => 'image/webp', 'note' => 'and this one'],
        ],
    ],
];

$totalCases = array_sum(array_map(static fn (array $s): int => count($s['cases']), $sections));

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>atispro/img-pipeline — cases</title>
<style>
	:root {
		--bg: #12141a; --panel: #1a1d26; --line: #2b3040; --ink: #e6e8ef;
		--dim: #8d94a8; --ok: #43c078; --bad: #ef5b5b; --wait: #6b7285;
		--accent: #6ea8fe;
	}
	* { box-sizing: border-box; }
	body { margin: 0; background: var(--bg); color: var(--ink);
		font: 14px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
	code { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: .92em;
		background: #0d0f14; border: 1px solid var(--line); border-radius: 4px; padding: .08em .38em; }
	a { color: var(--accent); }

	header { padding: 28px 24px 18px; border-bottom: 1px solid var(--line); }
	h1 { margin: 0 0 6px; font-size: 21px; letter-spacing: -.01em; }
	.lede { margin: 0; color: var(--dim); max-width: 78ch; }

	.bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 18px; align-items: center;
		flex-wrap: wrap; padding: 11px 24px; background: #0f1117ee; backdrop-filter: blur(8px);
		border-bottom: 1px solid var(--line); font-variant-numeric: tabular-nums; }
	.tally b { font-size: 16px; }
	.tally.ok b { color: var(--ok); } .tally.bad b { color: var(--bad); } .tally.wait b { color: var(--wait); }
	.bar .spacer { flex: 1; }
	.bar a, .bar button { text-decoration: none; border: 1px solid var(--line); padding: 5px 11px;
		border-radius: 6px; background: none; color: var(--accent); font: inherit; cursor: pointer; }

	section { padding: 26px 24px 8px; border-bottom: 1px solid var(--line); }
	h2 { margin: 0 0 6px; font-size: 16px; }
	.blurb { margin: 0 0 18px; color: var(--dim); max-width: 84ch; }

	.grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(268px, 1fr)); }
	.card { background: var(--panel); border: 1px solid var(--line); border-radius: 9px;
		overflow: hidden; display: flex; flex-direction: column; }
	.card.bad { border-color: var(--bad); }
	.thumb { height: 190px; display: grid; place-items: center; padding: 10px; overflow: hidden;
		background: repeating-conic-gradient(#20232d 0 25%, #191c24 0 50%) 0 0 / 18px 18px; }
	.thumb img { max-width: 100%; max-height: 100%; display: block; }
	.thumb .none { color: var(--dim); font-size: 12px; text-align: center; }
	.meta { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 7px; }
	.url { word-break: break-all; font-family: ui-monospace, Consolas, monospace; font-size: 11.5px; color: #b9c0d4; }
	.row { display: flex; gap: 8px; align-items: baseline; justify-content: space-between; font-size: 12px; }
	.row .k { color: var(--dim); }
	.got { font-variant-numeric: tabular-nums; white-space: pre-line; text-align: right; }
	.note { font-size: 12px; color: var(--dim); }
	.badge { align-self: flex-start; font-size: 11px; font-weight: 600; letter-spacing: .04em;
		text-transform: uppercase; padding: 2px 7px; border-radius: 4px; }
	.badge.ok { background: #143d28; color: var(--ok); }
	.badge.bad { background: #401c1c; color: var(--bad); }
	.badge.wait { background: #23262f; color: var(--wait); }
	.badge.info { background: #1d2b40; color: var(--accent); }
	.why { font-size: 12px; color: var(--bad); }

	table.cache { border-collapse: collapse; font-size: 12.5px; width: 100%; max-width: 900px; }
	table.cache td { border-bottom: 1px solid var(--line); padding: 4px 10px 4px 0; }
	table.cache td.n { text-align: right; color: var(--dim); font-variant-numeric: tabular-nums; }
	.sources { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); }
	.src { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; }
	.src b { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }
	.src div { font-size: 12px; color: var(--dim); }
</style>
</head>
<body>

<header>
	<h1>atispro/img-pipeline — case matrix</h1>
	<p class="lede">
		Every row is a live request through the real endpoint. The browser reports what it actually
		received; the page checks that against what was expected. <b>No delivered image may ever exceed
		its source</b> — that invariant is checked on every image row, not only the ones that name it.
	</p>
</header>

<div class="bar">
	<span class="tally ok">✓ <b id="t-ok">0</b> pass</span>
	<span class="tally bad">✗ <b id="t-bad">0</b> fail</span>
	<span class="tally wait">… <b id="t-wait"><?= $totalCases ?></b> pending</span>
	<span class="spacer"></span>
	<a href="?cache=1">cache listing</a>
	<a href="/img/?debug=capabilities" target="_blank" rel="noopener">capabilities</a>
	<form method="post" style="display:inline" onsubmit="return confirm('Delete every generated derivative under demo/files?')">
		<input type="hidden" name="clear" value="confirm">
		<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
		<button type="submit">clear cache</button>
	</form>
</div>

<?php if (isset($_GET['cleared'])): ?>
<section><p class="blurb">Removed <?= (int) $_GET['cleared'] ?> generated files. Reload to regenerate.</p></section>
<?php endif; ?>

<?php if (isset($_GET['cache'])): ?>
<section>
	<h2>Cache listing — demo/files</h2>
	<p class="blurb">Originals and everything generated from them. Note how the oversized rungs share one file.</p>
	<table class="cache">
		<?php foreach (demo_cacheListing($filesDir) as $row): ?>
		<tr><td><?= htmlspecialchars($row['path']) ?></td><td class="n"><?= number_format($row['size']) ?> B</td></tr>
		<?php endforeach; ?>
	</table>
</section>
<?php endif; ?>

<section>
	<h2>Backend in use</h2>
	<p class="blurb">
		ext-imagick is optional. The common production shape is no PHP extension and ImageMagick
		installed as an ordinary package — every derivative below is produced that way when the
		extension is absent. Binaries are looked for in <code>imagemagickPath</code> first, then on
		<code>PATH</code>; the <code>PATH</code> entries are always appended, so a configured directory
		that no longer holds the binaries falls back instead of disabling the CLI backend.
	</p>
	<table class="cache">
		<tr><td>ext-imagick</td><td><?= $capabilities['imagick_loaded'] ? 'loaded' : 'not installed' ?></td></tr>
		<tr><td>selected backend</td><td><b><?= $capabilities['state']['imagick'] ? 'ext-imagick' : ($capabilities['state']['cli'] !== null ? 'ImageMagick CLI' : 'NONE — nothing will generate') ?></b></td></tr>
		<tr><td>resolved binary</td><td><code><?= htmlspecialchars((string) ($capabilities['state']['cli'] ?? '—')) ?></code></td></tr>
		<tr><td>imagemagickPath</td><td><code><?= $config->imagemagickPath === '' ? "'' (unset — PATH only)" : htmlspecialchars($config->imagemagickPath) ?></code></td></tr>
		<tr><td>AVIF can carry alpha</td><td><?= $capabilities['state']['avifAlpha'] ? 'yes' : 'no — the CLI backend substitutes WebP for transparent sources' ?></td></tr>
		<tr><td>proc_open / exec / shell_exec</td><td><?= implode(' / ', array_map(
			static fn (bool $b): string => $b ? 'yes' : 'no',
			[$capabilities['proc_open_exists'], $capabilities['exec_exists'], $capabilities['shell_exec_exists']],
		)) ?></td></tr>
	</table>

	<p class="blurb" style="margin-top:14px">Candidates, in the order they were tried:</p>
	<table class="cache">
		<tr><td><b>binary</b></td><td><b>from</b></td><td><b>exit</b></td><td><b>is ImageMagick</b></td><td><b>reported</b></td></tr>
		<?php foreach ($capabilities['cli_candidates'] as $candidate): ?>
		<tr>
			<td><code><?= htmlspecialchars($candidate['tried']) ?></code></td>
			<td><?= htmlspecialchars($candidate['source']) ?></td>
			<td class="n"><?= (int) $candidate['exit_code'] ?></td>
			<td><?= $candidate['is_imagemagick'] ? 'yes' : 'no' ?></td>
			<td><?= htmlspecialchars((string) ($candidate['reported'] ?? '—')) ?></td>
		</tr>
		<?php endforeach; ?>
	</table>
	<p class="note" style="margin-top:10px">
		A candidate is accepted only when it exits 0 <em>and</em> says it is ImageMagick. On Windows the
		<code>convert</code> on the default PATH is system32’s filesystem converter — exit status alone
		would have selected it, and every conversion after that would have failed.
	</p>
</section>

<section>
	<h2>Sources</h2>
	<p class="blurb">
		Two photographs from <code>samples/</code>, plus calibration images generated on first load —
		a grid, a magenta centre crosshair and four differently coloured corners, so a crop is readable
		rather than merely plausible.
	</p>
	<div class="sources">
		<?php foreach ($sources as $name => [$w, $h, $desc]): ?>
		<div class="src">
			<b><?= htmlspecialchars($name) ?></b>
			<div><?= $w ?> × <?= $h ?></div>
			<div><?= htmlspecialchars($desc) ?></div>
		</div>
		<?php endforeach; ?>
	</div>
</section>

<?php foreach ($sections as $section): ?>
<section>
	<h2><?= htmlspecialchars($section['title']) ?></h2>
	<p class="blurb"><?= $section['blurb'] ?></p>
	<div class="grid">
		<?php foreach ($section['cases'] as $case):
			$kind = $case['kind'] ?? 'image';
			$src = $case['src'] ?? null;
			$data = [
				'kind' => $kind,
				'url' => '/img/' . $case['path'],
				'to' => $case['to'] ?? null,
				'type' => $case['type'] ?? null,
				'expect' => $case['expect'] ?? null,
				'source' => $src !== null && isset($sources[$src]) ? [$sources[$src][0], $sources[$src][1]] : null,
			];
		?>
		<div class="card" data-case="<?= htmlspecialchars(json_encode($data, JSON_THROW_ON_ERROR)) ?>">
			<div class="thumb"><span class="none">…</span></div>
			<div class="meta">
				<div class="url"><?= htmlspecialchars($case['path']) ?></div>
				<div class="row"><span class="k">expected</span><span class="exp"></span></div>
				<div class="row"><span class="k">delivered</span><span class="got">…</span></div>
				<?php if ($src !== null && isset($sources[$src])): ?>
				<div class="row"><span class="k">source</span><span><?= $sources[$src][0] ?> × <?= $sources[$src][1] ?></span></div>
				<?php endif; ?>
				<span class="badge wait">pending</span>
				<div class="why"></div>
				<?php if (isset($case['note'])): ?><div class="note"><?= htmlspecialchars($case['note']) ?></div><?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
</section>
<?php endforeach; ?>

<section>
	<h2>Not covered here</h2>
	<p class="blurb">
		EXIF-rotated sources (<code>-auto-orient</code> runs, but GD cannot write the orientation tag to
		build a fixture), the imagick backend on this host if the extension is absent, and genuinely
		concurrent generation of one derivative — the lock is exercised single-process in
		<code>tests/Unit/StoreTest.php</code>, which is not the same thing as two workers racing.
	</p>
</section>

<script>
const cards = [...document.querySelectorAll('.card')]
const tally = { ok: 0, bad: 0, wait: cards.length }

const paint = () => {
	document.getElementById('t-ok').textContent = tally.ok
	document.getElementById('t-bad').textContent = tally.bad
	document.getElementById('t-wait').textContent = tally.wait
}

const settle = (card, pass, why) => {
	const badge = card.querySelector('.badge')
	tally.wait--
	if (pass === null) {
		badge.className = 'badge info'
		badge.textContent = 'informational'
	} else {
		badge.className = 'badge ' + (pass ? 'ok' : 'bad')
		badge.textContent = pass ? 'pass' : 'fail'
		pass ? tally.ok++ : tally.bad++
		if (!pass) card.classList.add('bad')
	}
	if (why) card.querySelector('.why').textContent = why
	paint()
}

const dims = (pair) => pair ? `${pair[0] ?? '—'} × ${pair[1] ?? '—'}` : '—'

async function runCase(card) {
	const c = JSON.parse(card.dataset.case)

	card.querySelector('.exp').textContent =
		c.kind === 'redirect' ? '301 → ' + c.to
		: c.kind === 'refused' ? '404'
		: c.kind === 'stable' ? '200, no redirect'
		: dims(c.expect) + (c.type ? '  ' + c.type : '')

	let res
	try {
		// cache: 'reload' forces a real round-trip. Responses carry
		// max-age=604800, so without it the browser answers from its own cache
		// and the page reports passes for derivatives that are no longer on
		// disk — which is exactly what happened while this page was being built.
		res = await fetch(c.url, { redirect: 'follow', cache: 'reload' })
	} catch (e) {
		return settle(card, false, 'request failed: ' + e.message)
	}

	const got = card.querySelector('.got')
	const problems = []

	if (c.kind === 'refused') {
		got.textContent = res.status + ' ' + res.statusText
		const reason = res.headers.get('X-Img-Error')
		return settle(card, res.status === 404, res.status === 404 ? reason ?? '' : 'expected 404, got ' + res.status)
	}

	// A redirect still ends at a real image, and a "must not redirect" row is a
	// plain 200 — so both record their verdict here and then carry on to the
	// decode below rather than stopping at the status line.
	const landed = new URL(res.url).pathname

	if (c.kind === 'redirect') {
		if (!res.redirected) problems.push('no redirect happened')
		else if (landed !== c.to) problems.push('landed on ' + landed)
	}
	if (c.kind === 'stable' && res.redirected) {
		problems.push('redirected to ' + landed)
	}

	if (!res.ok) {
		got.textContent = res.status + ' ' + res.statusText
		return settle(card, false, res.headers.get('X-Img-Error') ?? 'expected an image')
	}

	const type = res.headers.get('Content-Type') ?? '?'
	const blob = await res.blob()
	const url = URL.createObjectURL(blob)

	// Not loading="lazy": the element is detached, so it never enters the
	// viewport and the load would never start. Concurrency is handled by the
	// pool at the bottom instead.
	const img = new Image()
	const decoded = await new Promise((done) => {
		const timer = setTimeout(() => done(false), 15000)
		img.onload = () => { clearTimeout(timer); done(true) }
		img.onerror = () => { clearTimeout(timer); done(false) }
		img.src = url
	})

	const w = img.naturalWidth, h = img.naturalHeight
	if (!decoded || !w || !h) return settle(card, false, 'the browser could not decode the response')

	const thumb = card.querySelector('.thumb')
	thumb.textContent = ''
	thumb.append(img)

	got.textContent = (res.redirected ? '301 → ' + landed + '\n' : '')
		+ `${w} × ${h}   ${type}   ${(blob.size / 1024).toFixed(1)} KB`

	// The invariant, applied to every image row.
	if (c.source && (w > c.source[0] || h > c.source[1])) {
		problems.push(`upscaled beyond the ${c.source[0]}×${c.source[1]} source`)
	}
	if (c.expect) {
		if (c.expect[0] !== null && w !== c.expect[0]) problems.push(`width ${w}, expected ${c.expect[0]}`)
		if (c.expect[1] !== null && h !== c.expect[1]) problems.push(`height ${h}, expected ${c.expect[1]}`)
	}
	if (c.type && type.split(';')[0].trim() !== c.type) {
		problems.push(`type ${type}, expected ${c.type}`)
	}

	// Rows with nothing pinned are shown, not scored. Redirect and stable rows
	// always have a verdict, so they are never merely informational.
	if (c.kind === 'image' && !c.expect && !c.type && !c.source) return settle(card, null, '')

	settle(card, problems.length === 0, problems.join('; '))
}

// A small pool: the browser would otherwise ask for every derivative at once and
// each miss is a separate ImageMagick process.
;(async () => {
	const queue = [...cards]
	const worker = async () => { let card; while ((card = queue.shift())) await runCase(card) }
	await Promise.all(Array.from({ length: 4 }, worker))
})()

paint()
</script>
</body>
</html>
