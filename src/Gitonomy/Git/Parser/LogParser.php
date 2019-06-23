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
            list($commit['authorName'], $commit['authorEmail'], $commit['authorDate']) = $this->consumeNameEmailDate();
            $commit['authorDate'] = $this->parseDate($commit['authorDate']);
            $this->consumeNewLine();

            $this->consume('committer ');
            list($commit['committerName'], $commit['committerEmail'], $commit['committerDate']) = $this->consumeNameEmailDate();
            $commit['committerDate'] = $this->parseDate($commit['committerDate']);

            // will consume an GPG signed commit if there is one
            $this->consumeGPGSignature();

            $this->consumeNewLine();
            $this->consumeNewLine();

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
}
