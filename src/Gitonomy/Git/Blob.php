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

/**
 * Representation of a Blob commit.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Blob
{
    /**
     * @var BlobObject
     */
    protected $object;

    /**
     * @var string
     */
    protected $path;

    /**
     * @param Repository $repository Repository where the blob is located
     * @param string     $path       Path of the blob
     */
    public function __construct(BlobObject $object, $path)
    {
        $this->object = $object;
        $this->path   = $path;
    }

    /**
     * Returns path to the blob.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @see Blob::getHash
     */
    public function getHash()
    {
        return $this->object->getHash();
    }

    /**
     * @see Blob::getContent
     */
    public function getContent()
    {
        return $this->object->getContent();
    }

    /**
     * @see Blob::getMimetype
     */
    public function getMimetype()
    {
        return $this->object->getMimetype();
    }

    /**
     * @see Blob::isBinary
     */
    public function isBinary()
    {
        return $this->object->isBinary();
    }

    /**
     * @see Blob::isText
     */
    public function isText()
    {
        return $this->object->isText();
    }
}
