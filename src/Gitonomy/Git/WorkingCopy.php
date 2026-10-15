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

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class WorkingCopy
{
    /**
     * @var Repository
     */
    protected $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;

        if ($this->repository->isBare()) {
            throw new LogicException('Can\'t create a working copy on a bare repository');
        }
    }

    public function getUntrackedFiles()
    {
        $lines = explode("\0", $this->run('status', ['--porcelain', '--untracked-files=all', '-z']));
        $lines = array_filter($lines, function ($l) {
            return substr($l, 0, 3) === '?? ';
        });
        $lines = array_map(function ($l) {
            return substr($l, 3);
        }, $lines);

        return $lines;
    }

    /**
     * Returns the changes of the working tree, staged or not, since $revision.
     *
     * @throws ReferenceNotFoundException $revision names no commit
     */
    public function getDiff(Revision|string $revision = 'HEAD'): Diff
    {
        $hash = ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit()->getHash();

        return Diff::parse($this->run('diff', ['-r', '-p', '--raw', '-m', '-M', '--full-index', $hash]), $this->repository);
    }

    public function getDiffPending()
    {
        return Diff::parse($this->run('diff', ['-r', '-p', '--raw', '-m', '-M', '--full-index']), $this->repository);
    }

    public function getDiffStaged()
    {
        return Diff::parse($this->run('diff', ['-r', '-p', '--raw', '-m', '-M', '--full-index', '--staged']), $this->repository);
    }

    /**
     * Passing a branch to create as second argument is deprecated, as a local
     * Branch object, which detaches HEAD, where 2.0 attaches HEAD to it.
     *
     * @return WorkingCopy
     */
    public function checkout($revision, $branch = null)
    {
        if (null !== $branch) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing a branch to create to "%s()" is deprecated, use "checkoutNewBranch()" instead.', __METHOD__);
        } elseif ($revision instanceof Branch && $revision->isLocal()) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing a local "%s" to "%s()", which detaches HEAD, is deprecated, 2.0 attaches HEAD to the branch: pass its name, or its commit to detach HEAD.', Branch::class, __METHOD__);
        }

        $args = [];
        if ($revision instanceof Commit) {
            $args[] = $revision->getHash();
        } elseif ($revision instanceof Reference) {
            $args[] = $revision->getFullname();
        } elseif ($revision instanceof Revision) {
            $args[] = $revision->getRevision();
        } elseif (is_string($revision)) {
            $args[] = $revision;
        } else {
            throw new InvalidArgumentException(sprintf('Unknown type "%s"', gettype($revision)));
        }

        if (null !== $branch) {
            $args = array_merge($args, ['-b', $branch]);
        }

        $this->run('checkout', $args);

        return $this;
    }

    /**
     * Creates the branch $branch at $startPoint, and checks it out, like
     * `git checkout -b`.
     *
     * @return $this
     *
     * @throws ReferenceNotFoundException $startPoint names no commit
     */
    public function checkoutNewBranch(string $branch, Revision|string $startPoint = 'HEAD'): self
    {
        // Like in 2.0. git gets the name, which tells a remote branch, to track it.
        ($startPoint instanceof Revision ? $startPoint : $this->repository->getRevision($startPoint))->getCommit();

        $this->run('checkout', ['-b', $branch, $startPoint instanceof Revision ? $startPoint->getRevision() : $startPoint]);

        return $this;
    }

    protected function run($command, array $args = [])
    {
        return $this->repository->run($command, $args);
    }
}
