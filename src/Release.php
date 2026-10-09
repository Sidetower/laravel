<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

/**
 * Which version of the application runs: the `release` setting, else the commit that `.git` or a `releases/<sha>` directory
 * (Envoyer, Deployer, Capistrano) names. Only reads small files; no `git` process.
 */
class Release
{
    /**
     * What identifies the release in items: the setting, else the 7-character commit. Null when neither is known.
     */
    public function name(): ?string
    {
        $configured = config('sidetower.release');

        if (is_scalar($configured) && trim((string) $configured) !== '') {
            return mb_substr(trim((string) $configured), 0, 100);
        }

        return $this->shortCommit();
    }

    public function shortCommit(): ?string
    {
        $commit = $this->commit();

        return $commit === null ? null : substr($commit, 0, 7);
    }

    /**
     * The full 40-character commit, if it can be read.
     */
    public function commit(): ?string
    {
        return $this->commitFromGit() ?? $this->commitFromReleaseDirectory();
    }

    /**
     * The branch `.git/HEAD` points at; null when HEAD is detached or there is no `.git`.
     */
    public function branch(): ?string
    {
        $ref = $this->headRef();

        if ($ref === null || ! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return mb_substr(substr($ref, strlen('refs/heads/')), 0, 100);
    }

    protected function commitFromGit(): ?string
    {
        $head = $this->readFile(base_path('.git/HEAD'));

        if ($head === null) {
            return null;
        }

        $head = trim($head);

        if ($this->isSha($head)) {
            return $head;
        }

        $ref = $this->headRef();

        if ($ref === null) {
            return null;
        }

        $loose = trim((string) $this->readFile(base_path('.git/'.$ref)));

        if ($this->isSha($loose)) {
            return $loose;
        }

        $packed = $this->readFile(base_path('.git/packed-refs'));

        if ($packed !== null && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The ref HEAD points at (`refs/heads/main`), if it is one that is safe to turn into a path.
     */
    protected function headRef(): ?string
    {
        $head = trim((string) $this->readFile(base_path('.git/HEAD')));

        if (! str_starts_with($head, 'ref: ')) {
            return null;
        }

        $ref = trim(substr($head, 5));

        if (preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) !== 1 || str_contains($ref, '..')) {
            return null;
        }

        return $ref;
    }

    protected function commitFromReleaseDirectory(): ?string
    {
        $path = realpath(base_path());

        if ($path === false || ! $this->isSha(basename($path)) || basename(dirname($path)) !== 'releases') {
            return null;
        }

        return basename($path);
    }

    protected function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }

    protected function readFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
