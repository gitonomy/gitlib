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

class LogParser extends CommitParser
{
    public $log = array();

    protected function doParse()
    {
        $this->log = array();

        while (!$this->isFinished()) {
            $commit = array();
            $this->consume('commit ');
            $commit['id'] = $this->consumeHash();
            $this->consumeNewLine();

            $this->consume('tree ');
            $commit['treeHash'] = $this->consumeHash();
            $this->consumeNewLine();

            $commit['parentHashes'] = array();
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

            $message = '';
            $files = array();

            //Is there a body?
            $this->expects("\n"); //Last commit may not have trailing linebreaks
            $this->expects("\n");

            if($this->expects('    ')){
                $message = $this->consumeMessage();
            }

            $this->expects("\n");

            if($this->lookAheadRegexp('/\w\t/')){
                $files = $this->consumeFiles();
            }

            $this->expects("\n");

            $commit['message'] = $message;
            $commit['files'] = $files;

            $this->log[] = $commit;
        }
    }

    private function consumeMessage(){
        $message = '';

        do {
            $message .= $this->consumeTo("\n") . "\n";
            $this->consumeNewLine();
        }while($this->expects('    '));

        return $message;
    }

    private function consumeFiles(){
        $files = array();

        do{
        	$matches = $this->consumeRegexp("/(.*?)(?:\n|$)/");
            $row = $matches[1];
            if(!trim($row))
                break;
            if(strpos($row, "\t") === false)
                throw new RuntimeException("Error in files: $row");
            list($op, $file) = explode("\t", $row, 2);
            $files[$file] = $op;
        }while(true);

        return $files;
    }
}
