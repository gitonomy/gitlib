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
}
