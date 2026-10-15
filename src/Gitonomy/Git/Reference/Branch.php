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

namespace Gitonomy\Git\Reference;

use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference;
use Gitonomy\Git\Revision;

/**
 * Representation of a branch reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class Branch extends Reference
{
    private $local = null;

    public function getName()
    {
        $fullname = $this->getFullname();

        if (preg_match('#^refs/heads/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['name'];
        }

        if (preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $fullname, $vars)) {
            return $vars['remote'].'/'.$vars['name'];
        }

        throw new RuntimeException(sprintf('Cannot extract branch name from "%s"', $fullname));
    }

    public function isRemote()
    {
        $this->detectBranchType();

        return !$this->local;
    }

    public function isLocal()
    {
        $this->detectBranchType();

        return $this->local;
    }

    /**
     * Checks if the history of $revision contains this branch.
     */
    public function isMergedInto(Revision|string $revision): bool
    {
        // ReferenceBag::createBranch() keeps the name it got instead of a hash, like in 1.6.
        $commit = $this->repository->getRevision($this->getCommitHash())->getCommit();
        $mergeBase = $this->repository->getMergeBase($commit, $revision);

        return null !== $mergeBase && $mergeBase->getHash() === $commit->getHash();
    }

    /**
     * Check if this branch is merged to a destination branch
     * Optionally, check only with remote branches.
     *
     * @param string $destinationBranchName
     * @param bool   $compareOnlyWithRemote
     *
     * @return null|bool
     *
     * @deprecated since gitonomy/gitlib 1.7, use isMergedInto() instead
     */
    public function isMergedTo($destinationBranchName = 'master', $compareOnlyWithRemote = false)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::isMergedInto()" instead.', __METHOD__, self::class);

        $arguments = ['-a'];

        if ($compareOnlyWithRemote) {
            $arguments = ['-r'];
        }

        $arguments[] = '--merged';
        $arguments[] = $destinationBranchName;

        try {
            $result = $this->repository->run('branch', $arguments);
        } catch (ProcessException $e) {
            throw new RuntimeException(
                sprintf('Cannot determine if merged to the branch "%s"', $destinationBranchName),
                $e->getCode(),
                $e
            );
        }

        if (!$result) {
            return false;
        }

        $output = explode("\n", trim(str_replace(['*', 'remotes/'], '', $result)));
        $filtered_output = array_filter($output, static function ($v) {
            return false === strpos($v, '->');
        });
        $trimmed_output = array_map('trim', $filtered_output);

        return in_array($this->getName(), $trimmed_output, true);
    }

    /**
     * Points the branch to $revision.
     *
     * @return self The branch, pointing to $revision
     */
    public function moveTo(Revision|string $revision): self
    {
        $hash = ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit()->getHash();
        $this->repository->run('update-ref', [$this->getFullname(), $hash]);

        $references = $this->repository->getReferences();
        $references->invalidate();

        return $this->isLocal() ? $references->getBranch($this->getName()) : $references->getRemoteBranch($this->getName());
    }

    private function detectBranchType()
    {
        if (null === $this->local) {
            $this->local = !preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $this->getFullname());
        }
    }
}
