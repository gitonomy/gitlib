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

namespace Gitonomy\Git;

use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\RuntimeException;

/**
 * Hooks collection, aggregated by repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final readonly class Hooks
{
    public function __construct(
        private Repository $repository,
    ) {
    }

    /**
     * Tests if repository has a given hook.
     *
     * @param string $name Name of the hook
     */
    public function has(string $name): bool
    {
        return file_exists($this->getPath($name));
    }

    /**
     * Fetches content of a hook.
     *
     * @param string $name Name of the hook
     *
     * @return string Content of the hook
     *
     * @throws InvalidArgumentException The hook does not exist
     * @throws RuntimeException         The hook can't be read
     */
    public function get(string $name): string
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException(\sprintf('The hook "%s" does not exist.', $name));
        }

        $content = file_get_contents($this->getPath($name));
        if (false === $content) {
            throw new RuntimeException(\sprintf('Unable to read the hook "%s".', $name));
        }

        return $content;
    }

    /**
     * Insert a hook in repository using a symlink.
     *
     * @param string $name Name of the hook to insert
     * @param string $file Target of symlink
     *
     * @throws RuntimeException The hook exists already, or can't be created
     */
    public function setSymlink(string $name, string $file): void
    {
        $path = $this->createPath($name);

        if (false === @symlink($file, $path)) {
            throw new RuntimeException(\sprintf('Unable to create the hook "%s" (%s).', $name, $path));
        }
    }

    /**
     * Set a hook in repository.
     *
     * @param string $name    Name of the hook
     * @param string $content Content of the hook
     *
     * @throws RuntimeException The hook exists already, or can't be created
     */
    public function set(string $name, string $content): void
    {
        $path = $this->createPath($name);

        if (false === @file_put_contents($path, $content) || false === @chmod($path, 0o700)) {
            throw new RuntimeException(\sprintf('Unable to create the hook "%s" (%s).', $name, $path));
        }
    }

    /**
     * Removes a hook from repository.
     *
     * @param string $name Name of the hook
     *
     * @throws RuntimeException The hook doesn't exist, or can't be removed
     */
    public function remove(string $name): void
    {
        if (!$this->has($name)) {
            throw new RuntimeException(\sprintf('The hook "%s" does not exist.', $name));
        }

        if (false === @unlink($this->getPath($name))) {
            throw new RuntimeException(\sprintf('Unable to remove the hook "%s".', $name));
        }
    }

    /**
     * @throws RuntimeException The hook exists already, or the repository has no hooks directory
     */
    private function createPath(string $name): string
    {
        if ($this->has($name)) {
            throw new RuntimeException(\sprintf('The hook "%s" exists already.', $name));
        }

        // A repository created with an empty template has none.
        $directory = $this->getDirectory();
        if (!is_dir($directory) && !@mkdir($directory) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Unable to create the hooks directory "%s".', $directory));
        }

        return $this->getPath($name);
    }

    private function getPath(string $name): string
    {
        return $this->getDirectory().'/'.$name;
    }

    /**
     * @throws RuntimeException The "commondir" file of a linked worktree can't be read
     */
    private function getDirectory(): string
    {
        $gitDir = $this->repository->getGitDir();
        // Like git, a linked worktree runs the hooks of the repository it belongs to.
        if (!is_file($gitDir.'/commondir')) {
            return $gitDir.'/hooks';
        }

        $commonDir = file_get_contents($gitDir.'/commondir');
        if (false === $commonDir) {
            throw new RuntimeException(\sprintf('Unable to read "%s/commondir".', $gitDir));
        }

        $commonDir = rtrim($commonDir, "\r\n");

        return (preg_match('#^(/|[a-zA-Z]:[\\\/])#', $commonDir) ? $commonDir : $gitDir.'/'.$commonDir).'/hooks';
    }
}
