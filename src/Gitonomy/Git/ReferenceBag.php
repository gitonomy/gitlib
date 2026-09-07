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

use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Stash;
use Gitonomy\Git\Reference\Tag;

/**
 * Reference set associated to a repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Julien DIDIER <genzo.wm@gmail.com>
 */
final class ReferenceBag implements \Countable, \IteratorAggregate
{
    /**
     * Associative array of fullname references.
     *
     * @var array<string, Reference>
     */
    private array $references = [];

    /**
     * List with all tags.
     *
     * @var Tag[]
     */
    private array $tags = [];

    /**
     * List with all branches.
     *
     * @var Branch[]
     */
    private array $branches = [];

    /**
     * A boolean indicating if the bag is already initialized.
     */
    private bool $initialized = false;

    public function __construct(
        private readonly Repository $repository,
    ) {
    }

    /**
     * Returns a reference, by name.
     *
     * @param string $fullname fullname of the reference (refs/heads/master, for example)
     */
    public function get(string $fullname): Reference
    {
        $this->initialize();

        if (!isset($this->references[$fullname])) {
            throw new ReferenceNotFoundException($fullname);
        }

        return $this->references[$fullname];
    }

    public function has(string $fullname): bool
    {
        $this->initialize();

        return isset($this->references[$fullname]);
    }

    /**
     * @template T of Reference
     *
     * @param T $reference
     *
     * @return T
     */
    public function update(Reference $reference): Reference
    {
        $fullname = $reference->getFullname();

        $this->initialize();
        $this->repository->run('update-ref', [$fullname, $reference->getCommitHash()]);

        $this->references[$fullname] = $reference;

        return $reference;
    }

    public function createBranch(string $name, string $commitHash): Branch
    {
        $branch = new Branch($this->repository, 'refs/heads/'.$name, $commitHash);

        return $this->update($branch);
    }

    public function createTag(string $name, string $commitHash): Tag
    {
        $tag = new Tag($this->repository, 'refs/tags/'.$name, $commitHash);

        return $this->update($tag);
    }

    public function delete(string $fullname): void
    {
        $this->repository->run('update-ref', ['-d', $fullname]);

        unset($this->references[$fullname]);
    }

    public function hasBranches(): bool
    {
        $this->initialize();

        return \count($this->branches) > 0;
    }

    public function hasBranch(string $name): bool
    {
        return $this->has('refs/heads/'.$name);
    }

    public function hasRemoteBranch(string $name): bool
    {
        return $this->has('refs/remotes/'.$name);
    }

    public function hasTag(string $name): bool
    {
        return $this->has('refs/tags/'.$name);
    }

    public function getFirstBranch(): Reference|false
    {
        $this->initialize();
        reset($this->branches);

        return current($this->references);
    }

    /**
     * @return Tag[] An array of Tag objects
     */
    public function resolveTags(Commit|string $hash): array
    {
        $this->initialize();

        if ($hash instanceof Commit) {
            $hash = $hash->getHash();
        }

        $tags = [];
        foreach ($this->references as $reference) {
            if ($reference instanceof Tag && $reference->getCommitHash() === $hash) {
                $tags[] = $reference;
            }
        }

        return $tags;
    }

    /**
     * @return Branch[] An array of Branch objects
     */
    public function resolveBranches(Commit|string $hash): array
    {
        $this->initialize();

        if ($hash instanceof Commit) {
            $hash = $hash->getHash();
        }

        $branches = [];
        foreach ($this->references as $reference) {
            if ($reference instanceof Branch && $reference->getCommitHash() === $hash) {
                $branches[] = $reference;
            }
        }

        return $branches;
    }

    /**
     * @return Reference[] An array of references
     */
    public function resolve(Commit|string $hash): array
    {
        $this->initialize();

        if ($hash instanceof Commit) {
            $hash = $hash->getHash();
        }

        $result = [];
        foreach ($this->references as $k => $reference) {
            if ($reference->getCommitHash() === $hash) {
                $result[] = $reference;
            }
        }

        return $result;
    }

    /**
     * @return Tag[] all tags
     */
    public function getTags(): array
    {
        $this->initialize();

        return $this->tags;
    }

    /**
     * @return Branch[] all branches
     */
    public function getBranches(): array
    {
        $this->initialize();

        $result = [];
        foreach ($this->references as $reference) {
            if ($reference instanceof Branch) {
                $result[] = $reference;
            }
        }

        return $result;
    }

    /**
     * @return Branch[] all local branches
     */
    public function getLocalBranches(): array
    {
        $result = [];
        foreach ($this->getBranches() as $branch) {
            if ($branch->isLocal()) {
                $result[] = $branch;
            }
        }

        return $result;
    }

    /**
     * @return Branch[] all remote branches
     */
    public function getRemoteBranches(): array
    {
        $result = [];
        foreach ($this->getBranches() as $branch) {
            if ($branch->isRemote()) {
                $result[] = $branch;
            }
        }

        return $result;
    }

    /**
     * @return array<string, Reference> An associative array with fullname as key (refs/heads/master, refs/tags/0.1)
     */
    public function getAll(): array
    {
        $this->initialize();

        return $this->references;
    }

    public function getTag(string $name): Tag
    {
        $this->initialize();

        return $this->getAs('refs/tags/'.$name, Tag::class);
    }

    public function getBranch(string $name): Branch
    {
        $this->initialize();

        return $this->getAs('refs/heads/'.$name, Branch::class);
    }

    public function getRemoteBranch(string $name): Branch
    {
        $this->initialize();

        return $this->getAs('refs/remotes/'.$name, Branch::class);
    }

    /**
     * @see Countable
     */
    public function count(): int
    {
        $this->initialize();

        return \count($this->references);
    }

    /**
     * @see IteratorAggregate
     */
    public function getIterator(): \ArrayIterator
    {
        $this->initialize();

        return new \ArrayIterator($this->references);
    }

    /**
     * @template T of Reference
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function getAs(string $fullname, string $class): Reference
    {
        $reference = $this->get($fullname);

        if (!$reference instanceof $class) {
            throw new ReferenceNotFoundException($fullname);
        }

        return $reference;
    }

    private function initialize(): void
    {
        if (true === $this->initialized) {
            return;
        }
        $this->initialized = true;

        try {
            $parser = new Parser\ReferenceParser();
            $output = $this->repository->run('show-ref');
        } catch (RuntimeException $e) {
            return;
        }
        $parser->parse($output);

        foreach ($parser->references as $row) {
            [$commitHash, $fullname] = $row;

            if (preg_match('#^refs/(heads|remotes)/(.*)$#', $fullname)) {
                if (preg_match('#.*HEAD$#', $fullname)) {
                    continue;
                }
                $reference = new Branch($this->repository, $fullname, $commitHash);
                $this->references[$fullname] = $reference;
                $this->branches[] = $reference;
            } elseif (preg_match('#^refs/tags/(.*)$#', $fullname)) {
                $reference = new Tag($this->repository, $fullname, $commitHash);
                $this->references[$fullname] = $reference;
                $this->tags[] = $reference;
            } elseif ('refs/stash' === $fullname) {
                $reference = new Stash($this->repository, $fullname, $commitHash);
                $this->references[$fullname] = $reference;
            }
        }
    }
}
