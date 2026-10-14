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
     * @throws DriverException               The ".git" file of $dir can't be read, or doesn't point to a git directory
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
            // In a linked worktree, a submodule or a clone with --separate-git-dir, ".git" is a file pointing to the git directory.
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
     * Reads the file like git: "gitdir: " then the path, absolute or relative
     * to $dir, without the line ending, "\r\n" included. The path may end with
     * a space.
     *
     * @return string The git directory the ".git" file of $dir points to
     *
     * @throws DriverException The file can't be read, or doesn't point to a git directory
     */
    private static function readGitFile(string $dir): string
    {
        $content = @file_get_contents($dir.'/.git');
        if (false === $content) {
            throw new DriverException(\sprintf('Unable to read the "%s/.git" file.', $dir));
        }

        $path = rtrim(substr($content, \strlen('gitdir: ')), "\r\n");
        // realpath() refuses a NUL byte, where git would read the path up to it.
        if (!str_starts_with($content, 'gitdir: ') || '' === $path || str_contains($path, "\0")) {
            throw new DriverException(\sprintf('The "%s/.git" file doesn\'t point to a git directory.', $dir));
        }

        $gitDir = str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) ? $path : $dir.'/'.$path;

        return realpath($gitDir) ?: throw new DriverException(\sprintf('The git directory "%s" of "%s" does not exist.', $path, $dir));
    }
}
