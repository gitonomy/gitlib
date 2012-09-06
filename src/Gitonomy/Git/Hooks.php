<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

/**
 * Hooks handler.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Hooks
{
    /**
     * @var Gitonomy\Git\Repository
     */
    protected $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public function has($name)
    {
        return file_exists($this->getPath($name));
    }

    public function setSymlink($name, $file)
    {
        $path = $this->getPath($name);
        if (file_exists($path)) {
            throw new \RuntimeException(sprintf('A hook "%s" is already defined', $name));
        }

        if (false === symlink($file, $path)) {
            throw new \RuntimeException(sprintf('Unable to create hook "%s"', $name, $path));
        }
    }

    public function set($name, $content)
    {
        $path = $this->getPath($name);
        if (file_exists($path)) {
            throw new \RuntimeException(sprintf('A hook "%s" is already defined', $name));
        }

        file_put_contents($path, $content);
        chmod($path, 0777);
    }

    public function remove($name)
    {
        $path = $this->getPath($name);
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('The hook "%s" was not found'));
        }

        unlink($path);
    }

    protected function getPath($name)
    {
        return $this->repository->getPath().'/hooks/'.$name;
    }
}
