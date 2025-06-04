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

class LogParser extends CommitParser
{
    public $log = [];

    protected function doParse()
    {
        $this->log = [];

        while (!$this->isFinished()) {
            $commit = [];
            $this->consume('commit ');
            $commit['id'] = $this->consumeHash();
            $this->consumeNewLine();

            $this->consume('tree ');
            $commit['treeHash'] = $this->consumeHash();
            $this->consumeNewLine();

            $commit['parentHashes'] = [];
            while ($this->expects('parent ')) {
                $commit['parentHashes'][] = $this->consumeHash();
                $this->consumeNewLine();
            }

            $this->consume('author ');
            list($commit['authorName'], $commit['authorEmail'], $authorDate) = $this->consumeNameEmailDate();
            $commit['authorDate'] = $this->parseDate($authorDate);
            $this->consumeNewLine();

            $this->consume('committer ');
            list($commit['committerName'], $commit['committerEmail'], $committerDate) = $this->consumeNameEmailDate();
            $commit['committerDate'] = $this->parseDate($committerDate);

            $this->consumeMergeTag();

            // will consume an GPG signed commit if there is one
            $this->consumeGPGSignature();

            $this->consumeNewLine();
            $this->consumeUnsupportedLinesToNewLine();
            if ($this->cursor < strlen($this->content)) {
                $this->consumeNewLine();
            }

            $message = '';
            if ($this->expects('    ')) {
                $this->cursor -= strlen('    ');

                while ($this->expects('    ')) {
                    $message .= $this->consumeTo("\n")."\n";
                    $this->consumeNewLine();
                }
            } else {
                $this->cursor--;
            }

            if (!$this->isFinished()) {
                $this->consumeNewLine();
            }

            $commit['message'] = $message;

            $this->log[] = $commit;
        }
    }

    protected function consumeUnsupportedLinesToNewLine()
    {
        // Consume any unsupported lines that may appear in the log output. For
        // example, gitbutler headers or other custom metadata but this should
        // work regardless of the content.
        while (!$this->isFinished() && substr($this->content, $this->cursor, 1) !== "\n") {
            $this->consumeTo("\n");
            $this->consumeNewLine();
        }
    }
}
