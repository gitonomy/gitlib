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

use Gitonomy\Git\Driver\Data\TreeEntryData;
use Gitonomy\Git\Driver\Data\TreeEntryType;
use Gitonomy\Git\Parser\ParserBase;

/**
 * Parses the output of ``git ls-tree -z``, whose names are never quoted.
 */
final class TreeParser extends ParserBase
{
    /**
     * @var TreeEntryData[]
     */
    public array $entries = [];

    protected function doParse(): void
    {
        $this->entries = [];

        while (!$this->isFinished()) {
            $vars = $this->consumeRegexp('/\d{6}/A');
            $mode = $vars[0];
            $this->consume(' ');

            $vars = $this->consumeRegexp('/(blob|tree|commit)/A');
            $type = TreeEntryType::from($vars[0]);
            $this->consume(' ');

            $hash = $this->consumeHash();
            $this->consume("\t");

            $name = $this->consumeTo("\0");
            $this->consume("\0");

            $this->entries[] = new TreeEntryData($mode, $type, $hash, $name);
        }
    }
}
