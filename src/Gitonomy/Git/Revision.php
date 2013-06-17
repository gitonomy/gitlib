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
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;

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
    protected $revision;

    /**
     * @var Commit
     */
    protected $commitHash;

    public function __construct(Repository $repository, $revision, $commitHash = null)
    {
        $this->repository = $repository;
        $this->revision   = $revision;
        $this->commitHash = $commitHash;
    }

    /**
     * @return Log
     */
    public function getLog($paths = null, $offset = null, $limit = null)
    {
        return $this->repository->getLog($this, $paths, $offset, $limit);
    }

    /**
     * Returns the commit associated to the reference.
     *
     * @return Gitonomy\Git\Commit
     */
    public function getCommit()
    {
        return $this->repository->getCommit($this->getCommitHash());
    }

    public function getCommitHash()
    {
        if (null !== $this->commitHash) {
            return $this->commitHash;
        }

        try {
            $result = $this->repository->run('rev-parse', array('--verify', $this->revision));
        } catch (ProcessException $e) {
            throw new ReferenceNotFoundException(sprintf('Can not find revision "%s"', $this->revision));
        }

        return $this->commitHash = trim($result);
    }

    /**
     * Returns the last modification date of the reference.
     *
     * @return DateTime
     */
    public function getLastModification($path = null)
    {
        return $this->getCommit()->getLastModification($path);
    }

    /**
     * @return string
     */
    public function getRevision()
    {
        return $this->revision;
    }

    /**
     * @return Repository
     */
    public function getRepository()
    {
        return $this->repository;
    }
}
