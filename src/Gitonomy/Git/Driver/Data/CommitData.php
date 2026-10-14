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
 * Raw commit data, as read by a Driver. Repository-agnostic: does not resolve
 * parents/tree into objects, that's Commit's job.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class CommitData
{
    /**
     * @param string[] $parentHashes
     */
    public function __construct(
        public string $hash,
        public string $treeHash,
        public array $parentHashes,
        public string $authorName,
        public string $authorEmail,
        public \DateTimeImmutable $authorDate,
        public string $committerName,
        public string $committerEmail,
        public \DateTimeImmutable $committerDate,
        public string $message,
    ) {
    }
}
