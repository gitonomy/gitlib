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

use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Parser\ParserBase;

/**
 * Parses the output of ``git log --format=raw``.
 */
final class LogParser extends ParserBase
{
    /**
     * @var array<string, CommitData> Indexed by hash
     */
    public array $commits = [];

    protected function doParse(): void
    {
        $this->commits = [];

        while (!$this->isFinished()) {
            $this->consume('commit ');
            $hash = $this->consumeHash();
            $this->consumeNewLine();

            $this->consume('tree ');
            $treeHash = $this->consumeHash();
            $this->consumeNewLine();

            $parentHashes = [];
            while ($this->expects('parent ')) {
                $parentHashes[] = $this->consumeHash();
                $this->consumeNewLine();
            }

            $this->consume('author ');
            [$authorName, $authorEmail, $authorDate] = $this->consumeNameEmailDate();
            $this->consumeNewLine();

            $this->consume('committer ');
            [$committerName, $committerEmail, $committerDate] = $this->consumeNameEmailDate();

            $this->consumeMergeTag();

            // will consume an GPG signed commit if there is one
            $this->consumeGPGSignature();

            $this->consumeNewLine();
            $this->consumeUnsupportedLinesToNewLine();
            if ($this->cursor < \strlen($this->content)) {
                $this->consumeNewLine();
            }

            $message = '';
            if ($this->expects('    ')) {
                $this->cursor -= \strlen('    ');

                while ($this->expects('    ')) {
                    $message .= $this->consumeTo("\n")."\n";
                    $this->consumeNewLine();
                }
            } else {
                --$this->cursor;
            }

            if (!$this->isFinished()) {
                $this->consumeNewLine();
            }

            $this->commits[$hash] = new CommitData($treeHash, $parentHashes, $authorName, $authorEmail, $authorDate, $committerName, $committerEmail, $committerDate, $message);
        }
    }

    private function consumeUnsupportedLinesToNewLine(): void
    {
        // Consume any unsupported lines that may appear in the log output. For
        // example, gitbutler headers or other custom metadata but this should
        // work regardless of the content.
        while (!$this->isFinished() && "\n" !== substr($this->content, $this->cursor, 1)) {
            $this->consumeTo("\n");
            $this->consumeNewLine();
        }
    }
}
