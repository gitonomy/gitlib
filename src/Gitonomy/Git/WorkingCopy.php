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
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final readonly class WorkingCopy
{
    public function __construct(
        private Repository $repository,
    ) {
        if ($this->repository->isBare()) {
            throw new LogicException('A bare repository has no working copy.');
        }
    }

    /**
     * @return string[]
     */
    public function getUntrackedFiles(): array
    {
        return $this->repository->getRepositoryDriver()->getUntrackedFiles();
    }

    /**
     * Returns the changes of the working tree, staged or not, since $revision.
     */
    public function getDiff(Revision|string $revision = 'HEAD'): Diff
    {
        $hash = ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit()->getHash();

        return Diff::fromData($this->repository->getRepositoryDriver()->getWorkingTreeDiff($hash), $this->repository);
    }

    /**
     * Returns the changes of the working tree not staged yet.
     */
    public function getDiffPending(): Diff
    {
        return Diff::fromData($this->repository->getRepositoryDriver()->getDiffPending(), $this->repository);
    }

    /**
     * Returns the changes staged in the index.
     */
    public function getDiffStaged(): Diff
    {
        return Diff::fromData($this->repository->getRepositoryDriver()->getDiffStaged(), $this->repository);
    }

    /**
     * Checks $revision out, like `git checkout`: HEAD points to it when it is
     * a local branch, or @{-<n>}, the nth branch checked out before, stays
     * as it is for "HEAD", and points to the commit otherwise: a tag, a hash,
     * a remote branch...
     *
     * Unlike `git checkout`, a name that no revision has throws, the name of
     * a remote branch alone included, like "feature" for "origin/feature":
     * create its local branch with checkoutNewBranch().
     *
     * Passing a branch to create as second argument is deprecated.
     *
     * @throws ReferenceNotFoundException $revision names no commit
     * @throws DriverException            The checkout would overwrite local changes
     */
    public function checkout(Revision|string $revision, ?string $branch = null): static
    {
        if (null !== $branch) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing a branch to create to "%s()" is deprecated, use "checkoutNewBranch()" instead.', __METHOD__);

            return $this->checkoutNewBranch($branch, $revision);
        }

        // A name that no revision has throws, where "git checkout" would create
        // the branch of a remote one, or discard the changes of a file.
        ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit();

        try {
            $this->repository->getRepositoryDriver()->checkout(self::name($revision));
        } finally {
            $this->repository->getReferences()->invalidate();
        }

        return $this;
    }

    /**
     * Creates the branch $branch at $startPoint, and checks it out, like
     * `git checkout -b`. The branch tracks $startPoint when it is a remote
     * branch, like "origin/feature", as with branch.autoSetupMerge=true,
     * git's default, whatever the configuration. A failure leaves the
     * branches and the files as they were: like `git checkout -b`, no
     * branch is created when the checkout fails.
     *
     * @throws ReferenceNotFoundException $startPoint names no commit
     * @throws DriverException            $branch exists already or isn't a valid name, $startPoint is the short name of several references, which git refuses, or the checkout would overwrite local changes
     */
    public function checkoutNewBranch(string $branch, Revision|string $startPoint = 'HEAD'): static
    {
        // Like checkout(). The driver gets the name, which tells a remote branch.
        ($startPoint instanceof Revision ? $startPoint : $this->repository->getRevision($startPoint))->getCommit();

        try {
            $this->repository->getRepositoryDriver()->checkoutNewBranch($branch, $startPoint instanceof Revision ? $startPoint->getRevision() : $startPoint);
        } finally {
            $this->repository->getReferences()->invalidate();
        }

        return $this;
    }

    /**
     * Merges a revision into the current branch.
     *
     * @throws ReferenceNotFoundException $revision names no commit
     * @throws DriverException            The merge fails: a conflict, staged changes, an unrelated history...
     */
    public function merge(Revision|string $revision, FastForward $fastForward = FastForward::Allow): static
    {
        // Like checkout(). The driver gets the name, which the message of the merge commit has.
        ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit();

        try {
            $this->repository->getRepositoryDriver()->merge(self::name($revision), $fastForward);
        } finally {
            $this->repository->getReferences()->invalidate();
        }

        return $this;
    }

    /**
     * @return string The name git checks out or merges: a local branch by its name, to attach HEAD to it
     */
    private static function name(Revision|string $revision): string
    {
        return match (true) {
            $revision instanceof Branch && $revision->isLocal() => $revision->getName(),
            $revision instanceof Reference => $revision->getFullname(),
            $revision instanceof Revision => $revision->getRevision(),
            default => $revision,
        };
    }
}
