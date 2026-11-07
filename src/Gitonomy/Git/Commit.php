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
use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Util\Message;

/**
 * Representation of a Git commit.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Commit extends Revision
{
    /**
     * Raw commit data, as returned by the Driver. Lazily loaded.
     */
    private ?CommitData $data;

    // Values derived from the fields above, computed and cached independently.
    private ?string $shortHash = null;
    private ?Tree $tree = null;

    /**
     * @param Repository $repository Repository of the commit
     * @param string     $hash       Hash of the commit
     *
     * @internal Get it from Repository::getCommit(), which returns the same object while the application uses it
     */
    public function __construct(Repository $repository, string $hash, ?CommitData $data = null)
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            throw new ReferenceNotFoundException(\sprintf('"%s" is not the hash of a commit: get a commit by its name with getRevision(...)->getCommit().', $hash));
        }

        parent::__construct($repository, $hash);

        $this->data = $data;
    }

    /**
     * @internal
     */
    public function setData(CommitData $data): void
    {
        $this->data = $data;
    }

    /**
     * Returns the changes of the commit: against its first parent, like GitHub
     * shows a merge commit, or against nothing for a root commit.
     */
    public function getDiff(): Diff
    {
        return Diff::fromData($this->repository->getRepositoryDriver()->getDiff($this->getParentHashes()[0] ?? null, $this->revision), $this->repository);
    }

    /**
     * Returns the commit hash.
     */
    public function getHash(): string
    {
        return $this->revision;
    }

    /**
     * Returns the short commit hash.
     */
    public function getShortHash(): string
    {
        return $this->shortHash ??= $this->repository->getRepositoryDriver()->getShortHash($this->revision);
    }

    /**
     * Returns parent hashes.
     *
     * @return string[] An array of SHA1 hashes
     */
    public function getParentHashes(): array
    {
        return $this->data()->parentHashes;
    }

    /**
     * Returns the parent commits.
     *
     * @return Commit[] An array of Commit objects
     */
    public function getParents(): array
    {
        $result = [];
        foreach ($this->data()->parentHashes as $parentHash) {
            $result[] = $this->repository->getCommit($parentHash);
        }

        return $result;
    }

    /**
     * Returns the tree hash.
     */
    public function getTreeHash(): string
    {
        return $this->data()->treeHash;
    }

    public function getTree(): Tree
    {
        return $this->tree ??= $this->repository->getTree($this->getTreeHash());
    }

    /**
     * @throws ReferenceNotFoundException No commit up to this one changes $path
     */
    public function getLastModification(?string $path = null): self
    {
        if (null !== $path && str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }

        try {
            return $this->getLog($path, null, 1)->getSingleCommit();
        } catch (ReferenceNotFoundException $e) {
            throw new ReferenceNotFoundException(\sprintf('No commit up to "%s" changes "%s".', $this->revision, $path), 0, $e);
        }
    }

    /**
     * Resolves all references associated to this commit.
     *
     * @return Reference[] An array of references (Branch, Tag, Squash)
     */
    public function resolveReferences(): array
    {
        return $this->repository->getReferences()->resolve($this);
    }

    /**
     * Returns the local and remote branches whose history contains the commit.
     *
     * @return Branch[]
     */
    public function getIncludingBranches(): array
    {
        $fullnames = $this->repository->getRepositoryDriver()->getIncludingBranches($this->revision);
        $references = $this->repository->getReferences();
        // A branch another process created since gitlib read the references.
        if ([] !== array_filter($fullnames, static fn (string $fullname): bool => !$references->has($fullname))) {
            $references->invalidate();
        }

        $branches = [];
        foreach ($fullnames as $fullname) {
            // Unknown still: the default branch of a remote, which the references leave out, even
            // when a mirror leaves it as a regular reference, or a branch deleted meanwhile.
            if (!$references->has($fullname)) {
                continue;
            }

            $branch = $references->get($fullname);
            if ($branch instanceof Branch) {
                $branches[] = $branch;
            }
        }

        return $branches;
    }

    /**
     * Returns the author name.
     */
    public function getAuthorName(): string
    {
        return $this->data()->authorName;
    }

    /**
     * Returns the author email.
     */
    public function getAuthorEmail(): string
    {
        return $this->data()->authorEmail;
    }

    /**
     * Returns the authoring date, at the UTC offset of the author, like git
     * log shows it.
     */
    public function getAuthorDate(): \DateTimeImmutable
    {
        return $this->data()->authorDate;
    }

    /**
     * Returns the committer name.
     */
    public function getCommitterName(): string
    {
        return $this->data()->committerName;
    }

    /**
     * Returns the committer email.
     */
    public function getCommitterEmail(): string
    {
        return $this->data()->committerEmail;
    }

    /**
     * Returns the committing date, at the UTC offset of the committer, like
     * git log shows it.
     */
    public function getCommitterDate(): \DateTimeImmutable
    {
        return $this->data()->committerDate;
    }

    /**
     * Returns the message of the commit.
     */
    public function getMessage(): string
    {
        return $this->data()->message;
    }

    /**
     * @return string The first paragraph of the message, on one line, like "git log --format=%s"
     */
    public function getSubjectMessage(): string
    {
        return Message::subject($this->getMessage());
    }

    /**
     * @return string What follows the subject and its empty lines, like "git log --format=%b"
     */
    public function getBodyMessage(): string
    {
        return Message::body($this->getMessage());
    }

    public function getCommit(): self
    {
        return $this;
    }

    private function data(): CommitData
    {
        return $this->data ??= $this->repository->getRepositoryDriver()->getCommitData($this->revision);
    }
}
