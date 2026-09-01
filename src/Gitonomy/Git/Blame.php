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

namespace Gitonomy\Git;

use Gitonomy\Git\Blame\Line;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Parser\BlameParser;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Blame implements \Countable
{
    /**
     * @var Line[]|null
     */
    protected ?array $lines = null;

    /**
     * @param string      $file      the file the blame is about
     * @param string|null $lineRange argument to pass to git blame (-L).
     *                               Can be a line range (40,60 or 40,+21)
     *                               or a regexp ('/^function$/')
     */
    public function __construct(
        protected readonly Repository $repository,
        protected readonly Revision $revision,
        protected readonly string $file,
        protected readonly ?string $lineRange = null,
    ) {
    }

    public function getLine(int $number): Line
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Line number should be at least 1');
        }

        $lines = $this->getLines();

        if (!isset($lines[$number])) {
            throw new InvalidArgumentException('Line does not exist');
        }

        return $lines[$number];
    }

    /**
     * Returns lines grouped by commit.
     *
     * @return array a list of two-elements array (commit, lines)
     */
    public function getGroupedLines(): array
    {
        $result = [];
        $commit = null;
        $current = [];

        foreach ($this->getLines() as $lineNumber => $line) {
            if ($commit !== $line->getCommit()) {
                if (count($current)) {
                    $result[] = [$commit, $current];
                }
                $commit = $line->getCommit();
                $current = [];
            }

            $current[$lineNumber] = $line;
        }

        if (count($current)) {
            $result[] = [$commit, $current];
        }

        return $result;
    }

    /**
     * @return Line[] All lines of the blame.
     */
    public function getLines(): array
    {
        if (null !== $this->lines) {
            return $this->lines;
        }

        $args = ['-p'];

        if (null !== $this->lineRange) {
            $args[] = '-L';
            $args[] = $this->lineRange;
        }

        $args[] = $this->revision->getRevision();
        $args[] = '--';
        $args[] = $this->file;

        $parser = new BlameParser($this->repository);
        $parser->parse($this->repository->run('blame', $args));
        $this->lines = $parser->lines;

        return $this->lines;
    }

    public function count(): int
    {
        return count($this->getLines());
    }
}
