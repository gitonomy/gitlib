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

class Revision
{
    protected $repository;
    protected $name;
    protected $resolved;

    public function __construct(Repository $repository, $name)
    {
        $this->repository = $repository;
        $this->name       = $name;
    }

    public function getLog($limit = null)
    {
        return new Log($this->repository, $this->getResolved(), $limit);
    }

    public function getResolved()
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        ob_start();
        system(sprintf(
            'cd %s && git rev-parse --verify %s',
            escapeshellarg($this->repository->getPath()),
            escapeshellarg($this->name)
        ), $result);
        $output = ob_get_clean();

        if (0 !== $result) {
            throw new \RuntimeException(sprintf('Unable to resolve the revision "%s"', $this->name));
        }

        return $this->resolved = trim($output);
    }

    public function getCommit()
    {
        return $this->repository->getCommit($this->getResolved());
    }
}
