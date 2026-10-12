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

use Gitonomy\Git\Commit;
use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference;

/**
 * Representation of a tag reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Bruce Wells <brucekwells@gmail.com>
 */
final class Tag extends Reference
{
    /**
     * Raw annotated-tag data, as returned by the Driver. Lazily loaded.
     */
    private ?TagData $data = null;

    private ?bool $annotated = null;

    // Values derived from the fields above, computed and cached independently.
    private ?string $subjectMessage = null;
    private ?string $bodyMessage = null;

    public function getName(): string
    {
        if (!preg_match('#^refs/tags/(.*)$#', $this->revision, $vars)) {
            throw new RuntimeException(\sprintf('Cannot extract tag name from "%s"', $this->revision));
        }

        return $vars[1];
    }

    /**
     * Check if tag is annotated.
     */
    public function isAnnotated(): bool
    {
        return $this->annotated ??= 'tag' === $this->repository->getRepositoryDriver()->getObjectType($this->revision);
    }

    /**
     * Returns the actual commit associated with the tag, and not the hash of the tag if annotated.
     */
    public function getCommit(): Commit
    {
        try {
            $hash = $this->repository->getRepositoryDriver()->resolveRevision($this->revision.'^{commit}');
        } catch (DriverException $e) {
            throw new ReferenceNotFoundException(\sprintf('Can not find revision "%s"', $this->revision), 0, $e);
        }

        return $this->repository->getCommit($hash);
    }

    /**
     * Returns the tagger name.
     */
    public function getTaggerName(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }

        return $this->data()->taggerName;
    }

    /**
     * Returns the comitter email.
     */
    public function getTaggerEmail(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }

        return $this->data()->taggerEmail;
    }

    /**
     * Returns the authoring date.
     */
    public function getTaggerDate(): \DateTimeImmutable|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }

        return $this->data()->taggerDate;
    }

    /**
     * Returns the message of the commit.
     */
    public function getMessage(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }

        return $this->data()->message;
    }

    /**
     * Returns the subject message (the first line).
     */
    public function getSubjectMessage(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }

        if (null === $this->subjectMessage) {
            $lines = explode("\n", $this->data()->message);
            $this->subjectMessage = reset($lines);
        }

        return $this->subjectMessage;
    }

    /**
     * Return the body message.
     */
    public function getBodyMessage(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }

        if (null === $this->bodyMessage) {
            $lines = explode("\n", $this->data()->message);

            // Drop the subject line, then the blank separator line if the
            // message follows the "subject\n\nbody" convention.
            array_shift($lines);
            if (isset($lines[0]) && '' === $lines[0]) {
                array_shift($lines);
            }
            if ([] !== $lines && '' === end($lines)) {
                array_pop($lines);
            }

            $this->bodyMessage = implode("\n", $lines);
        }

        return $this->bodyMessage;
    }

    /**
     * Return the GPG signature.
     *
     * @throws InvalidArgumentException The tag isn't signed
     */
    public function getGPGSignature(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }

        return $this->data()->gpgSignature ?? throw new InvalidArgumentException(\sprintf('Tag "%s" is not signed.', $this->getName()));
    }

    /**
     * Check whether tag is signed.
     */
    public function isSigned(): bool
    {
        return $this->isAnnotated() && null !== $this->data()->gpgSignature;
    }

    private function data(): TagData
    {
        if (null === $this->data) {
            $this->data = $this->repository->getRepositoryDriver()->getTagData($this->revision);
        }

        return $this->data;
    }
}
