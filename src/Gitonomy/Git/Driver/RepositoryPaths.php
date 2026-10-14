<?php

/*
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Driver;

use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Util\NulByte;

/**
 * The git directory and the working tree of a repository, found the same way by every driver.
 *
 * @internal
 */
final readonly class RepositoryPaths
{
    private function __construct(
        public string $gitDir,
        public ?string $workingDir,
    ) {
    }

    /**
     * @param string      $dir        A bare repository, a working tree, or a git directory when $workingDir is given
     * @param string|null $workingDir The working tree of the git directory $dir
     *
     * @throws InvalidArgumentException      $dir doesn't exist, or isn't a repository, or a path contains a NUL byte
     * @throws DriverException               The ".git" file of $dir doesn't point to a git directory
     * @throws UnsupportedOperationException The repository uses SHA-256 hashes
     */
    public static function resolve(string $dir, ?string $workingDir): self
    {
        NulByte::assertNone($dir, $workingDir ?? '');

        $realDir = realpath($dir);
        if (false === $realDir || !is_dir($realDir)) {
            throw new InvalidArgumentException(\sprintf('Directory "%s" does not exist or is not a directory.', $dir));
        }

        $paths = match (true) {
            null !== $workingDir => new self($realDir, $workingDir),
            is_dir($realDir.'/.git') => new self($realDir.'/.git', $realDir),
            // In a linked worktree or a submodule, ".git" is a file pointing to the git directory.
            is_file($realDir.'/.git') => new self(self::readGitFile($realDir), $realDir),
            default => new self($realDir, null),
        };

        // Like git, which requires it in any git directory.
        if (!is_file($paths->gitDir.'/HEAD')) {
            throw new InvalidArgumentException(\sprintf('Directory "%s" is not a git repository.', $dir));
        }

        $config = is_file($paths->gitDir.'/config') ? file_get_contents($paths->gitDir.'/config') : false;
        if (false !== $config && preg_match('/^\s*objectformat\s*=\s*sha256\s*$/mi', $config)) {
            throw new UnsupportedOperationException(\sprintf('The repository "%s" uses SHA-256 hashes, which gitlib doesn\'t support yet.', $dir));
        }

        return $paths;
    }

    /**
     * @return string The git directory the ".git" file of $dir points to
     */
    private static function readGitFile(string $dir): string
    {
        $content = file_get_contents($dir.'/.git');
        if (false === $content || !preg_match('/^gitdir: (.+)$/m', $content, $match)) {
            throw new DriverException(\sprintf('The "%s/.git" file doesn\'t point to a git directory.', $dir));
        }

        $gitDir = str_starts_with($match[1], '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $match[1]) ? $match[1] : $dir.'/'.$match[1];

        return realpath($gitDir) ?: throw new DriverException(\sprintf('The git directory "%s" of "%s" does not exist.', $match[1], $dir));
    }
}
