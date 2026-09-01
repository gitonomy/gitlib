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

use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;

/**
 * Reference in a Git repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Julien DIDIER <genzo.wm@gmail.com>
 */
abstract class Reference extends Revision
{
    protected ?string $commitHash;

    public function __construct(Repository $repository, string $revision, ?string $commitHash = null)
    {
        parent::__construct($repository, $revision);

        $this->commitHash = $commitHash;
    }

    public function getFullname(): string
    {
        return $this->revision;
    }

    public function delete(): void
    {
        $this->repository->getReferences()->delete($this->getFullname());
    }

    public function getCommitHash(): string
    {
        if (null !== $this->commitHash) {
            return $this->commitHash;
        }

        try {
            $result = $this->repository->run('rev-parse', ['--verify', $this->revision]);
        } catch (ProcessException $e) {
            throw new ReferenceNotFoundException(sprintf('Can not find revision "%s"', $this->revision));
        }

        return $this->commitHash = trim($result);
    }

    /**
     * Returns the commit associated to the reference.
     */
    public function getCommit(): Commit
    {
        return $this->repository->getCommit($this->getCommitHash());
    }

    public function getLastModification(?string $path = null): Commit
    {
        return $this->getCommit()->getLastModification($path);
    }
}
