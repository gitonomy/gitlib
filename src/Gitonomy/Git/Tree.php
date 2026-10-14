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
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Tree
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
     * @return array<string, TreeEntry> By name
     */
    public function getEntries(): array
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
     * @return array<string, TreeEntry> The submodules, by name
     */
    public function getSubmoduleEntries(): array
    {
        return array_filter($this->getEntries(), static fn (TreeEntry $entry): bool => $entry->getObject() instanceof Submodule);
    }

    /**
     * @return array<string, TreeEntry> The submodules, by name
     *
     * @deprecated since gitonomy/gitlib 2.0, use getSubmoduleEntries() instead
     */
    public function getCommitReferenceEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "getSubmoduleEntries()" instead.', __METHOD__);

        return $this->getSubmoduleEntries();
    }

    /**
     * @return array<string, TreeEntry> The trees, by name
     */
    public function getTreeEntries(): array
    {
        return array_filter($this->getEntries(), static fn (TreeEntry $entry): bool => $entry->getObject() instanceof self);
    }

    /**
     * @return array<string, TreeEntry> The blobs, by name
     */
    public function getBlobEntries(): array
    {
        return array_filter($this->getEntries(), static fn (TreeEntry $entry): bool => $entry->getObject() instanceof Blob);
    }

    /**
     * @throws InvalidArgumentException The tree has no entry $name
     */
    public function getEntry(string $name): Submodule|self|Blob
    {
        return ($this->getEntries()[$name] ?? throw new InvalidArgumentException(\sprintf('The tree "%s" has no entry "%s".', $this->hash, $name)))->getObject();
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
            $element = ($element->getEntries()[$segment] ?? throw new InvalidArgumentException(\sprintf('Unable to resolve "%s": "%s" doesn\'t exist.', $path, implode('/', $resolved))))->getObject();
        }

        return $element;
    }
}
