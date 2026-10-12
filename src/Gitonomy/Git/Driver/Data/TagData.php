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
    public function __construct(
        public string $objectHash,
        public string $objectType,
        public string $tagName,
        public string $taggerName,
        public string $taggerEmail,
        public \DateTimeImmutable $taggerDate,
        public string $message,
        public ?string $gpgSignature,
    ) {
    }

    /**
     * Splits the message of a tag object from the PGP signature git appends to it.
     *
     * @return array{string, string|null} The message, and the signature between its armor lines
     */
    public static function splitSignature(string $message): array
    {
        if (!preg_match('/^(.*?)-----BEGIN PGP SIGNATURE-----(.*?)-----END PGP SIGNATURE-----/s', $message, $vars)) {
            return [$message, null];
        }

        return [$vars[1], $vars[2]];
    }
}
