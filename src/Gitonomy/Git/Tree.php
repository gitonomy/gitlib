<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

class Tree
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

    public function getHash()
    {
        return $this->hash;
    }

    protected function initialize()
    {
        if (true === $this->isInitialized) {
            return;
        }

        ob_start();
        system(sprintf(
            'cd %s && git cat-file -p %s',
            escapeshellarg($this->repository->getPath()),
            escapeshellarg($this->hash)
        ), $return);
        $result = ob_get_clean();

        if (0 !== $return) {
            throw new \RuntimeException('Error while getting content of a commit');
        }

        $parser = new Parser\TreeParser();
        $parser->parse($result);

        $this->entries = array();

        foreach ($parser->entries as $entry) {
            list($mode, $type, $hash, $name) = $entry;
            if ($type == 'blob') {
                $this->entries[$name] = array($mode, $this->repository->getBlob($hash));
            } else {
                $this->entries[$name] = array($mode, $this->repository->getTree($hash));
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
            throw new \InvalidArgumentException('No entry '.$name);
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
            if ($element instanceof Tree) {
                $element = $element->getEntry($segment);
            } elseif ($entry instanceof Blob) {
                throw new \InvalidArgumentException('Unresolvable path');
            } else {
                throw new \RuntimeException('Unknow type of element');
            }
        }

        return $element;
    }
}
