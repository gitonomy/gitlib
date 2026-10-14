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
use Gitonomy\Git\Exception\ReferenceNotFoundException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @implements \IteratorAggregate<int, Commit>
 */
final class Log implements \Countable, \IteratorAggregate
{
    private ?RevisionList $revisions;

    /**
     * @var string[]
     */
    private array $paths;

    private ?int $offset;

    private ?int $limit;

    /**
     * Instanciates a git log object.
     *
     * The revisions are names, ranges ("a..b", "a...b"), names to exclude
     * ("^a"), or "--all".
     *
     * @param Repository                                               $repository the repository where log occurs
     * @param RevisionList|Revision|string|array<Revision|string>|null $revisions  a list of revisions or null if you want all history
     * @param string[]|string|null                                     $paths      paths to filter on, with git's wildcards
     * @param int|null                                                 $offset     start list from a given position
     * @param int|null                                                 $limit      limit number of fetched elements
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

    /**
     * @deprecated since gitonomy/gitlib 2.0, use Repository::getDiff() instead: it ignored the paths of the log
     */
    public function getDiff(): Diff
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "%s::getDiff()" instead.', __METHOD__, Repository::class);

        return $this->repository->getLegacyDiff($this->revisions);
    }

    public function getRevisions(): ?RevisionList
    {
        return $this->revisions;
    }

    /**
     * @return string[]
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * @return self The log, starting from the commit $offset
     */
    public function withOffset(?int $offset): self
    {
        $log = clone $this;
        $log->offset = $offset;

        return $log;
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use withOffset() instead
     */
    public function setOffset(?int $offset): static
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "withOffset()" instead.', __METHOD__);

        $this->offset = $offset;

        return $this;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * @return self The log, of $limit commits at most
     */
    public function withLimit(?int $limit): self
    {
        $log = clone $this;
        $log->limit = $limit;

        return $log;
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use withLimit() instead
     */
    public function setLimit(?int $limit): static
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "withLimit()" instead.', __METHOD__);

        $this->limit = $limit;

        return $this;
    }

    /**
     * @throws ReferenceNotFoundException The log is empty
     */
    public function getSingleCommit(): Commit
    {
        return $this->withLimit(1)->getCommits()[0] ?? throw new ReferenceNotFoundException('The log has no commit.');
    }

    /**
     * @return Commit[]
     */
    public function getCommits(): array
    {
        [$includes, $excludes] = $this->range();
        $commitLog = $this->repository->getRepositoryDriver()->getCommitLog($includes, $excludes, $this->paths, $this->offset ?? 0, $this->limit);

        $result = [];
        foreach ($commitLog as $commitData) {
            $commit = $this->repository->getCommit($commitData->hash);
            $commit->setData($commitData);

            $result[] = $commit;
        }

        return $result;
    }

    /**
     * @see Countable
     *
     * @return int The number of commits of the log, after its offset and up to its limit: as many as getCommits() returns
     */
    public function count(): int
    {
        $count = max(0, $this->countCommits() - ($this->offset ?? 0));

        return null === $this->limit ? $count : min($count, $this->limit);
    }

    /**
     * @see IteratorAggregate
     *
     * @return \ArrayIterator<int, Commit>
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
        [$includes, $excludes] = $this->range();

        return $this->repository->getRepositoryDriver()->countCommits($includes, $excludes, $this->paths);
    }

    /**
     * Resolves the revisions to the commits whose history to list, and the
     * ones whose history to leave out.
     *
     * @return array{list<string>|null, list<string>} Null for every reference and HEAD
     *
     * @throws ReferenceNotFoundException A revision names no commit
     */
    private function range(): array
    {
        if (null === $this->revisions) {
            return [null, []];
        }

        $all = false;
        $includes = [];
        $excludes = [];
        foreach ($this->revisions->getAll() as $revision) {
            if ($revision instanceof Commit || $revision instanceof Reference) {
                $includes[] = $revision->getCommit()->getHash();

                continue;
            }

            $name = $revision->getRevision();
            if ('--all' === $name) {
                $all = true;
            } elseif (str_starts_with($name, '^')) {
                $excludes[] = $this->commitHash(substr($name, 1));
            } elseif (str_contains($name, '...')) {
                // Reachable from either end, but not from both.
                [$one, $two] = array_map($this->commitHash(...), explode('...', $name, 2));
                array_push($includes, $one, $two);
                array_push($excludes, ...$this->repository->getRepositoryDriver()->getMergeBases($one, $two));
            } elseif (str_contains($name, '..')) {
                [$from, $to] = array_map($this->commitHash(...), explode('..', $name, 2));
                $includes[] = $to;
                $excludes[] = $from;
            } elseif (str_starts_with($name, '-')) {
                throw new InvalidArgumentException(\sprintf('The log of "%s" is not supported: pass revisions, ranges, names to exclude or "--all".', $name));
            } else {
                $includes[] = $this->commitHash($name);
            }
        }

        return [$all ? null : $includes, $excludes];
    }

    /**
     * @param string $revision An end of a range, HEAD when empty
     */
    private function commitHash(string $revision): string
    {
        return $this->repository->getRevision('' === $revision ? 'HEAD' : $revision)->getCommit()->getHash();
    }
}
