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

use Gitonomy\Git\Driver\Data\ObjectType;
use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * Iterating a tree gives its entries, by name.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @implements \IteratorAggregate<string, TreeEntry>
 */
final class Tree implements \IteratorAggregate
{
    /**
     * @var array<string, TreeEntry>|null By name, null until read
     */
    private ?array $entries = null;

    /**
     * @internal Get it from Repository::getTree(), which returns the same object while the application uses it
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly string $hash,
    ) {
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * @return \ArrayIterator<string, TreeEntry> The entries, by name
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries());
    }

    /**
     * @return array<string, TreeEntry> The blobs, by name
     */
    public function getBlobs(): array
    {
        return array_filter($this->entries(), static fn (TreeEntry $entry): bool => $entry->getObject() instanceof Blob);
    }

    /**
     * @return array<string, TreeEntry> The submodules, by name
     */
    public function getSubmodules(): array
    {
        return array_filter($this->entries(), static fn (TreeEntry $entry): bool => $entry->getObject() instanceof Submodule);
    }

    /**
     * @return array<string, TreeEntry> The trees, by name
     */
    public function getTrees(): array
    {
        return array_filter($this->entries(), static fn (TreeEntry $entry): bool => $entry->getObject() instanceof self);
    }

    /**
     * @return array<string, array{string, Blob|Tree|Submodule}> The mode and the object of each entry, by name
     *
     * @deprecated since gitonomy/gitlib 1.7, iterate the tree instead
     */
    public function getEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, iterate the tree instead.', __METHOD__);

        return self::toArrays($this->entries());
    }

    /**
     * @return array<string, array{string, Blob}> The mode and the blob of each blob, by name
     *
     * @deprecated since gitonomy/gitlib 1.7, use getBlobs() instead
     */
    public function getBlobEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getBlobs()" instead.', __METHOD__);

        // @phpstan-ignore return.type (getBlobs() returns blobs only.)
        return self::toArrays($this->getBlobs());
    }

    /**
     * @return array<string, array{string, Submodule}> The mode and the submodule of each submodule, by name
     *
     * @deprecated since gitonomy/gitlib 1.7, use getSubmodules() instead
     */
    public function getCommitReferenceEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getSubmodules()" instead.', __METHOD__);

        // @phpstan-ignore return.type (getSubmodules() returns submodules only.)
        return self::toArrays($this->getSubmodules());
    }

    /**
     * @return array<string, array{string, Tree}> The mode and the tree of each tree, by name
     *
     * @deprecated since gitonomy/gitlib 1.7, use getTrees() instead
     */
    public function getTreeEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getTrees()" instead.', __METHOD__);

        // @phpstan-ignore return.type (getTrees() returns trees only.)
        return self::toArrays($this->getTrees());
    }

    /**
     * @throws InvalidArgumentException The tree has no entry $name
     */
    public function getEntry(string $name): Submodule|self|Blob
    {
        return ($this->entries()[$name] ?? throw new InvalidArgumentException(\sprintf('The tree "%s" has no entry "%s".', $this->hash, $name)))->getObject();
    }

    /**
     * @param string $path Relative to the tree, "" or "/" for the tree itself
     *
     * @throws InvalidArgumentException $path doesn't exist in the tree
     */
    public function resolvePath(string $path): Submodule|self|Blob
    {
        $element = $this;
        $resolved = [];
        foreach (array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment) as $segment) {
            if (!$element instanceof self) {
                throw new InvalidArgumentException(\sprintf('Unable to resolve "%s": "%s" is a %s, not a tree.', $path, implode('/', $resolved), $element instanceof Blob ? 'file' : 'submodule'));
            }

            $resolved[] = $segment;
            $element = ($element->entries()[$segment] ?? throw new InvalidArgumentException(\sprintf('Unable to resolve "%s": "%s" doesn\'t exist.', $path, implode('/', $resolved))))->getObject();
        }

        return $element;
    }

    /**
     * @return array<string, TreeEntry> By name
     */
    private function entries(): array
    {
        if (null !== $this->entries) {
            return $this->entries;
        }

        $this->entries = [];
        foreach ($this->repository->getRepositoryDriver()->getTreeEntries($this->hash) as $data) {
            $object = match ($data->type) {
                ObjectType::Blob => $this->repository->getBlob($data->hash),
                ObjectType::Tree => $this->repository->getTree($data->hash),
                // A commit in a tree is a submodule.
                default => new Submodule($data->hash),
            };
            $this->entries[$data->name] = new TreeEntry($data->name, $data->mode, $object);
        }

        return $this->entries;
    }

    /**
     * The entries in the format of 1.x.
     *
     * @param array<string, TreeEntry> $entries
     *
     * @return array<string, array{string, Blob|Tree|Submodule}>
     */
    private static function toArrays(array $entries): array
    {
        return array_map(static fn (TreeEntry $entry): array => [$entry->getMode(), $entry->getObject()], $entries);
    }
}
