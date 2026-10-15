<?php

/**
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
use Gitonomy\Git\Exception\UnexpectedValueException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 *
 * @implements \IteratorAggregate<string, TreeEntry>
 */
class Tree implements \IteratorAggregate
{
    protected $repository;
    protected $hash;
    protected $isInitialized = false;
    protected $entries;
    protected $entriesByType;

    /**
     * In gitlib 2.0, the constructor is internal: get it from Repository::getTree() or Commit::getTree().
     */
    public function __construct(Repository $repository, $hash)
    {
        $this->repository = $repository;
        $this->hash = $hash;
    }

    public function getHash()
    {
        return $this->hash;
    }

    protected function initialize()
    {
        if (true === $this->isInitialized) {
            return;
        }

        $output = $this->repository->run('cat-file', ['-p', $this->hash]);
        $parser = new Parser\TreeParser();
        $parser->parse($output);

        $this->entries = [];
        $this->entriesByType = [
            'blob'   => [],
            'tree'   => [],
            'commit' => [],
        ];

        foreach ($parser->entries as $entry) {
            list($mode, $type, $hash, $name) = $entry;
            if ($type == 'blob') {
                $treeEntry = [$mode, $this->repository->getBlob($hash)];
            } elseif ($type == 'tree') {
                $treeEntry = [$mode, $this->repository->getTree($hash)];
            } else {
                $treeEntry = [$mode, new CommitReference($hash)];
            }
            $this->entries[$name] = $treeEntry;
            $this->entriesByType[$type][$name] = $treeEntry;
        }

        $this->isInitialized = true;
    }

    /**
     * @return \ArrayIterator<string, TreeEntry> The entries, by name
     */
    public function getIterator(): \ArrayIterator
    {
        $this->initialize();

        return new \ArrayIterator(self::treeEntries($this->entries));
    }

    /**
     * @return array<string, TreeEntry> The blobs, by name
     */
    public function getBlobs(): array
    {
        $this->initialize();

        return self::treeEntries($this->entriesByType['blob']);
    }

    /**
     * @return array<string, TreeEntry> The submodules, by name
     */
    public function getSubmodules(): array
    {
        $this->initialize();

        return self::treeEntries($this->entriesByType['commit']);
    }

    /**
     * @return array<string, TreeEntry> The trees, by name
     */
    public function getTrees(): array
    {
        $this->initialize();

        return self::treeEntries($this->entriesByType['tree']);
    }

    /**
     * @return array<string, array{string, CommitReference|Tree|Blob}> An associative array name => [mode, object], a CommitReference being a Submodule
     *
     * @deprecated since gitonomy/gitlib 1.7, iterate the tree instead
     */
    public function getEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, iterate the tree instead.', __METHOD__);

        $this->initialize();

        return $this->entries;
    }

    /**
     * @return array<string, array{string, CommitReference}> An associative array of name => [mode, commit reference]
     *
     * @deprecated since gitonomy/gitlib 1.7, use getSubmodules() instead
     */
    public function getCommitReferenceEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getSubmodules()" instead.', __METHOD__);

        $this->initialize();

        return $this->entriesByType['commit'];
    }

    /**
     * @return array<string, array{string, Tree}> An associative array of name => [mode, tree]
     *
     * @deprecated since gitonomy/gitlib 1.7, use getTrees() instead
     */
    public function getTreeEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getTrees()" instead.', __METHOD__);

        $this->initialize();

        return $this->entriesByType['tree'];
    }

    /**
     * @return array<string, array{string, Blob}> An associative array of name => [mode, blob]
     *
     * @deprecated since gitonomy/gitlib 1.7, use getBlobs() instead
     */
    public function getBlobEntries(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getBlobs()" instead.', __METHOD__);

        $this->initialize();

        return $this->entriesByType['blob'];
    }

    public function getEntry($name)
    {
        $this->initialize();

        if (!isset($this->entries[$name])) {
            throw new InvalidArgumentException('No entry '.$name);
        }

        return $this->entries[$name][1];
    }

    public function resolvePath($path)
    {
        if ($path == '') {
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

    /**
     * @param array<string, array{string, CommitReference|Tree|Blob}> $entries
     *
     * @return array<string, TreeEntry>
     */
    private static function treeEntries(array $entries): array
    {
        $treeEntries = [];
        foreach ($entries as $name => [$mode, $object]) {
            $treeEntries[$name] = new TreeEntry((string) $name, $mode, $object);
        }

        return $treeEntries;
    }
}
