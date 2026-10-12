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
 * Parses the output of ``git cat-file tag``.
 */
final class TagParser extends ParserBase
{
    public TagData $data;

    protected function doParse(): void
    {
        $this->consume('object ');
        $object = $this->consumeHash();
        $this->consumeNewLine();

        $this->consume('type ');
        $type = $this->consumeTo("\n");
        $this->consumeNewLine();

        $this->consume('tag ');
        $tag = $this->consumeTo("\n");
        $this->consumeNewLine();

        $this->consume('tagger ');
        [$taggerName, $taggerEmail, $taggerDate] = $this->consumeNameEmailDate();

        $this->consumeNewLine();
        $this->consumeNewLine();

        [$message, $gpgSignature] = TagData::splitSignature($this->consumeAll());

        $this->data = new TagData($object, $type, $tag, $taggerName, $taggerEmail, $taggerDate, $message, $gpgSignature);
    }
}
