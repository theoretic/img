# atispro/img-pipeline

On-demand image derivatives. Geometry, format and filters are encoded in the URL path; the first request generates the file, and every request after that is served statically by Apache.

**Contract:** the URL is the cache key. Anything that is not in canonical form is redirected to the URL that is, so one image never accumulates near-duplicate derivatives under different names.

## URL grammar

```
/site/assets/files/1044/photo.jpg                    serve as-is
/site/assets/files/1044/photo.jpg.avif               convert
/site/assets/files/1044/400x800/photo.jpg            resize to fill, focal-point crop
/site/assets/files/1044/400x/photo.jpg               width 400, source aspect kept
/site/assets/files/1044/x800/photo.jpg               height 800
/site/assets/files/1044/400x800f/photo.jpg           fit the box, no crop (legacy)
/site/assets/files/1044/400x/photo.dim-25.jpg.avif   filter, then convert
```

### Fit modes

| Geometry | Meaning |
|---|---|
| `400x800` | Resize to fill the box, then crop to exactly 400×800. The delivered aspect is the **box** aspect. |
| `400x800f` | Fit the box with the **source** aspect kept, nothing cropped — exactly the pixels an `object-fit: contain` display of that box shows. Legacy request form: answered with a 302 to the single-axis canonical (below). |

`f` exists for clients that know their box but not the source image, so they cannot tell which axis the contain display binds. The server has `getimagesize()`, so it decides:

- `boxAspect >= srcAspect` → the display pillarboxes, **height** binds
- `boxAspect  < srcAspect` → the display letterboxes, **width** binds

An f request stores nothing under its own name. The delivered image is uncropped, so one axis fully determines it — the free axis would only multiply cache keys over identical bytes. The pipeline answers with a **302** to the single-axis canonical form (`400x800f` against a landscape source → `400x`), which generates and serves as usual; `f` is a request form, never a storage form. 302 rather than 301 because replacing the source can flip the binding axis; the redirect self-heals within its max-age 3600.

Current adaptive-media builds do not emit `f` at all: they resolve the binding axis client-side (from `data-src-aspect` or the learned-ratio cache) and ask single-axis directly, falling back to plain width-only — always sufficient for a contain display — when the aspect is unknown. The grammar keeps parsing `WxHf` so old URLs in cached HTML resolve forever.

**History.** Until 24.08.26 `f` meant cover-the-box-without-crop: the *max* axis, guaranteeing the image spanned the box on both axes. Its only consumer displayed the result with `object-fit: contain`, which shows the *min* axis — the guarantee over-delivered by up to the square of the aspect mismatch (a 380×800 box over a 4:1 source fetched ~64× the displayed pixels). The under-fill defect that motivated it (that same box against an 800×200 source getting 400×100) was really a cover expectation, which the crop mode serves properly, focal point included. f-shaped files generated before the change remain on disk and are served statically until `atispro-img clear`.

Requests without the suffix behave exactly as they always have, so existing URLs across the fleet are unaffected.

### Filters

`modulate`, `darken`, `lighten`, `dim`, `brighten`, `grayscale`, `sepia`, `blur`, `softblur`, `vintage`.

Parameters follow the name: `blur2,4`, `dim-25`, `darken10`. A segment is only read as a filter when the name is registered, so a real filename such as `photo.landscape.jpg` is left alone. Parameters are clamped to a declared range and quantised, then the token is re-rendered canonically — `blur0,2` and a bare `blur` are one cache entry, not two.

Parameters are whole numbers, and every step in the registry is integral. They cannot be otherwise: `.` separates filename segments, so `photo.blur0,4.5.jpg` splits into four parts and the filter is gone before it can be parsed.

## Configuration

`Atispro\Img\Config::defaults()` holds every key and its default. A site overrides only what differs, in `img.config.php`.

| Key | Default | Notes |
|---|---|---|
| `imagesPath` | — | Required. Holds originals and derivatives. |
| `publicBase` | `/site/assets/files` | URL prefix that maps onto `imagesPath`. |
| `widths` / `heights` | 200…3000, identical | The allowed geometry rungs — one ladder for both axes. Must match `sizes` in `@atispro/core` `adaptive-media.js`. |
| `geometryPolicy` | `redirect` | `redirect` \| `strict` \| `off` — see below. |
| `widthMax` / `heightMax` | 3000 / 3000 | Hard ceilings. |
| `maxPixels` | 50 M | Decompression-bomb guard. |
| `formats` | jpeg, jpg, png, webp, avif, heic, heif | Also the output allowlist. Serving an existing upload as-is is exempt, so a GIF/TIFF/BMP original stays servable; transforming one is refused. |
| `filters` | all registered | Filter names URLs may invoke. Every parameter combination is a cache entry, so a site should list only what it uses (`[]` disables filters). |
| `derivativeCap` | 100 | Backstop: max derivatives per source per directory; 0 disables. |
| `sharpen` | radius 0, sigma 0.7 | Applied after resize and crop. |
| `limits` | area, memory, map, disk, time | Passed to `-limit` / `setResourceLimit`. `disk` matters: without it, exceeding memory/map spills the pixel cache to disk unbounded. |
| `externalEncoders` | none | Per-format external encoder, gated on the source format. |
| `imagemagickPath` | Windows dev path, else unset | Optional. Unset means "find it on `PATH`", which is what most hosts want. |
| `processor` | `auto` | `auto` \| `imagick` \| `cli`. |
| `capabilitiesCacheTtl` | 3600 | How long a successful backend probe is trusted. |
| `negativeCacheTtl` | 60 | How long a probe that found *nothing* is trusted. Short, so a host recovers quickly once a binary appears — but not zero, because each failed candidate costs a process spawn. |
| `debug` | `false` | Enables `?debug=capabilities` on direct hits to `index.php`. |

### geometryPolicy

`redirect` answers an off-ladder geometry with a 301 to the nearest rung, rounded up. That bounds the derivative cache without breaking clients that still emit arbitrary sizes — they refetch the canonical URL and everything lands in one place. Move a site to `strict` once its `adaptive-media` build is known to snap to the same ladders; `off` restores the legacy behaviour and an unbounded key space.

## Installing

```bash
composer require atispro/img-pipeline
```

Then copy the drop-in kit into the site:

| From | To |
|---|---|
| `site-stub/index.php` | `webroot/api/img/index.php` |
| `site-stub/img.config.php` | `webroot/api/img/img.config.php` |
| `site-stub/.htaccess` | `webroot/api/img/.htaccess` |
| `site-stub/assets.htaccess` | `webroot/site/assets/files/.htaccess` |

Both `.htaccess` files matter. `assets.htaccess` denies the pipeline's own state
(`.capabilities.json` reports the absolute path of your ImageMagick binary), and
both rewrites carry `[B,QSD]` so a stray `&` in a filename cannot split into
extra `$_GET` parameters. Neither rule can be exercised by the demo server —
`php -S` does not read `.htaccess` — so verify them against real Apache:

```bash
curl -so /dev/null -w '%{http_code}\n' https://SITE/site/assets/files/.capabilities.json
```

## Migrating from a per-site install

1. Delete `webroot/api/img/_include/` and the old `index.php` / `batch.php` / `.batch/`.
2. Delete `webroot/site/assets/files/rmthumbs.php`. Cache clearing is `atispro-img clear`, and it deliberately has no web route — the previous arrangement granted `Require all granted` on that file, which put a full cache wipe and the CPU storm of regenerating everything behind a plain URL.
3. Check what changes before it changes:
   ```bash
   vendor/bin/atispro-img diff-legacy --limit=200
   ```
   It is read-only. It predicts what the new grammar produces for each existing derivative and compares it against what is on disk. `resize` rows are expected wherever the old independent per-axis clamp distorted the aspect ratio; `gone` rows are the only class that breaks a live page.
4. Clear and let the cache rebuild:
   ```bash
   vendor/bin/atispro-img clear --dry-run
   vendor/bin/atispro-img clear
   ```

## Backends

`auto` prefers ext-imagick and falls back to the ImageMagick CLI. A runtime failure in the extension demotes it for the rest of the capability TTL and retries on the CLI, so a broken build degrades instead of 404ing.

**ext-imagick is not required.** The usual production shape — no PHP extension, ImageMagick installed as a normal package — needs no configuration at all: leave `imagemagickPath` unset and the binaries are found on `PATH`.

Binaries are resolved in this order, and every step is a fallback rather than a replacement:

1. `magick` then `convert` inside `imagemagickPath`, when one is configured
2. `magick` then `convert` on `PATH`

A configured directory that no longer holds the binaries — stale after an upgrade, or a config copied between hosts — therefore degrades to `PATH` instead of taking the CLI backend down with it.

A candidate is accepted only when `-version` exits 0 **and** the output identifies itself as ImageMagick. Exit status alone is not proof: on Windows the `convert` on the default `PATH` is system32's filesystem conversion tool, and a host that selected it would have failed every conversion afterwards. IM6 ships only `convert`, which is why both names are tried rather than assuming `magick`.

Execution falls back `proc_open` → `exec` → `shell_exec`, so shared hosts that disable one of them still work.

```bash
vendor/bin/atispro-img capabilities
```

Both backends consume one filter definition, which carries an `imagick` call and a `cli` argv fragment side by side, so they cannot drift apart. Where they cannot help but differ — some builds silently flatten alpha when writing AVIF — the CLI backend detects it at probe time and writes WebP into the same file instead. `Content-Type` comes from the file's magic bytes rather than its extension, so that substitution is invisible to the browser.

## Development

```bash
composer install
composer test    # phpunit, unit + integration
composer stan    # phpstan level 6
composer demo    # php -S localhost:8080, then open http://localhost:8080/
```

The demo is a live case matrix: every fit mode against every source shape, the no-upscale edge cases, format conversions, all ten filters, the canonicalisation redirects and the refusals. Each row is a real request through the real endpoint, and the page checks what the browser actually received against what was expected — including the invariant that **no delivered image may ever exceed its source**, which is asserted on every image row rather than only the ones that name it. Sources are the two photographs in `samples/` plus calibration images generated on first load, gridded and with four differently coloured corners so a crop is readable rather than merely plausible.

The integration suite runs a golden table of URLs against **every** backend the host has and asserts they agree. That cross-backend comparison is the point: the defects that survived longest in the previous implementations — sepia taking percent on one side and quantum units on the other, a negative filter parameter that 404'd on the CLI and rendered fine on imagick — are all invisible when only one backend is exercised. On a host with just one backend installed, those rows still run but the comparison is trivially satisfied.

## What is protected, and what is reserved

The pipeline only ever deletes or overwrites a file when it can find the source
that file would have been generated from. `screenshot.png.jpg` with no
`screenshot.png` beside it is an upload, and both `clear` and the writer leave it
alone; a directory called `2x4` is content, not a geometry cache.

Two namespaces are reserved. `name.<imgext>.<imgext>` sitting beside
`name.<imgext>` is a conversion, and is indistinguishable from one. And a
content directory named like an on-ladder geometry (`400x`, `600x450`) is
indistinguishable from a cache directory: an upload inside one, with a
same-named file in the parent, reads as that file's derivative and can be
overwritten by it. Do not use either shape for real content.

## Cache invalidation

`Pipeline::VERSION` and `Config::hash()` are written to a stamp file in `imagesPath`. A derivative older than that stamp is regenerated on next request, so changing the encoder settings or the sharpen parameters invalidates existing files without anyone having to remember to clear them. Code changes — the filter registry included — are covered only by a `Pipeline::VERSION` bump; the config hash cannot see them.

Editing an original in place also invalidates everything made from it: a derivative older than its own source is rebuilt. Mtimes have one-second granularity, so an edit landing in the very same second as the build is the one gap — the next edit closes it.

AT
15.08.26
