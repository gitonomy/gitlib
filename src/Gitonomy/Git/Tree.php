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

use Gitonomy\Git\Driver\Data\TreeEntryType;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\UnexpectedValueException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Tree
{
    private bool $isInitialized = false;

    /**
     * @var array<string, array{string, CommitReference|Tree|Blob}>
     */
    private array $entries;

    /**
     * @var array{blob: array<string, array{string, Blob}>, tree: array<string, array{string, Tree}>, commit: array<string, array{string, CommitReference}>}
     */
    private array $entriesByType;

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
     * @return array<string, array{string, CommitReference|Tree|Blob}> An associative array name => $object
     */
    public function getEntries(): array
    {
        $this->initialize();

        return $this->entries;
    }

    /**
     * @return array<string, array{string, CommitReference}> An associative array of name => [mode, commit reference]
     */
    public function getCommitReferenceEntries(): array
    {
        $this->initialize();

        return $this->entriesByType['commit'];
    }

    /**
     * @return array<string, array{string, Tree}> An associative array of name => [mode, tree]
     */
    public function getTreeEntries(): array
    {
        $this->initialize();

        return $this->entriesByType['tree'];
    }

    /**
     * @return array<string, array{string, Blob}> An associative array of name => [mode, blob]
     */
    public function getBlobEntries(): array
    {
        $this->initialize();

        return $this->entriesByType['blob'];
    }

    public function getEntry(string $name): CommitReference|self|Blob
    {
        $this->initialize();

        if (!isset($this->entries[$name])) {
            throw new InvalidArgumentException('No entry '.$name);
        }

        return $this->entries[$name][1];
    }

    public function resolvePath(string $path): CommitReference|self|Blob
    {
        if ('' == $path) {
            return $this;
        }

        $path = preg_replace('#^/#', '', $path);

        $segments = explode('/', $path);
        $element = $this;
        foreach ($segments as $segment) {
            if ($element instanceof self) {
                $element = $element->getEntry($segment);
            } elseif ($element instanceof Blob) {
                throw new InvalidArgumentException('Unresolvable path');
            } else {
                throw new UnexpectedValueException('Unknow type of element');
            }
        }

        return $element;
    }

    private function initialize(): void
    {
        if (true === $this->isInitialized) {
            return;
        }

        $entries = $this->repository->getRepositoryDriver()->getTreeEntries($this->hash);

        $this->entries = [];
        $this->entriesByType = [
            'blob' => [],
            'tree' => [],
            'commit' => [],
        ];

        foreach ($entries as $entry) {
            if (TreeEntryType::Blob === $entry->type) {
                $treeEntry = [$entry->mode, $this->repository->getBlob($entry->hash)];
                $this->entriesByType['blob'][$entry->name] = $treeEntry;
            } elseif (TreeEntryType::Tree === $entry->type) {
                $treeEntry = [$entry->mode, $this->repository->getTree($entry->hash)];
                $this->entriesByType['tree'][$entry->name] = $treeEntry;
            } else {
                $treeEntry = [$entry->mode, new CommitReference($entry->hash)];
                $this->entriesByType['commit'][$entry->name] = $treeEntry;
            }
            $this->entries[$entry->name] = $treeEntry;
        }

        $this->isInitialized = true;
    }
}
