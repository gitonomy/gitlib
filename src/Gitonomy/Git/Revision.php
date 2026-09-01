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

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Revision
{
    public function __construct(
        protected readonly Repository $repository,
        protected readonly string $revision,
    ) {
    }

    public function getLog(array|string|null $paths = null, ?int $offset = null, ?int $limit = null): Log
    {
        return $this->repository->getLog($this, $paths, $offset, $limit);
    }

    /**
     * Returns the last modification date of the reference.
     */
    public function getCommit(): Commit
    {
        return $this->getLog()->getSingleCommit();
    }

    public function getRevision(): string
    {
        return $this->revision;
    }

    public function getRepository(): Repository
    {
        return $this->repository;
    }
}
