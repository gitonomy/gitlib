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

/**
 * Representation of a Blob commit.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Blob
{
    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var string
     */
    protected $hash;

    /**
     * @var boolean
     */
    protected $initialized = false;

    /**
     * @var string
     */
    protected $content;

    /**
     * @param Repository $repository Repository where the blob is located
     * @param string     $hash       Hash of the blob
     */
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
     * @throws RuntimeException Error occurred while getting content of blob
     */
    private function initialize()
    {
        if (true === $this->initialized) {
            return;
        }

        $process = $this->repository->getProcess('cat-file', array('-p', $this->hash));

        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Error while getting content of a blob : '.$process->getErrorOutput());
        }

        $this->content = $process->getOutput();
    }

    /**
     * Returns content of the blob.
     *
     * @throws RuntimeException Error occurred while getting content of blob
     */
    public function getContent()
    {
        $this->initialize();

        return $this->content;
    }
}
