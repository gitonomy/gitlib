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

namespace Gitonomy\Git\Driver\Data;

/**
 * Raw data of an annotated tag object, as read by a Driver.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class TagData
{
    /**
     * The armors git signs tags with: GPG, then X.509 (gpgsm), then SSH.
     */
    private const string SIGNATURE = '-----BEGIN (PGP SIGNATURE|PGP MESSAGE|SIGNED MESSAGE|SSH SIGNATURE)-----';

    /**
     * @param string|null $taggerName Null for a tag without a tagger, like the oldest tags of git itself
     */
    public function __construct(
        public string $objectHash,
        public ObjectType $objectType,
        public string $tagName,
        public ?string $taggerName,
        public ?string $taggerEmail,
        public ?\DateTimeImmutable $taggerDate,
        public string $message,
        public ?string $gpgSignature,
    ) {
    }

    /**
     * @param string $signedMessage The message of the tag object, followed by the signature git appends to it, if any
     */
    public static function fromSignedMessage(string $objectHash, ObjectType $objectType, string $tagName, ?string $taggerName, ?string $taggerEmail, ?\DateTimeImmutable $taggerDate, string $signedMessage): self
    {
        if (!preg_match('/^(.*?)'.self::SIGNATURE.'(.*?)-----END \2-----/s', $signedMessage, $vars)) {
            return new self($objectHash, $objectType, $tagName, $taggerName, $taggerEmail, $taggerDate, $signedMessage, null);
        }

        return new self($objectHash, $objectType, $tagName, $taggerName, $taggerEmail, $taggerDate, $vars[1], $vars[3]);
    }
}
