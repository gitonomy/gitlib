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

use Gitonomy\Git\Exception\ReferenceNotFoundException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Revision
{
    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var Commit
     */
    protected $resolved;

    public function __construct(Repository $repository, $name)
    {
        $this->repository = $repository;
        $this->name       = $name;
    }

    /**
     * @return Log
     */
    public function getLog($paths = null, $offset = null, $limit = null)
    {
        return $this->repository->getLog($this->name, $paths, $offset, $limit);
    }

    /**
     * Resolves the revision to a commit hash.
     *
     * @return Commit
     */
    public function getResolved()
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        try {
            $result = $this->repository->run('rev-parse', array('--verify', $this->name));
        } catch (\RuntimeException $e) {
            throw new ReferenceNotFoundException(sprintf('Can not find reference "%s"', $this->name));
        }

        return $this->resolved = $this->repository->getCommit(trim($result));
    }
}
