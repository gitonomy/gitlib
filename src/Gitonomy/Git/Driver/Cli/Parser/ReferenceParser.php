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

namespace Gitonomy\Git\Driver\Cli\Parser;

use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Parser\ParserBase;

/**
 * Parses ``<hash> <full name>`` lines.
 */
final class ReferenceParser extends ParserBase
{
    /**
     * @var ReferenceData[]
     */
    public array $references = [];

    protected function doParse(): void
    {
        $this->references = [];

        while (!$this->isFinished()) {
            $hash = $this->consumeHash();
            $this->consume(' ');
            $fullname = $this->consumeTo("\n");
            $this->consumeNewLine();
            $this->references[] = new ReferenceData($fullname, $hash);
        }
    }
}
