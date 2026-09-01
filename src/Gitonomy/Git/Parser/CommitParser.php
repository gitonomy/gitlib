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

use Gitonomy\Git\Exception\RuntimeException;

class CommitParser extends ParserBase
{
    public string $tree;

    /**
     * @var string[]
     */
    public array $parents;

    public string $authorName;
    public string $authorEmail;
    public \DateTime $authorDate;
    public string $committerName;
    public string $committerEmail;
    public \DateTime $committerDate;
    public string $message;

    protected function doParse(): void
    {
        $this->consume('tree ');
        $this->tree = $this->consumeHash();
        $this->consumeNewLine();

        $this->parents = [];
        while ($this->expects('parent ')) {
            $this->parents[] = $this->consumeHash();
            $this->consumeNewLine();
        }

        $this->consume('author ');
        [$this->authorName, $this->authorEmail, $authorDate] = $this->consumeNameEmailDate();
        $this->authorDate = $this->parseDate($authorDate);
        $this->consumeNewLine();

        $this->consume('committer ');
        [$this->committerName, $this->committerEmail, $committerDate] = $this->consumeNameEmailDate();
        $this->committerDate = $this->parseDate($committerDate);

        $this->consumeMergeTag();

        // will consume an GPG signed commit if there is one
        $this->consumeGPGSignature();

        $this->consumeNewLine();

        $this->consumeNewLine();
        $this->message = $this->consumeAll();
    }

    /**
     * @return string[] A tuple of [name, email, date]
     */
    protected function consumeNameEmailDate(): array
    {
        if (!preg_match('/(([^\n]*) <([^\n]*)> (\d+ [+-]\d{4}))/A', $this->content, $vars, 0, $this->cursor)) {
            throw new RuntimeException('Unable to parse name, email and date');
        }

        $this->cursor += strlen($vars[1]);

        return [$vars[2], $vars[3], $vars[4]];
    }

    protected function parseDate(string $text): \DateTime
    {
        $date = \DateTime::createFromFormat('U e O', $text.' UTC');

        if (!$date instanceof \DateTime) {
            throw new RuntimeException(sprintf('Unable to convert "%s" to datetime', $text));
        }

        return $date;
    }
}
