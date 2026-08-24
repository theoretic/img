<?php

/**
 * The demo's image endpoint.
 *
 * Deliberately line-for-line the same as site-stub/index.php apart from where
 * the autoloader and the config come from — so what the demo exercises is what
 * a site would run.
 */

declare(strict_types=1);

use Atispro\Img\Config;
use Atispro\Img\Exception\BusyException;
use Atispro\Img\Exception\ImgException;
use Atispro\Img\Http\Response;
use Atispro\Img\Pipeline;
use Atispro\Img\Process\Capabilities;
use Atispro\Img\Result;

require __DIR__ . '/../vendor/autoload.php';

try {
    $config = Config::fromArray(require __DIR__ . '/img.config.php');
} catch (\Throwable $e) {
    error_log('[atispro-img] config: ' . $e->getMessage());
    Response::serverError();
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    Response::methodNotAllowed();
    exit;
}

if ($config->debug && ($_GET['debug'] ?? null) === 'capabilities') {
    header('Content-Type: application/json');
    echo json_encode(
        Capabilities::diagnose($config),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    exit;
}

$request = (string) ($_GET['request'] ?? '');
if ($request === '') {
    Response::notFound();
    exit;
}

try {
    $result = (new Pipeline($config))->handle($request);
} catch (ImgException $e) {
    error_log('[atispro-img] ' . $e->getMessage());

    // Only with debug on: these messages carry absolute filesystem paths and
    // raw ImageMagick stderr, which is not something to hand to a client.
    if ($config->debug) {
        header('X-Img-Error: ' . str_replace(["\r", "\n"], ' ', $e->getMessage()));
    }

    $e instanceof BusyException ? Response::busy() : Response::notFound();
    exit;
} catch (\Throwable $e) {
    error_log('[atispro-img] unexpected: ' . $e->getMessage());

    if ($config->debug) {
        header('X-Img-Error: ' . str_replace(["\r", "\n"], ' ', $e->getMessage()));
    }

    Response::serverError();
    exit;
}

if ($result->isRedirect()) {
    Response::redirect($result->path, $result->permanent);
    exit;
}

Response::serveFile($result->path);
