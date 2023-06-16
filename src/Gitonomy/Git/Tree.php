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
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\UnexpectedValueException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Tree
{
    protected $repository;

    /**
     * @var string
     */
    protected $hash;

    /**
     * @var bool
     */
    protected $isInitialized = false;

    /**
     * @var array
     */
    protected $entries;

    public function __construct(Repository $repository, $hash)
    {
        $this->repository = $repository;
        $this->hash = $hash;
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @throws ProcessException Error while executing git command (debug-mode only)
     *                          or when there are Problems with executing the Process
     */
    protected function initialize()
    {
        if (true === $this->isInitialized) {
            return;
        }

        $output = $this->repository->run('cat-file', ['-p', $this->hash]);
        $parser = new Parser\TreeParser();
        $parser->parse($output);

        $this->entries = [];

        foreach ($parser->entries as $entry) {
            list($mode, $type, $hash, $name) = $entry;
            if ($type == 'blob') {
                $this->entries[$name] = [$mode, $this->repository->getBlob($hash)];
            } elseif ($type == 'tree') {
                $this->entries[$name] = [$mode, $this->repository->getTree($hash)];
            } else {
                $this->entries[$name] = [$mode, new CommitReference($hash)];
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

    /**
     * @param string $name
     *
     * @throws InvalidArgumentException No entry found
     *
     * @return Blob
     */
    public function getEntry($name)
    {
        $this->initialize();

        if (!isset($this->entries[$name])) {
            throw new InvalidArgumentException('No entry '.$name);
        }

        return $this->entries[$name][1];
    }

    /**
     * @param string $path
     *
     * @throws InvalidArgumentException Unresolvable path
     * @throws UnexpectedValueException Unknow type of element
     *
     * @return Tree
     */
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
}
