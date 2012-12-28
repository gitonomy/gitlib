<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

use Gitonomy\Git\Parser\BlameParser;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Blame implements \Countable
{
    protected $repository;
    protected $revision;
    protected $file;
    protected $lineRange;

    protected $lines;

    /**
     * @param string $lineRange Argument to pass to git blame (-L).
     *                          Can be a line range (40,60 or 40,+21)
     *                          or a regexp ('/^function$/')
     */
    public function __construct(Repository $repository, $revision, $file, $lineRange = null)
    {
        $this->repository = $repository;
        $this->revision   = $revision;
        $this->lineRange  = $lineRange;
        $this->file       = $file;
    }

    public function getLine($number)
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Line number should be at least 1');
        }

        $lines = $this->getLines();

        if (!isset($lines[$number - 1])) {
            throw new \InvalidArgumentException('Line does not exist');
        }

        return $lines[$number - 1];
    }

    public function getLines()
    {
        if (null !== $this->lines) {
            return $this->lines;
        }

        $args = array('-p');

        if (null !== $this->lineRange) {
            $args[] = '-L';
            $args[] = $this->lineRange;
        }

        $args[] = $this->revision;
        $args[] = '--';
        $args[] = $this->file;

        $parser = new BlameParser($this->repository);
        $parser->parse($this->repository->run('blame', $args));
        $this->lines = $parser->lines;

        return $this->lines;
    }

    public function count()
    {
        return count($this->getLines());
    }
}
