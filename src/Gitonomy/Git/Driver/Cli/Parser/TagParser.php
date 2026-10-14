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

use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Parser\ParserBase;

/**
 * Parses a tag object, as ``git cat-file --batch`` gives it.
 *
 * @internal Used by CliDriver only
 */
final class TagParser extends ParserBase
{
    public TagData $data;

    protected function doParse(): void
    {
        // The object the tag points to, which the reference gives already, and the name of the tag.
        foreach (['object ', 'type ', 'tag '] as $header) {
            $this->consume($header);
            $this->consumeTo("\n");
            $this->consumeNewLine();
        }

        // The oldest tags, like the first ones of git itself, have no tagger.
        [$taggerName, $taggerEmail, $taggerDate] = [null, null, null];
        if ($this->expects('tagger ')) {
            [$taggerName, $taggerEmail, $taggerDate] = $this->consumeNameEmailDate();
            $this->consumeNewLine();
        }

        $this->consumeNewLine();

        $this->data = TagData::fromSignedMessage($taggerName, $taggerEmail, $taggerDate, $this->consumeAll());
    }
}
