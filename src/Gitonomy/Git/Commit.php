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
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Symfony\Component\String\CodePointString;

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
    private ?string $subjectMessage = null;
    private ?string $bodyMessage = null;

    /**
     * @param Repository $repository Repository of the commit
     * @param string     $hash       Hash of the commit
     */
    public function __construct(Repository $repository, string $hash, ?CommitData $data = null)
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            throw new ReferenceNotFoundException($hash);
        }

        parent::__construct($repository, $hash);

        $this->data = $data;
    }

    public function setData(CommitData $data): void
    {
        $this->data = $data;
    }

    public function getDiff(): Diff
    {
        $diff = $this->repository->getRepositoryDriver()->getCommitDiff($this->revision);
        $diff->setRepository($this->repository);

        return $diff;
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
        if (null === $this->shortHash) {
            try {
                $this->shortHash = $this->repository->getRepositoryDriver()->getShortHash($this->revision);
            } catch (DriverException $e) {
                throw new ReferenceNotFoundException($this->revision, 0, $e);
            }
        }

        return $this->shortHash;
    }

    /**
     * Returns a fixed-with short hash.
     */
    public function getFixedShortHash(int $length = 6): string
    {
        return new CodePointString($this->revision)->slice(0, $length)->toString();
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

    public function getLastModification(?string $path = null): self
    {
        if (null !== $path && str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }

        return $this->getLog($path, null, 1)->getSingleCommit();
    }

    /**
     * Returns the first line of the commit, and the first 50 characters.
     *
     * Ported from https://github.com/fabpot/Twig-extensions/blob/d67bc7e69788795d7905b52d31188bbc1d390e01/lib/Twig/Extensions/Extension/Text.php#L52-L109
     */
    public function getShortMessage(int $length = 50, bool $preserve = false, string $separator = '...'): string
    {
        $message = $this->getSubjectMessage();

        $codePointMessage = new CodePointString($message);

        if ($codePointMessage->length() > $length) {
            if ($preserve && null !== ($breakpoint = $codePointMessage->indexOf(' ', $length))) {
                $length = $breakpoint;
            }

            return rtrim($codePointMessage->slice(0, $length)->toString()).$separator;
        }

        return $message;
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
     * Find branch containing the commit.
     *
     * @param bool $local  set true to try to locate a commit on local repository
     * @param bool $remote set true to try to locate a commit on remote repository
     *
     * @return Reference[]|Branch[] An array of Reference\Branch
     */
    public function getIncludingBranches(bool $local = true, bool $remote = true): array
    {
        if (!$local && !$remote) {
            throw new InvalidArgumentException('You should a least set one argument to true');
        }

        $references = $this->repository->getReferences();

        $branches = [];
        foreach ($this->repository->getRepositoryDriver()->getIncludingBranches($this->revision) as $fullname) {
            if (str_starts_with($fullname, 'refs/heads/')) {
                if ($local) {
                    $branches[] = $references->getBranch(substr($fullname, \strlen('refs/heads/')));
                }
            } elseif ($remote) {
                $branches[] = $references->getRemoteBranch(substr($fullname, \strlen('refs/remotes/')));
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
     * Returns the authoring date.
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
     * Returns the comitter email.
     */
    public function getCommitterEmail(): string
    {
        return $this->data()->committerEmail;
    }

    /**
     * Returns the authoring date.
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
     * Returns the subject message (the first line).
     */
    public function getSubjectMessage(): string
    {
        if (null === $this->subjectMessage) {
            $lines = explode("\n", $this->getMessage());
            $this->subjectMessage = reset($lines);
        }

        return $this->subjectMessage;
    }

    /**
     * Return the body message.
     */
    public function getBodyMessage(): string
    {
        if (null === $this->bodyMessage) {
            $lines = explode("\n", $this->getMessage());

            array_shift($lines);
            array_shift($lines);

            $this->bodyMessage = implode("\n", $lines);
        }

        return $this->bodyMessage;
    }

    public function getCommit(): self
    {
        return $this;
    }

    private function data(): CommitData
    {
        if (null === $this->data) {
            try {
                $this->data = $this->repository->getRepositoryDriver()->getCommitData($this->revision);
            } catch (DriverException $e) {
                throw new ReferenceNotFoundException(\sprintf('Can not find reference "%s"', $this->revision), 0, $e);
            }
        }

        return $this->data;
    }
}
