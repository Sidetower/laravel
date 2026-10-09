<?php

declare(strict_types=1);

/*
 * Copies the JSON Schema files and the conformance fixtures of the Sidetower protocol into this repository:
 *
 *     composer protocol:sync -- /path/to/sidetower/packages/protocol
 *
 * The copies live in tests/Protocol/schema/v1 and tests/Protocol/fixtures/v1 and are committed, so the tests need no checkout of
 * the panel repository. Files that no longer exist at the source are removed.
 */

$source = $argv[1] ?? null;

if ($source === null || $source === '' || ! is_dir("{$source}/schema/v1") || ! is_dir("{$source}/fixtures/v1")) {
    fwrite(STDERR, "Usage: composer protocol:sync -- <path to packages/protocol of the panel repository>\n");
    fwrite(STDERR, "The path must contain schema/v1 and fixtures/v1.\n");

    exit(1);
}

$source = rtrim($source, '/\\');
$target = __DIR__;

/**
 * @return list<string> the paths of the .json files below $directory, relative to it, sorted
 */
function jsonFilesBelow(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $files[] = substr($file->getPathname(), strlen($directory) + 1);
        }
    }

    sort($files);

    return $files;
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($directory);
}

foreach (['schema/v1', 'fixtures/v1'] as $directory) {
    $files = jsonFilesBelow("{$source}/{$directory}");

    if ($files === []) {
        fwrite(STDERR, "No .json files in {$source}/{$directory}; nothing copied.\n");

        exit(1);
    }

    removeDirectory("{$target}/{$directory}");

    foreach ($files as $file) {
        $destination = "{$target}/{$directory}/{$file}";

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0777, true);
        }

        copy("{$source}/{$directory}/{$file}", $destination);
    }

    fwrite(STDOUT, sprintf("%s: %d files\n", $directory, count($files)));
}
