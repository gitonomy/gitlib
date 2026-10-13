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

use Gitonomy\Git\Driver\Data\ObjectType;
use Gitonomy\Git\Driver\Data\TreeEntryData;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Parser\ParserBase;

/**
 * Parses a tree object, as ``git cat-file --batch`` gives it: for each
 * entry, its mode, a space, its name, a NUL byte, and its hash in binary.
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
            // git stores the mode of a tree as "40000".
            $mode = str_pad($this->consumeRegexp('/[0-7]{5,6}/')[0], 6, '0', \STR_PAD_LEFT);
            $this->consume(' ');

            $name = $this->consumeTo("\0");
            $this->consume("\0");

            $hash = substr($this->content, $this->cursor, 20);
            if (20 !== \strlen($hash)) {
                throw new RuntimeException(\sprintf('The tree entry "%s" has no hash.', $name));
            }
            $this->cursor += 20;

            $type = match ($mode) {
                '040000' => ObjectType::Tree,
                // A submodule: the hash is a commit of another repository.
                '160000' => ObjectType::Commit,
                default => ObjectType::Blob,
            };

            $this->entries[] = new TreeEntryData($mode, $type, bin2hex($hash), $name);
        }
    }
}
