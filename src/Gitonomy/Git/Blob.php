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
     * @var int Size that git uses to look for NULL byte: https://git.kernel.org/pub/scm/git/git.git/tree/xdiff-interface.c?h=v2.44.0#n193
     */
    private const FIRST_FEW_BYTES = 8000;

    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var string
     */
    protected $hash;

    /**
     * @var string
     */
    protected $content;

    /**
     * @var string
     */
    protected $mimetype;

    /**
     * @var bool
     */
    protected $text;

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
     * @throws ProcessException Error occurred while getting content of blob
     *
     * @return string Content of the blob.
     */
    public function getContent()
    {
        if (null === $this->content) {
            $this->content = $this->repository->run('cat-file', ['-p', $this->hash]);
        }

        return $this->content;
    }

    /**
     * Determine the mimetype of the blob.
     *
     * @return string A mimetype
     */
    public function getMimetype()
    {
        if (null === $this->mimetype) {
            $finfo = new \finfo(FILEINFO_MIME);
            $this->mimetype = $finfo->buffer($this->getContent());
        }

        return $this->mimetype;
    }

    /**
     * Determines if file is binary.
     *
     * Uses the same check that git uses to determine if a file is binary or not
     * https://git.kernel.org/pub/scm/git/git.git/tree/xdiff-interface.c?h=v2.44.0#n193
     *
     * @return bool
     */
    public function isBinary()
    {
        return !$this->isText();
    }

    /**
     * Determines if file is text.
     *
     * Uses the same check that git uses to determine if a file is binary or not
     * https://git.kernel.org/pub/scm/git/git.git/tree/xdiff-interface.c?h=v2.44.0#n193
     *
     * @return bool
     */
    public function isText()
    {
        if (null === $this->text) {
            $this->text = !str_contains(substr($this->getContent(), 0, self::FIRST_FEW_BYTES), chr(0));
        }

        return $this->text;
    }
}
