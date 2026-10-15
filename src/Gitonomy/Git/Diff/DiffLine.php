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

namespace Gitonomy\Git\Diff;

/**
 * A line of a hunk.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final class DiffLine
{
    public function __construct(
        private readonly DiffLineType $type,
        private readonly string $content,
    ) {
    }

    public function getType(): DiffLineType
    {
        return $this->type;
    }

    /**
     * @return string The line, without its "+", "-" or " " prefix, nor its line break
     */
    public function getContent(): string
    {
        return $this->content;
    }
}
