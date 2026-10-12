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
 * One line of a blame result, as read by a Driver. Keeps only the commit
 * hash: resolving it to a Commit object is Blame's job, since Driver has no
 * Repository reference (and thus no commit cache).
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class BlameLineData
{
    public function __construct(
        public string $commitHash,
        public string $sourceLine,
        public string $targetLine,
        public ?string $blockLine,
        public string $content,
    ) {
    }
}
