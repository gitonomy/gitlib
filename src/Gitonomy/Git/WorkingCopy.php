<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

use Gitonomy\Git\Diff\Diff;

use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
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

    public function getStatus()
    {
        return WorkingStatus::parseOutput();
    }

    public function getUntrackedFiles()
    {
        return array();
    }

    public function getDiffPending()
    {
        return Diff::parse($this->run('diff', array('-r', '-p', '-m', '-M', '--full-index')));
    }

    public function getDiffStaged()
    {
        return Diff::parse($this->run('diff', array('-r', '-p', '-m', '-M', '--full-index', '--staged')));
    }

    /**
     * @return WorkingCopy
     */
    public function checkout($revision, $branch = null)
    {
        $args = array();
        if ($revision instanceof Commit) {
            $args[] = $revision->getHash();
        } elseif ($revision instanceof Reference) {
            $args[] = $revision->getFullname();
        } elseif (is_string($revision)) {
            $args[] = $revision;
        } else {
            throw new InvalidArgumentException(sprintf('Unknown type "%s"', gettype($revision)));
        }

        if (null !== $branch) {
            $args = array_merge($args, array('-b', $branch));
        }

        $this->run('checkout', $args);

        return $this;
    }

    protected function run($command, array $args = array())
    {
        return $this->repository->run($command, $args);
    }
}
