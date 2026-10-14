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
     * Checks $revision out: HEAD points to it when it is a local branch, to
     * its commit otherwise.
     *
     * Passing a branch to create as second argument is deprecated.
     */
    public function checkout(Revision|string $revision, ?string $branch = null): static
    {
        if (null !== $branch) {
            trigger_deprecation('gitonomy/gitlib', '2.0', 'Passing a branch to create to "%s()" is deprecated, use "checkoutNewBranch()" instead.', __METHOD__);

            return $this->checkoutNewBranch($branch, $revision);
        }

        try {
            $this->repository->getRepositoryDriver()->checkout(self::name($revision));
        } finally {
            $this->repository->getReferences()->invalidate();
        }

        return $this;
    }

    /**
     * Creates the branch $branch at $startPoint, and checks it out.
     */
    public function checkoutNewBranch(string $branch, Revision|string $startPoint = 'HEAD'): static
    {
        $startHash = ($startPoint instanceof Revision ? $startPoint : $this->repository->getRevision($startPoint))->getCommit()->getHash();

        try {
            $this->repository->getRepositoryDriver()->checkoutNewBranch($branch, $startHash);
        } finally {
            $this->repository->getReferences()->invalidate();
        }

        return $this;
    }

    /**
     * Merges a revision into the current branch.
     *
     * An array of arguments of "git merge" ([], ["--no-ff"] or ["--ff-only"]) is deprecated.
     *
     * @param FastForward|string[] $fastForward
     */
    public function merge(Revision|string $revision, FastForward|array $fastForward = FastForward::Allow): static
    {
        if (\is_array($fastForward)) {
            trigger_deprecation('gitonomy/gitlib', '2.0', 'Passing arguments of "git merge" to "%s()" is deprecated, pass a "%s" instead.', __METHOD__, FastForward::class);

            $fastForward = match ($fastForward) {
                [] => FastForward::Allow,
                ['--no-ff'] => FastForward::Never,
                ['--ff-only'] => FastForward::Only,
                default => throw new InvalidArgumentException(\sprintf('Unsupported arguments "%s": pass a "%s" instead.', implode(' ', $fastForward), FastForward::class)),
            };
        }

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
