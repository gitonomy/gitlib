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
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Parser\ReferenceParser;
use Gitonomy\Git\Parser\TagParser;
use Gitonomy\Git\Reference;

/**
 * Representation of a tag reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 * @author Bruce Wells <brucekwells@gmail.com>
 */
final class Tag extends Reference
{
    private bool $loaded = false;

    private ?string $taggerName = null;
    private ?string $taggerEmail = null;
    private ?\DateTime $taggerDate = null;
    private ?string $message = null;
    private ?string $gpgSignature = null;

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
        try {
            $result = $this->repository->run('cat-file', ['tag', $this->revision]);
        } catch (ProcessException $e) {
            return false; // Is not an annotated tag
        }

        // In non-debug mode, a failed command returns null instead of throwing.
        return null !== $result;
    }

    /**
     * Returns the actual commit associated with the tag, and not the hash of the tag if annotated.
     */
    public function getCommit(): Commit
    {
        if ($this->isAnnotated()) {
            try {
                $output = $this->repository->run('show-ref', ['-d', '--tag', $this->revision]);
                $parser = new ReferenceParser();
                $parser->parse($output);

                $commitHash = null;
                foreach ($parser->references as [$row]) {
                    $commitHash = $row;
                }

                if (null !== $commitHash) {
                    return $this->repository->getCommit($commitHash);
                }
            } catch (ProcessException $e) {
                // ignore the exception
            }
        }

        return parent::getCommit();
    }

    /**
     * Returns the tagger name.
     */
    public function getTaggerName(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }
        $this->ensureLoaded();

        return $this->taggerName ?? throw new \InvalidArgumentException('No data named "taggerName" in Tag.');
    }

    /**
     * Returns the comitter email.
     */
    public function getTaggerEmail(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }
        $this->ensureLoaded();

        return $this->taggerEmail ?? throw new \InvalidArgumentException('No data named "taggerEmail" in Tag.');
    }

    /**
     * Returns the authoring date.
     */
    public function getTaggerDate(): \DateTime|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }
        $this->ensureLoaded();

        return $this->taggerDate ?? throw new \InvalidArgumentException('No data named "taggerDate" in Tag.');
    }

    /**
     * Returns the message of the commit.
     */
    public function getMessage(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }
        $this->ensureLoaded();

        return $this->message ?? throw new \InvalidArgumentException('No data named "message" in Tag.');
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
            $lines = explode("\n", $this->getMessageOrThrow());
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
            $lines = explode("\n", $this->getMessageOrThrow());

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
     */
    public function getGPGSignature(): string|false
    {
        if (!$this->isAnnotated()) {
            return false;
        }
        $this->ensureLoaded();

        return $this->gpgSignature ?? throw new \InvalidArgumentException('No data named "gpgSignature" in Tag.');
    }

    /**
     * Check whether tag is signed.
     */
    public function isSigned(): bool
    {
        try {
            $this->getGPGSignature();

            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    private function getMessageOrThrow(): string
    {
        $this->ensureLoaded();

        return $this->message ?? throw new \InvalidArgumentException('No data named "message" in Tag.');
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $parser = new TagParser();
        $result = $this->repository->run('cat-file', ['tag', $this->revision]);

        if (null === $result) {
            throw new \InvalidArgumentException('Unable to read tag data.');
        }

        $parser->parse($result);

        $this->taggerName = $parser->taggerName;
        $this->taggerEmail = $parser->taggerEmail;
        $this->taggerDate = $parser->taggerDate;
        $this->message = $parser->message;
        $this->gpgSignature = $parser->gpgSignature;

        $this->loaded = true;
    }
}
