<?php
/**
 * Shared bootstrap: loads config.php and enforces the access-key gate.
 */

declare(strict_types=1);

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $file = __DIR__ . '/config.php';

        if (!is_file($file)) {
            http_response_code(503);
            exit('Not installed yet - open install.php in your browser.');
        }

        $config = require $file;
    }

    return $config;
}

function require_access(): void
{
    $config = app_config();
    $key = (string)($config['access_key'] ?? '');

    session_start();

    $provided = $_GET['key'] ?? null;
    if ($provided !== null && $provided !== '' && $key !== '' && hash_equals($key, (string)$provided)) {
        $_SESSION['ok'] = true;
    }

    if (($_SESSION['ok'] ?? false) !== true) {
        http_response_code(404);
        exit;
    }
}
