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

/**
 * Reference in a Git repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Julien DIDIER <genzo.wm@gmail.com>
 */
abstract class Reference extends Revision
{
    /**
     * @param string $commitHash What the reference points to, through an annotated tag
     */
    public function __construct(
        Repository $repository,
        string $fullname,
        protected readonly string $commitHash,
    ) {
        parent::__construct($repository, $fullname);
    }

    public function getFullname(): string
    {
        return $this->revision;
    }

    public function delete(): void
    {
        $this->repository->getReferences()->delete($this->getFullname());
    }

    /**
     * @return string The commit the reference points to, when it was read: the commit an annotated tag points to, for one
     */
    public function getCommitHash(): string
    {
        return $this->commitHash;
    }

    /**
     * Returns the commit associated to the reference.
     */
    public function getCommit(): Commit
    {
        return $this->repository->getCommit($this->commitHash);
    }

    public function getLastModification(?string $path = null): Commit
    {
        return $this->getCommit()->getLastModification($path);
    }
}
