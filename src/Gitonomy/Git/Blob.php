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

use Gitonomy\Git\Exception\RuntimeException;

/**
 * Representation of a Blob commit.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Blob
{
    /**
     * Size that git uses to look for NULL byte: https://git.kernel.org/pub/scm/git/git.git/tree/xdiff-interface.c?h=v2.44.0#n193.
     */
    private const int FIRST_FEW_BYTES = 8000;

    private ?string $content = null;

    private ?string $mimetype = null;

    private ?bool $text = null;

    /**
     * @param Repository $repository Repository where the blob is located
     * @param string     $hash       Hash of the blob
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
     * @return string content of the blob
     *
     * @throws Exception\DriverException Error occurred while getting content of blob
     */
    public function getContent(): string
    {
        if (null === $this->content) {
            $this->content = $this->repository->getRepositoryDriver()->getBlobContent($this->hash);
        }

        return $this->content;
    }

    /**
     * @return string The mimetype of the content, like "text/plain"
     *
     * @throws RuntimeException fileinfo can't read the content
     */
    public function getMimetype(): string
    {
        return $this->mimetype ??= new \finfo(\FILEINFO_MIME_TYPE)->buffer($this->getContent()) ?: throw new RuntimeException(\sprintf('Unable to read the mimetype of the blob "%s".', $this->hash));
    }

    /**
     * Determines if file is binary.
     *
     * Uses the same check that git uses to determine if a file is binary or not
     * https://git.kernel.org/pub/scm/git/git.git/tree/xdiff-interface.c?h=v2.44.0#n193
     */
    public function isBinary(): bool
    {
        return !$this->isText();
    }

    /**
     * Determines if file is text.
     *
     * Uses the same check that git uses to determine if a file is binary or not
     * https://git.kernel.org/pub/scm/git/git.git/tree/xdiff-interface.c?h=v2.44.0#n193
     */
    public function isText(): bool
    {
        if (null === $this->text) {
            $this->text = !str_contains(substr($this->getContent(), 0, self::FIRST_FEW_BYTES), \chr(0));
        }

        return $this->text;
    }
}
