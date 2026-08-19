<?php
// Dev-only: emulates the .htaccess internal rewrite for PHP's built-in server,
// which has no mod_rewrite. Never shipped in the ZIP.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$root = $_SERVER['DOCUMENT_ROOT'];
$file = realpath($root . $path);
if ($file && is_file($file) && strncmp($file, realpath($root), strlen(realpath($root))) === 0) {
    return false; // serve the real file
}
$_SERVER['TEB_REWRITE'] = '1';
require $root . '/index.php';
