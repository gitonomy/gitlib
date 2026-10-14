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

namespace Gitonomy\Git\Reference;

use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Util\Message;

/**
 * Representation of a tag reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Bruce Wells <brucekwells@gmail.com>
 */
final class Tag extends Reference
{
    /**
     * The annotated tag object, read through the driver when needed.
     */
    private ?TagData $data = null;

    /**
     * @param string      $commitHash What the tag points to, through its annotated tag object
     * @param string|null $tagHash    The annotated tag object, null for a lightweight tag
     */
    public function __construct(
        Repository $repository,
        string $fullname,
        string $commitHash,
        private readonly ?string $tagHash = null,
    ) {
        parent::__construct($repository, $fullname, $commitHash);
    }

    public function getName(): string
    {
        if (!preg_match('#^refs/tags/(.*)$#', $this->revision, $vars)) {
            throw new RuntimeException(\sprintf('Cannot extract tag name from "%s".', $this->revision));
        }

        return $vars[1];
    }

    /**
     * Check if tag is annotated.
     */
    public function isAnnotated(): bool
    {
        return null !== $this->tagHash;
    }

    /**
     * @return string|null The name of the tagger, null for a lightweight tag or a tag without tagger
     */
    public function getTaggerName(): ?string
    {
        return $this->data()?->taggerName;
    }

    /**
     * @return string|null The email of the tagger, null for a lightweight tag or a tag without tagger
     */
    public function getTaggerEmail(): ?string
    {
        return $this->data()?->taggerEmail;
    }

    /**
     * @return \DateTimeImmutable|null The tagging date, at the UTC offset of the tagger, like git log shows it; null for a lightweight tag or a tag without tagger
     */
    public function getTaggerDate(): ?\DateTimeImmutable
    {
        return $this->data()?->taggerDate;
    }

    /**
     * @return string|null The message of the annotated tag, without its signature; null for a lightweight tag
     */
    public function getMessage(): ?string
    {
        return $this->data()?->message;
    }

    /**
     * @return string|null The first paragraph of the message, on one line, null for a lightweight tag
     */
    public function getSubjectMessage(): ?string
    {
        $message = $this->getMessage();

        return null === $message ? null : Message::subject($message);
    }

    /**
     * @return string|null What follows the subject and its empty lines, null for a lightweight tag
     */
    public function getBodyMessage(): ?string
    {
        $message = $this->getMessage();

        return null === $message ? null : Message::body($message);
    }

    /**
     * @return string|null The signature (GPG, X.509 or SSH) between its armor lines, null for an unsigned or lightweight tag
     */
    public function getGpgSignature(): ?string
    {
        return $this->data()?->gpgSignature;
    }

    public function isSigned(): bool
    {
        return null !== $this->getGpgSignature();
    }

    private function data(): ?TagData
    {
        if (null === $this->tagHash) {
            return null;
        }

        return $this->data ??= $this->repository->getRepositoryDriver()->getTagData($this->tagHash);
    }
}
