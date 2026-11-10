<?php

/**
 * Build the compiled Vite assets — but only when they are missing.
 *
 * The assets are committed to the repository (public/build), so a fresh clone
 * can serve every page without a Node toolchain: that is what the fresh-clone
 * smoke proves, and what a server install needs. When the assets really do
 * have to be built, `npm install` runs WITH its install scripts: the
 * bundler's native binaries (rolldown, the Tailwind oxide engine) are
 * fetched by a postinstall script, and `--ignore-scripts` leaves the build
 * without them — which is how the pipeline's fresh-clone job failed while
 * the same command passed on a Windows workstation.
 */

$root = dirname(__DIR__);
$manifest = $root.'/public/build/manifest.json';

if (is_file($manifest)) {
    fwrite(STDOUT, "public/build/manifest.json is present — the compiled assets ship with the repository; nothing to build.\n");

    exit(0);
}

$npm = 'npm';

fwrite(STDOUT, "public/build is missing — installing and building the assets...\n");

passthru($npm.' install', $status);

if ($status !== 0) {
    fwrite(STDERR, "npm install failed (exit {$status}).\n");

    exit($status);
}

passthru($npm.' run build', $status);

if ($status !== 0) {
    fwrite(STDERR, "npm run build failed (exit {$status}).\n");

    exit($status);
}

exit(0);
