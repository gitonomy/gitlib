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
        $lines = explode("\0", $this->run('status', ['--porcelain', '--untracked-files=all', '-z']));
        $lines = array_filter($lines, function ($l) {
            return substr($l, 0, 3) === '?? ';
        });
        $lines = array_map(function ($l) {
            return substr($l, 3);
        }, $lines);

        return $lines;
    }

    public function getDiffPending()
    {
        $diff = Diff::parse($this->run('diff', ['-r', '-p', '-m', '-M', '--full-index']));
        $diff->setRepository($this->repository);

        return $diff;
    }

    public function getDiffStaged()
    {
        $diff = Diff::parse($this->run('diff', ['-r', '-p', '-m', '-M', '--full-index', '--staged']));
        $diff->setRepository($this->repository);

        return $diff;
    }

    /**
     * Checkout the given revision.
     *
     * Optionally sets the branch.
     *
     * @param Commit|Reference|string $revision
     * @param string|null             $branch
     *
     * @return WorkingCopy
     */
    public function checkout($revision, $branch = null)
    {
        $args = [];
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
            $args = array_merge($args, ['-b', $branch]);
        }

        $this->run('checkout', $args);

        return $this;
    }

    /**
     * Stages the files provided by arguments.
     *
     * @param string[] $files
     *
     * @return Repository the current repository
     */
    public function stage(array $files = [])
    {
        foreach ($files as $file) {
            $this->run('add', [$file]);
        }

        return $this;
    }

    /**
     * Unstages the files provided by arguments.
     *
     * @param string[] $files
     *
     * @return Repository the current repository
     */
    public function unstage(array $files = [])
    {
        foreach ($files as $file) {
            $this->run('restore', ['--staged', $file]);
        }

        return $this;
    }

    /**
     * Discards file changed from files provided by arguments.
     *
     * @param string[] $files
     *
     * @return Repository the current repository
     */
    public function discard(array $files = [])
    {
        foreach ($files as $file) {
            $this->run('checkout', ['--', $file]);
        }

        return $this;
    }

    /**
     * Creates a commit with the message provided.
     *
     * Optionally stages files provided.
     *
     * @param string      $message
     * @param string|null $author
     * @param string[]    $files
     *
     * @return Repository the current repository
     */
    public function commit($message, $author = null, array $files = [])
    {
        $this->stage($files);

        if ($author === null) {
            $this->run('commit', ['-m', $message]);
        } else {
            $this->run('commit', ['-m', $message, sprintf('--author="%s"', $author)]);
        }

        return $this;
    }

    protected function run($command, array $args = [])
    {
        return $this->repository->run($command, $args);
    }
}
