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
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Stash;
use Gitonomy\Git\Reference\Tag;

/**
 * Reference set associated to a repository.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Julien DIDIER <genzo.wm@gmail.com>
 *
 * @implements \IteratorAggregate<string, Reference>
 */
final class ReferenceBag implements \Countable, \IteratorAggregate
{
    /**
     * The references, by full name: null until read, and after every write.
     *
     * @var array<string, Reference>|null
     */
    private ?array $references = null;

    /**
     * @internal Get it from Repository::getReferences(), which gitlib reads again after its writes
     */
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
        return $this->references()[$fullname] ?? throw new ReferenceNotFoundException(\sprintf('The reference "%s" does not exist.', $fullname));
    }

    public function has(string $fullname): bool
    {
        return isset($this->references()[$fullname]);
    }

    /**
     * @template T of Reference
     *
     * @param T $reference
     *
     * @return T
     *
     * @deprecated since gitonomy/gitlib 1.7, use Branch::moveTo() instead
     */
    public function update(Reference $reference): Reference
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::moveTo()" instead.', __METHOD__, Branch::class);

        $this->repository->getRepositoryDriver()->updateReference($reference->getFullname(), $reference->getCommitHash());
        $this->invalidate();

        return $reference;
    }

    /**
     * @throws InvalidArgumentException The branch exists already
     */
    public function createBranch(string $name, Revision|string $revision): Branch
    {
        $this->create('refs/heads/'.$name, $revision);

        return $this->getBranch($name);
    }

    /**
     * Creates a lightweight tag.
     *
     * @throws InvalidArgumentException The tag exists already
     */
    public function createTag(string $name, Revision|string $revision): Tag
    {
        $this->create('refs/tags/'.$name, $revision);

        return $this->getTag($name);
    }

    /**
     * Like `git update-ref -d`, deleting a missing reference does nothing.
     *
     * @param string $fullname fullname of the reference (refs/heads/master, for example)
     *
     * @throws InvalidArgumentException $fullname isn't the full name of a reference under "refs/", like "HEAD" or "master"
     */
    public function delete(string $fullname): void
    {
        $this->repository->getRepositoryDriver()->deleteReference($fullname);
        $this->invalidate();
    }

    public function hasBranches(): bool
    {
        return [] !== $this->getBranches();
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

    public function getFirstBranch(): ?Branch
    {
        return $this->getBranches()[0] ?? null;
    }

    /**
     * @return Tag[] The tags pointing to $commit, annotated or not
     */
    public function resolveTags(Commit|string $commit): array
    {
        return array_values(array_filter($this->resolve($commit), static fn (Reference $reference): bool => $reference instanceof Tag));
    }

    /**
     * @return Branch[] The branches pointing to $commit
     */
    public function resolveBranches(Commit|string $commit): array
    {
        return array_values(array_filter($this->resolve($commit), static fn (Reference $reference): bool => $reference instanceof Branch));
    }

    /**
     * @return Reference[] The references pointing to $commit, through annotated tags
     */
    public function resolve(Commit|string $commit): array
    {
        $hash = match (true) {
            $commit instanceof Commit => $commit->getHash(),
            1 === preg_match('/^[0-9a-f]{40}$/', $commit) => $commit,
            default => $this->repository->getRevision($commit)->getCommit()->getHash(),
        };

        return array_values(array_filter($this->references(), static fn (Reference $reference): bool => $reference->getCommitHash() === $hash));
    }

    /**
     * @return Tag[] all tags
     */
    public function getTags(): array
    {
        return array_values(array_filter($this->references(), static fn (Reference $reference): bool => $reference instanceof Tag));
    }

    /**
     * @return Branch[] all branches
     */
    public function getBranches(): array
    {
        return array_values(array_filter($this->references(), static fn (Reference $reference): bool => $reference instanceof Branch));
    }

    /**
     * @return Branch[] all local branches
     */
    public function getLocalBranches(): array
    {
        return array_values(array_filter($this->getBranches(), static fn (Branch $branch): bool => $branch->isLocal()));
    }

    /**
     * @return Branch[] all remote branches
     */
    public function getRemoteBranches(): array
    {
        return array_values(array_filter($this->getBranches(), static fn (Branch $branch): bool => $branch->isRemote()));
    }

    /**
     * @return array<string, Reference> An associative array with fullname as key (refs/heads/master, refs/tags/0.1)
     */
    public function getAll(): array
    {
        return $this->references();
    }

    public function getTag(string $name): Tag
    {
        return $this->getAs('refs/tags/'.$name, Tag::class);
    }

    public function getBranch(string $name): Branch
    {
        return $this->getAs('refs/heads/'.$name, Branch::class);
    }

    public function getRemoteBranch(string $name): Branch
    {
        return $this->getAs('refs/remotes/'.$name, Branch::class);
    }

    /**
     * @see Countable
     */
    public function count(): int
    {
        return \count($this->references());
    }

    /**
     * @see IteratorAggregate
     *
     * @return \ArrayIterator<string, Reference>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->references());
    }

    /**
     * Reads the references again when next needed: gitlib calls it after
     * every write, through this bag or not (checkout, merge, run()...).
     *
     * @internal
     */
    public function invalidate(): void
    {
        $this->references = null;
    }

    /**
     * @throws InvalidArgumentException $fullname exists already
     */
    private function create(string $fullname, Revision|string $revision): void
    {
        if ($this->has($fullname)) {
            throw new InvalidArgumentException(\sprintf('The reference "%s" exists already.', $fullname));
        }

        $hash = ($revision instanceof Revision ? $revision : $this->repository->getRevision($revision))->getCommit()->getHash();
        $this->repository->getRepositoryDriver()->createReference($fullname, $hash);
        $this->invalidate();
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
            throw new ReferenceNotFoundException(\sprintf('The reference "%s" does not exist.', $fullname));
        }

        return $reference;
    }

    /**
     * @return array<string, Reference>
     */
    private function references(): array
    {
        if (null !== $this->references) {
            return $this->references;
        }

        $references = [];
        foreach ($this->repository->getRepositoryDriver()->getReferences() as $data) {
            $fullname = $data->fullname;

            if (str_starts_with($fullname, 'refs/heads/') || str_starts_with($fullname, 'refs/remotes/')) {
                // The default branch of a remote, a symbolic reference to one of its branches; a remote may be named "foo/bar".
                if (str_ends_with($fullname, '/HEAD') && str_starts_with($fullname, 'refs/remotes/')) {
                    continue;
                }
                $references[$fullname] = new Branch($this->repository, $fullname, $data->commitHash);
            } elseif (str_starts_with($fullname, 'refs/tags/')) {
                $references[$fullname] = new Tag($this->repository, $fullname, $data->commitHash, $data->tagHash);
            } elseif ('refs/stash' === $fullname) {
                $references[$fullname] = new Stash($this->repository, $fullname, $data->commitHash);
            }
        }

        return $this->references = $references;
    }
}
