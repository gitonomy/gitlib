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
 * Represents the actual git tree object, not dependent of the current path.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class TreeObject
{
    protected $repository;
    protected $hash;
    protected $isInitialized = false;
    protected $entries;

    public function __construct(Repository $repository, $hash)
    {
        $this->repository = $repository;
        $this->hash = $hash;
    }

    /**
     * @return Repository
     */
    public function getRepository()
    {
        return $this->repository;
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

        $output = $this->repository->run('cat-file', array('-p', $this->hash));
        $parser = new Parser\TreeParser();
        $parser->parse($output);

        $this->entries = array();

        foreach ($parser->entries as $entry) {
            list($mode, $type, $hash, $name) = $entry;
            if ($type == 'blob') {
                $this->entries[$name] = array($mode, $this->repository->getBlob($hash));
            } elseif ($type == 'tree') {
                $this->entries[$name] = array($mode, $this->repository->getTree($hash));
            } else {
                $this->entries[$name] = array($mode, new CommitReference($hash));
            }
        }

        $this->isInitialized = true;
    }

    /**
     * @return array An associative array name => $object
     */
    public function getEntries()
    {
        $this->initialize();

        return $this->entries;
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
            if ($element instanceof TreeObject) {
                $element = $element->getEntry($segment);
            } elseif ($element instanceof BlobObject) {
                throw new InvalidArgumentException('Unresolvable path');
            } else {
                throw new UnexpectedValueException('Unknow type of element');
            }
        }

        return $element;
    }
}
