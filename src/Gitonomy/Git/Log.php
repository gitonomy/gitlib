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
use Gitonomy\Git\Exception\ReferenceNotFoundException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Log implements \Countable, \IteratorAggregate
{
    private ?RevisionList $revisions;

    private array $paths;

    private ?int $offset;

    private ?int $limit;

    /**
     * Instanciates a git log object.
     *
     * @param Repository                              $repository the repository where log occurs
     * @param RevisionList|Revision|string|array|null $revisions  a list of revisions or null if you want all history
     * @param array|string|null                       $paths      paths to filter on
     * @param int|null                                $offset     start list from a given position
     * @param int|null                                $limit      limit number of fetched elements
     */
    public function __construct(
        private readonly Repository $repository,
        RevisionList|Revision|string|array|null $revisions = null,
        array|string|null $paths = null,
        ?int $offset = null,
        ?int $limit = null,
    ) {
        if (null !== $revisions && !$revisions instanceof RevisionList) {
            $revisions = new RevisionList($repository, $revisions);
        }

        if (null === $paths) {
            $paths = [];
        } elseif (\is_string($paths)) {
            $paths = [$paths];
        }

        $this->revisions = $revisions;
        $this->paths = $paths;
        $this->offset = $offset;
        $this->limit = $limit;
    }

    public function getDiff(): Diff
    {
        return $this->repository->getDiff($this->revisions);
    }

    public function getRevisions(): ?RevisionList
    {
        return $this->revisions;
    }

    public function getPaths(): array
    {
        return $this->paths;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function setOffset(?int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function setLimit(?int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function getSingleCommit(): Commit
    {
        $limit = $this->limit;
        $this->limit = 1;
        $commits = $this->getCommits();
        $this->setLimit($limit);

        if (0 === \count($commits)) {
            throw new ReferenceNotFoundException('The log is empty');
        }

        return array_pop($commits);
    }

    /**
     * @return Commit[]
     */
    public function getCommits(): array
    {
        $revisions = $this->revisions?->getAsTextArray();

        try {
            $commitLog = $this->repository->getRepositoryDriver()->getCommitLog($revisions, $this->paths, $this->offset, $this->limit);
        } catch (DriverException $e) {
            $revisionsText = null !== $revisions ? implode(' ', $revisions) : '--all';

            throw new ReferenceNotFoundException(\sprintf('Can not find revision "%s"', $revisionsText), 0, $e);
        }

        $result = [];
        foreach ($commitLog as $hash => $commitData) {
            $commit = $this->repository->getCommit($hash);
            $commit->setData($commitData);

            $result[] = $commit;
        }

        return $result;
    }

    /**
     * @see Countable
     */
    public function count(): int
    {
        return $this->countCommits();
    }

    /**
     * @see IteratorAggregate
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getCommits());
    }

    /**
     * Count commits, without offset or limit.
     */
    public function countCommits(): int
    {
        return $this->repository->getRepositoryDriver()->countCommits($this->revisions?->getAsTextArray(), $this->paths);
    }
}
