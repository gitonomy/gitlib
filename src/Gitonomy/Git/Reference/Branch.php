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

namespace Gitonomy\Git\Reference;

use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference;
use Gitonomy\Git\Revision;

/**
 * Representation of a branch reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Branch extends Reference
{
    public function getName(): string
    {
        $fullname = $this->getFullname();

        if (preg_match('#^refs/heads/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['name'];
        }

        if (preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['remote'].'/'.$vars['name'];
        }

        throw new RuntimeException(\sprintf('Cannot extract branch name from "%s".', $fullname));
    }

    public function isRemote(): bool
    {
        return str_starts_with($this->getFullname(), 'refs/remotes/');
    }

    public function isLocal(): bool
    {
        return !$this->isRemote();
    }

    /**
     * Checks if the history of $revision contains this branch.
     */
    public function isMergedInto(Revision|string $revision): bool
    {
        $destination = ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit()->getHash();

        return \in_array($this->commitHash, $this->repository->getRepositoryDriver()->getMergeBases($this->commitHash, $destination), true);
    }

    /**
     * Check if this branch is merged to a destination branch
     * Optionally, check only with remote branches.
     *
     * @deprecated since gitonomy/gitlib 1.7, use isMergedInto() instead
     */
    public function isMergedTo(string $destinationBranchName = 'master', bool $compareOnlyWithRemote = false): bool
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::isMergedInto()" instead.', __METHOD__, self::class);

        if ($compareOnlyWithRemote && $this->isLocal()) {
            return false;
        }

        return $this->isMergedInto($destinationBranchName);
    }

    /**
     * Points the branch to $revision.
     *
     * @return self The branch, pointing to $revision
     */
    public function moveTo(Revision|string $revision): self
    {
        $hash = ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit()->getHash();
        $this->repository->getRepositoryDriver()->updateReference($this->getFullname(), $hash);

        $references = $this->repository->getReferences();
        $references->invalidate();

        return $this->isLocal() ? $references->getBranch($this->getName()) : $references->getRemoteBranch($this->getName());
    }
}
