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

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\LogicException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final readonly class WorkingCopy
{
    public function __construct(
        private Repository $repository,
    ) {
        if ($this->repository->isBare()) {
            throw new LogicException('Can\'t create a working copy on a bare repository');
        }
    }

    /**
     * @return string[]
     */
    public function getUntrackedFiles(): array
    {
        return $this->repository->getRepositoryDriver()->getUntrackedFiles();
    }

    public function getDiffPending(): Diff
    {
        $diff = $this->repository->getRepositoryDriver()->getDiffPending();
        $diff->setRepository($this->repository);

        return $diff;
    }

    public function getDiffStaged(): Diff
    {
        $diff = $this->repository->getRepositoryDriver()->getDiffStaged();
        $diff->setRepository($this->repository);

        return $diff;
    }

    /**
     * @param Commit|Reference|string $revision
     */
    public function checkout($revision, ?string $branch = null): static
    {
        if (null === $branch) {
            $this->repository->getRepositoryDriver()->checkout($this->revisionToString($revision));
        } else {
            $this->repository->getRepositoryDriver()->checkoutNewBranch($branch, $this->revisionToString($revision));
        }

        return $this;
    }

    /**
     * Merges a revision into the current branch.
     *
     * @param Commit|Reference|string $revision
     * @param string[]                $args     extra arguments for the merge command (e.g. ``['--no-ff']``)
     */
    public function merge($revision, array $args = []): static
    {
        $this->repository->getRepositoryDriver()->merge($this->revisionToString($revision), $args);

        return $this;
    }

    /**
     * @param Commit|Reference|string $revision
     */
    private function revisionToString($revision): string
    {
        if ($revision instanceof Commit) {
            $result = $revision->getHash();
        } elseif ($revision instanceof Reference) {
            $result = $revision->getFullname();
        } elseif (\is_string($revision)) {
            $result = $revision;
        } else {
            throw new InvalidArgumentException(\sprintf('Unknown type "%s"', \gettype($revision)));
        }

        return $result;
    }
}
