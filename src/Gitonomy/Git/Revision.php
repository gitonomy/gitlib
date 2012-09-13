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
     * @var string
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
    public function getLog($offset = null, $limit = null)
    {
        return new Log($this->repository, $this->getResolved()->getHash(), $offset, $limit);
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

        $process = $this->repository->getProcess('rev-parse', array('--verify', $this->name));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Unable to resolve the revision "%s": %s', $this->name, $process->getErrorOutput()));
        }

        return $this->resolved = $this->repository->getCommit(trim($process->getOutput()));
    }
}
