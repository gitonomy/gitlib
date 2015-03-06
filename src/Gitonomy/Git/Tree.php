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

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Tree
{
    /**
     * @var TreeObject
     */
    protected $object;

    /**
     * @var string
     */
    protected $path;

    /**
     * @var array
     */
    protected $entries;

    public function __construct(TreeObject $object, $path)
    {
        $this->object = $object;
        $this->path   = $path;
    }

    /**
     * Returns path to this tree entry.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @see Tree:getHash
     */
    public function getHash()
    {
        return $this->object->getHash();
    }

    /**
     * @see Tree::getEntries
     */
    public function getEntries()
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = $this->object->getEntries();
        $this->entries = array();

        foreach ($entries as $name => $entry) {
            $this->entries[$name] = array(
                $entry[0],
                $this->object->getRepository()->getResolved($entry[1], $this->path.'/'.$name)
            );
        }

        return $this->entries;
    }

    /**
     * @see Tree::getEntry
     */
    public function getEntry($name)
    {
        $entries = $this->getEntries();

        if (!isset($entries[$name])) {
            throw new InvalidArgumentException('No entry '.$name);
        }

        return $entries[$name][1];
    }

    /**
     * @see Tree::resolvePath($path)
     */
    public function resolvePath($path)
    {
        $object = $this->object->resolvePath($path);

        return $this->object->getRepository()->getResolved($object, $this->path.'/'.$path);
    }
}
