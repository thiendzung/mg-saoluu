<?php
/** Regression test for the source-to-package SHA-256 manifest boundary. */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "FAIL CLI only\n"); exit(1); }

$root = dirname(__DIR__);
$path = $root . '/integrity.json';
$manifest = json_decode((string) file_get_contents($path), true);
if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'mg-saoluu-integrity' || !is_array($manifest['files'] ?? null)) {
    fwrite(STDERR, "FAIL invalid integrity manifest\n");
    exit(1);
}

$checked = 0;
foreach ($manifest['files'] as $relative => $expected) {
    $file = $root . '/' . $relative;
    if (!is_file($file) || !preg_match('/^[a-f0-9]{64}$/i', (string) $expected)) {
        fwrite(STDERR, "FAIL missing or invalid entry: {$relative}\n");
        exit(1);
    }
    $actual = hash_file('sha256', $file);
    if (!hash_equals(strtolower((string) $expected), (string) $actual)) {
        fwrite(STDERR, "FAIL manifest mismatch: {$relative}\n");
        exit(1);
    }
    $checked++;
}

echo "PASS integrity manifest matches {$checked} source files\n";
