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

use Gitonomy\Git\Driver\Data\BlameLineData;
use Gitonomy\Git\Parser\ParserBase;

/**
 * Parses the output of ``git blame -p``.
 */
final class BlameParser extends ParserBase
{
    /**
     * @var BlameLineData[]
     */
    public array $lines = [];

    /**
     * @var array<string, bool>
     */
    private array $seenHashes = [];

    protected function doParse(): void
    {
        $this->lines = [];
        $this->seenHashes = [];

        $line = 1;
        while (!$this->isFinished()) {
            $hash = $this->consumeHash();
            $this->consume(' ');
            $vars = $this->consumeRegexp('/(\d+) (\d+)( (\d+))?/A');
            $sourceLine = $vars[1];
            $targetLine = $vars[2];
            $blockLine = $vars[4] ?? null;
            $this->consumeTo("\n");
            $this->consumeNewLine();

            if (!isset($this->seenHashes[$hash])) {
                foreach (['author', 'author-mail', 'author-time', 'author-tz',
                    'committer', 'committer-mail', 'committer-time', 'committer-tz',
                    'summary', ] as $key) {
                    $this->consume($key);
                    $this->consumeTo("\n");
                    $this->consumeNewLine();
                }

                if ($this->expects('previous ')) {
                    $this->consumeTo("\n");
                    $this->consumeNewLine();
                }

                if ($this->expects('boundary')) {
                    $this->consumeNewLine();
                }
                $this->consume('filename');
                $this->consumeTo("\n"); // filename
                $this->consumeNewLine();
                $this->seenHashes[$hash] = true;
            }
            $this->consume("\t");
            $content = $this->consumeTo("\n");
            $this->consumeNewLine();

            $this->lines[$line] = new BlameLineData($hash, $sourceLine, $targetLine, $blockLine, $content);
            ++$line;
        }
    }
}
