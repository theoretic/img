<?php

declare(strict_types=1);

use Atispro\Img\Config;
use Atispro\Img\Exception\BusyException;
use Atispro\Img\Exception\ImgException;
use Atispro\Img\Http\Response;
use Atispro\Img\Pipeline;
use Atispro\Img\Process\Capabilities;
use Atispro\Img\Result;

require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

// A config mistake used to escape as an uncaught fatal — with display_errors on,
// that dumps the absolute imagesPath and a stack trace to the client.
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

// Diagnostics. Only reachable by hitting index.php directly — the rewrite never
// forwards a query string — and only when the site has opted in, because the
// payload reports disable_functions and absolute paths.
if ($config->debug && ($_GET['debug'] ?? null) === 'capabilities') {
    header('Content-Type: application/json');
    // SUBSTITUTE, because a shell's failure text arrives in the system codepage
    // and json_encode() returns false outright on malformed UTF-8 — answering a
    // diagnostic request with an empty body.
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
} catch (BusyException $e) {
    error_log('[atispro-img] ' . $e->getMessage());
    Response::busy();
    exit;
} catch (ImgException $e) {
    error_log('[atispro-img] ' . $e->getMessage());
    Response::notFound();
    exit;
} catch (\Throwable $e) {
    // Anything the pipeline's own exception tree does not cover — a TypeError,
    // an Error from a broken extension. Same rule as above: log the detail,
    // send nothing but the status.
    error_log('[atispro-img] unexpected: ' . $e->getMessage());
    Response::serverError();
    exit;
}

if ($result->isRedirect()) {
    Response::redirect($result->path, $result->permanent);
    exit;
}

Response::serveFile($result->path);
