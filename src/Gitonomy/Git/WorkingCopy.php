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
        private readonly Repository $repository,
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
        $lines = explode("\0", $this->run('status', ['--porcelain', '--untracked-files=all', '-z']));
        $lines = array_filter($lines, static function ($l) {
            return '?? ' === substr($l, 0, 3);
        });

        return array_map(static function ($l) {
            return substr($l, 3);
        }, $lines);
    }

    public function getDiffPending(): Diff
    {
        $diff = Diff::parse($this->run('diff', ['-r', '-p', '--raw', '-m', '-M', '--full-index']));
        $diff->setRepository($this->repository);

        return $diff;
    }

    public function getDiffStaged(): Diff
    {
        $diff = Diff::parse($this->run('diff', ['-r', '-p', '--raw', '-m', '-M', '--full-index', '--staged']));
        $diff->setRepository($this->repository);

        return $diff;
    }

    /**
     * @param Commit|Reference|string $revision
     */
    public function checkout($revision, ?string $branch = null): static
    {
        $args = [];
        if ($revision instanceof Commit) {
            $args[] = $revision->getHash();
        } elseif ($revision instanceof Reference) {
            $args[] = $revision->getFullname();
        } elseif (\is_string($revision)) {
            $args[] = $revision;
        } else {
            throw new InvalidArgumentException(\sprintf('Unknown type "%s"', \gettype($revision)));
        }

        if (null !== $branch) {
            $args = array_merge($args, ['-b', $branch]);
        }

        $this->run('checkout', $args);

        return $this;
    }

    private function run(string $command, array $args = []): ?string
    {
        return $this->repository->run($command, $args);
    }
}
