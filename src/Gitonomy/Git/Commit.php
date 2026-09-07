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
use Gitonomy\Git\Exception\ProcessException;
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
     * Whether the fields below have been populated (either lazily fetched
     * from the repository, or provided upfront through setData()).
     */
    private bool $loaded = false;

    private ?string $treeHash = null;

    /**
     * @var string[]|null
     */
    private ?array $parentHashes = null;

    private ?string $authorName = null;
    private ?string $authorEmail = null;
    private ?\DateTime $authorDate = null;
    private ?string $committerName = null;
    private ?string $committerEmail = null;
    private ?\DateTime $committerDate = null;
    private ?string $message = null;

    // Values derived from the fields above, computed and cached independently.
    private ?string $shortHash = null;
    private ?Tree $tree = null;
    private ?string $subjectMessage = null;
    private ?string $bodyMessage = null;

    /**
     * @param Repository           $repository Repository of the commit
     * @param string               $hash       Hash of the commit
     * @param array<string, mixed> $data
     */
    public function __construct(Repository $repository, string $hash, array $data = [])
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            throw new ReferenceNotFoundException($hash);
        }

        parent::__construct($repository, $hash);

        $this->setData($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): void
    {
        foreach ($data as $name => $value) {
            match ($name) {
                'treeHash' => $this->treeHash = self::asString($name, $value),
                'parentHashes' => $this->parentHashes = self::asStringList($name, $value),
                'authorName' => $this->authorName = self::asString($name, $value),
                'authorEmail' => $this->authorEmail = self::asString($name, $value),
                'authorDate' => $this->authorDate = self::asDateTime($name, $value),
                'committerName' => $this->committerName = self::asString($name, $value),
                'committerEmail' => $this->committerEmail = self::asString($name, $value),
                'committerDate' => $this->committerDate = self::asDateTime($name, $value),
                'message' => $this->message = self::asString($name, $value),
                default => throw new InvalidArgumentException(\sprintf('Unknown commit data "%s".', $name)),
            };
        }

        if ([] !== $data) {
            $this->loaded = true;
        }
    }

    public function getDiff(): Diff
    {
        $args = ['-r', '-p', '--raw', '-m', '-M', '--no-commit-id', '--full-index', $this->revision];

        $result = $this->repository->run('diff-tree', $args);
        if (null === $result) {
            throw new ReferenceNotFoundException($this->revision);
        }

        $diff = Diff::parse($result);
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
            $result = $this->repository->run('log', ['--abbrev-commit', '--format=%h', '-n', 1, $this->revision]);
            if (null === $result) {
                throw new ReferenceNotFoundException($this->revision);
            }

            $this->shortHash = trim($result);
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
        $this->ensureLoaded();

        return $this->parentHashes ?? throw new ReferenceNotFoundException($this->revision);
    }

    /**
     * Returns the parent commits.
     *
     * @return Commit[] An array of Commit objects
     */
    public function getParents(): array
    {
        $result = [];
        foreach ($this->getParentHashes() as $parentHash) {
            $result[] = $this->repository->getCommit($parentHash);
        }

        return $result;
    }

    /**
     * Returns the tree hash.
     */
    public function getTreeHash(): string
    {
        $this->ensureLoaded();

        return $this->treeHash ?? throw new ReferenceNotFoundException($this->revision);
    }

    public function getTree(): Tree
    {
        if (null === $this->tree) {
            $this->tree = $this->repository->getTree($this->getTreeHash());
        }

        return $this->tree;
    }

    public function getLastModification(?string $path = null): self
    {
        if (null !== $path && str_starts_with($path, '/')) {
            $path = new CodePointString($path)->slice(1)->toString();
        }

        if ($getWorkingDir = $this->repository->getWorkingDir()) {
            $path = $getWorkingDir.'/'.$path;
        }

        $result = $this->repository->run('log', ['--format=%H', '-n', 1, $this->revision, '--', $path]);

        if (null === $result) {
            throw new ReferenceNotFoundException($this->revision);
        }

        return $this->repository->getCommit(trim($result));
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
        $arguments = ['--contains', $this->revision];

        if ($local && $remote) {
            $arguments[] = '-a';
        } elseif (!$local && $remote) {
            $arguments[] = '-r';
        } elseif (!$local) {
            throw new InvalidArgumentException('You should a least set one argument to true');
        }

        try {
            $result = $this->repository->run('branch', $arguments);
        } catch (ProcessException $e) {
            return [];
        }

        if (!$result) {
            return [];
        }

        $branchesName = explode("\n", trim(str_replace('*', '', $result)));
        $branchesName = array_filter($branchesName, static function ($v) {
            return null === new CodePointString($v)->indexOf('->');
        });
        $branchesName = array_map('trim', $branchesName);

        $references = $this->repository->getReferences();

        $branches = [];
        foreach ($branchesName as $branchName) {
            if (false === $local) {
                $branches[] = $references->getRemoteBranch($branchName);
            } elseif (0 === new CodePointString($branchName)->indexOfLast('remotes/')) {
                $branches[] = $references->getRemoteBranch(str_replace('remotes/', '', $branchName));
            } else {
                $branches[] = $references->getBranch($branchName);
            }
        }

        return $branches;
    }

    /**
     * Returns the author name.
     */
    public function getAuthorName(): string
    {
        $this->ensureLoaded();

        return $this->authorName ?? throw new ReferenceNotFoundException($this->revision);
    }

    /**
     * Returns the author email.
     */
    public function getAuthorEmail(): string
    {
        $this->ensureLoaded();

        return $this->authorEmail ?? throw new ReferenceNotFoundException($this->revision);
    }

    /**
     * Returns the authoring date.
     */
    public function getAuthorDate(): \DateTime
    {
        $this->ensureLoaded();

        return $this->authorDate ?? throw new ReferenceNotFoundException($this->revision);
    }

    /**
     * Returns the committer name.
     */
    public function getCommitterName(): string
    {
        $this->ensureLoaded();

        return $this->committerName ?? throw new ReferenceNotFoundException($this->revision);
    }

    /**
     * Returns the comitter email.
     */
    public function getCommitterEmail(): string
    {
        $this->ensureLoaded();

        return $this->committerEmail ?? throw new ReferenceNotFoundException($this->revision);
    }

    /**
     * Returns the authoring date.
     */
    public function getCommitterDate(): \DateTime
    {
        $this->ensureLoaded();

        return $this->committerDate ?? throw new ReferenceNotFoundException($this->revision);
    }

    /**
     * Returns the message of the commit.
     */
    public function getMessage(): string
    {
        $this->ensureLoaded();

        return $this->message ?? throw new ReferenceNotFoundException($this->revision);
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

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $parser = new Parser\CommitParser();

        try {
            $result = $this->repository->run('cat-file', ['commit', $this->revision]);
        } catch (ProcessException $e) {
            throw new ReferenceNotFoundException(\sprintf('Can not find reference "%s"', $this->revision));
        }

        if (null === $result) {
            throw new ReferenceNotFoundException(\sprintf('Can not find reference "%s"', $this->revision));
        }

        $parser->parse($result);

        $this->treeHash = $parser->tree;
        $this->parentHashes = $parser->parents;
        $this->authorName = $parser->authorName;
        $this->authorEmail = $parser->authorEmail;
        $this->authorDate = $parser->authorDate;
        $this->committerName = $parser->committerName;
        $this->committerEmail = $parser->committerEmail;
        $this->committerDate = $parser->committerDate;
        $this->message = $parser->message;

        $this->loaded = true;
    }

    private static function asString(string $name, mixed $value): string
    {
        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf('Commit data "%s" must be a string.', $name));
        }

        return $value;
    }

    private static function asDateTime(string $name, mixed $value): \DateTime
    {
        if (!$value instanceof \DateTime) {
            throw new InvalidArgumentException(\sprintf('Commit data "%s" must be a DateTime.', $name));
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private static function asStringList(string $name, mixed $value): array
    {
        if (!\is_array($value)) {
            throw new InvalidArgumentException(\sprintf('Commit data "%s" must be an array.', $name));
        }

        $result = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new InvalidArgumentException(\sprintf('Commit data "%s" must be an array of strings.', $name));
            }

            $result[] = $item;
        }

        return $result;
    }
}
