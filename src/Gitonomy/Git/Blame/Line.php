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

namespace Gitonomy\Git\Blame;

use Gitonomy\Git\Commit;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final readonly class Line
{
    /**
     * Instanciates a new Line object.
     */
    public function __construct(
        private readonly Commit $commit,
        private readonly string $sourceLine,
        private readonly string $targetLine,
        private readonly ?string $blockLine,
        private readonly string $content,
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getLine(): string
    {
        return $this->sourceLine;
    }

    public function getCommit(): Commit
    {
        return $this->commit;
    }
}
