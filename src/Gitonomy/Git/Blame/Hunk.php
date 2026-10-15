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

namespace Gitonomy\Git\Blame;

use Gitonomy\Git\Commit;

/**
 * Consecutive lines of a blame, last changed by the same commit.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final class Hunk
{
    /**
     * @param array<int, Line> $lines By number
     */
    public function __construct(
        private Commit $commit,
        private array $lines,
    ) {
    }

    public function getCommit(): Commit
    {
        return $this->commit;
    }

    /**
     * @return array<int, Line> By number
     */
    public function getLines(): array
    {
        return $this->lines;
    }
}
