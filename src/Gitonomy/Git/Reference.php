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
 * Branch, Tag and Stash extend it: extending it outside gitlib isn't
 * supported, and won't be possible in 3.0.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Julien DIDIER <genzo.wm@gmail.com>
 */
abstract class Reference extends Revision
{
    /**
     * @param string $commitHash What the reference points to, through an annotated tag
     *
     * @internal Get it from Repository::getReferences(), which gitlib reads again after its writes
     */
    public function __construct(
        Repository $repository,
        string $fullname,
        protected readonly string $commitHash,
    ) {
        parent::__construct($repository, $fullname);
    }

    /**
     * @return string The name, without its prefix: "master", "origin/master" for a remote branch, "0.1" for a tag
     */
    abstract public function getName(): string;

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
