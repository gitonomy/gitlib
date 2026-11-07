<?php

/**
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
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\RuntimeException;

/**
 * Hooks collection, aggregated by repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class Hooks
{
    /**
     * @var \Gitonomy\Git\Repository
     */
    protected $repository;

    /**
     * @var Repository
     */
    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Tests if repository has a given hook.
     *
     * @param string $name Name of the hook
     *
     * @throws InvalidArgumentException The name leads out of the hooks directory
     *
     * @return bool
     */
    public function has($name)
    {
        $path = $this->getPath($name);

        // A symbolic link to a file not deployed yet is a hook too: set()
        // would write through it, out of the hooks directory.
        return is_link($path) || file_exists($path);
    }

    /**
     * Fetches content of a hook.
     *
     * @param string $name Name of the hook
     *
     * @throws InvalidArgumentException Hook does not exist, or the name leads out of the hooks directory
     *
     * @return string Content of the hook
     */
    public function get($name)
    {
        if (!$this->has($name) || !file_exists($this->getPath($name))) {
            throw new InvalidArgumentException(sprintf('Hook named "%s" is not present', $name));
        }

        return file_get_contents($this->getPath($name));
    }

    /**
     * Insert a hook in repository using a symlink.
     *
     * @param string $name Name of the hook to insert
     * @param string $file Target of symlink
     *
     * @throws InvalidArgumentException The name leads out of the hooks directory
     * @throws LogicException           Hook is already present
     * @throws RuntimeException         Error on symlink creation
     */
    public function setSymlink($name, $file)
    {
        if ($this->has($name)) {
            throw new LogicException(sprintf('A hook "%s" is already defined', $name));
        }

        $path = $this->getPath($name);
        if (false === symlink($file, $path)) {
            throw new RuntimeException(sprintf('Unable to create hook "%s" (%s)', $name, $path));
        }
    }

    /**
     * Set a hook in repository.
     *
     * @param string $name    Name of the hook
     * @param string $content Content of the hook
     *
     * @throws InvalidArgumentException The name leads out of the hooks directory
     * @throws LogicException           The hook is already defined
     */
    public function set($name, $content)
    {
        if ($this->has($name)) {
            throw new LogicException(sprintf('A hook "%s" is already defined', $name));
        }

        $path = $this->getPath($name);
        file_put_contents($path, $content);
        chmod($path, 0777);
    }

    /**
     * Removes a hook from repository.
     *
     * @param string $name Name of the hook
     *
     * @throws InvalidArgumentException The name leads out of the hooks directory
     * @throws LogicException           The hook is not present
     */
    public function remove($name)
    {
        if (!$this->has($name)) {
            throw new LogicException(sprintf('The hook "%s" was not found', $name));
        }

        unlink($this->getPath($name));
    }

    /**
     * Unlike 2.0, 1.x accepts a name in a subdirectory, like "post-receive.d/notify",
     * or starting with a dot.
     *
     * @throws InvalidArgumentException The name leads out of the hooks directory
     *
     * @return string
     */
    protected function getPath($name)
    {
        if (1 === preg_match('#(?:^|[/\\\\])\.\.(?:[/\\\\]|$)#', (string) $name)) {
            throw new InvalidArgumentException(sprintf('The name of the hook "%s" leads out of the hooks directory.', $name));
        }

        return $this->repository->getGitDir().'/hooks/'.$name;
    }
}
