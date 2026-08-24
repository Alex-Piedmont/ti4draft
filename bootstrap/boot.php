<?php

$projectRoot = dirname(__DIR__);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    http_response_code(500);
    die("<h2>No vendor directory found. Try running composer install.</h2>");
}

require_once $autoloadPath;

function failBootstrap(string $message): never
{
    http_response_code(500);
    die("<h2>{$message}</h2>");
}


try {
    $dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
    $dotenv->load();
} catch (Exception $e) {
    // if it was set some other way (like in production) then that's ok.
    if (public_url() === null) {
        failBootstrap('No .env file found. See .env.example or the readme for details');
    }
}

if (public_url() === null) {
    failBootstrap('.env file is incomplete: no valid URL or RAILWAY_PUBLIC_DOMAIN set.');
}

$storage = env('STORAGE');
if ($storage === null) {
    failBootstrap('.env file is incomplete: no STORAGE set.');
}

if ($storage === 'local') {
    $storagePath = env('STORAGE_PATH');
    if ($storagePath === null) {
        failBootstrap('.env file is incomplete: if STORAGE is local, then STORAGE_PATH is required.');
    }

    if (!is_dir($storagePath) || !is_writable($storagePath)) {
        failBootstrap('STORAGE_PATH does not exist or is not writeable.');
    }
}
