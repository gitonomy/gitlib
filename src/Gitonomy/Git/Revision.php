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

use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;

/**
 * A name git resolves to a commit: a branch, a tag, a hash, "HEAD~2"...
 *
 * Commit and Reference extend it: extending it outside gitlib isn't
 * supported, and won't be possible in 3.0.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Revision
{
    /**
     * @internal Get it from Repository::getRevision()
     */
    public function __construct(
        protected readonly Repository $repository,
        protected readonly string $revision,
    ) {
    }

    /**
     * @param string[]|string|null $paths Paths to filter on, with git's wildcards
     *
     * @throws InvalidArgumentException $offset or $limit is negative
     */
    public function getLog(array|string|null $paths = null, ?int $offset = null, ?int $limit = null): Log
    {
        return $this->repository->getLog($this, $paths, $offset, $limit);
    }

    /**
     * Returns the commit the revision names.
     *
     * @throws ReferenceNotFoundException The revision names no commit
     */
    public function getCommit(): Commit
    {
        $driver = $this->repository->getRepositoryDriver();

        try {
            // "^{commit}" would be part of the text of ":/text", the commit
            // whose message matches it: its hash first.
            $revision = str_starts_with($this->revision, ':/') ? $driver->resolveRevision($this->revision) : $this->revision;
            $hash = $driver->resolveRevision($revision.'^{commit}');
        } catch (ReferenceNotFoundException $e) {
            throw new ReferenceNotFoundException(\sprintf('The revision "%s" does not exist, or names no commit.', $this->revision), 0, $e);
        }

        return $this->repository->getCommit($hash);
    }

    public function getRevision(): string
    {
        return $this->revision;
    }

    public function getRepository(): Repository
    {
        return $this->repository;
    }
}
