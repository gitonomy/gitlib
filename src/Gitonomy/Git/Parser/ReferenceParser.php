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

namespace Gitonomy\Git\Parser;

final class ReferenceParser extends ParserBase
{
    /**
     * @var array<int, array{string, string}>
     */
    public array $references = [];

    protected function doParse(): void
    {
        $this->references = [];

        while (!$this->isFinished()) {
            $hash = $this->consumeHash();
            $this->consume(' ');
            $name = $this->consumeTo("\n");
            $this->consumeNewLine();
            $this->references[] = [$hash, $name];
        }
    }
}
