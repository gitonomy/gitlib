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

namespace Gitonomy\Git\Parser;

/**
 * @deprecated since gitonomy/gitlib 1.7, gitlib 2.0 moves it to the internal parsers of its CliDriver
 */
class ReferenceParser extends ParserBase
{
    public $references;

    protected function doParse()
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
