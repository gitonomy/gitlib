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

use Gitonomy\Git\Blame\Hunk;
use Gitonomy\Git\Blame\Line;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Parser\BlameParser;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @final since gitonomy/gitlib 1.7
 */
class Blame implements \Countable
{
    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var Revision
     */
    protected $revision;

    /**
     * @var string
     */
    protected $file;

    /**
     * @var string|null
     */
    protected $lineRange;

    /**
     * @var array|null
     */
    protected $lines;

    /**
     * The fourth argument is the first line to blame, and a fifth one the last
     * line, like in 2.0: like "git blame -L 5,3", a last line before the first
     * one blames the lines 3 to 5.
     *
     * A string as fourth argument, a range of lines passed to "git blame -L",
     * like "40,60", "40,+21" or a regexp ("/^function$/"), is deprecated.
     *
     * @param int|string|null $lineRange The first line to blame, the first one of the file when null
     * @param int|null        $lastLine  The last line to blame, the last one of the file when null
     *
     * @throws InvalidArgumentException An invalid range of lines
     */
    public function __construct(Repository $repository, Revision $revision, $file, $lineRange = null, ?int $lastLine = null)
    {
        if (is_string($lineRange)) {
            trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing a range of lines as a string to "%s::getBlame()" or "%s()" is deprecated, pass the first and the last lines instead.', Repository::class, __METHOD__);
        } elseif (null !== $lastLine) {
            if ((null !== $lineRange && $lineRange < 1) || $lastLine < 1) {
                throw new InvalidArgumentException(sprintf('Invalid range of lines "%s,%s": the lines are numbered from 1.', $lineRange, $lastLine));
            }

            $lineRange = ($lineRange ?? 1).','.$lastLine;
        }

        $this->repository = $repository;
        $this->revision = $revision;
        // A first line alone, "-L 5" like in 1.6, blames from this line to the end of the file.
        $this->lineRange = $lineRange;
        $this->file = $file;
    }

    /**
     * @return Line
     */
    public function getLine($number)
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
     * @return list<Hunk> The consecutive lines last changed by the same commit
     */
    public function getHunks(): array
    {
        $hunks = [];
        $commit = null;
        $lines = [];
        foreach ($this->getLines() as $line) {
            if ($commit !== $line->getCommit() && [] !== $lines) {
                $hunks[] = new Hunk($commit, $lines);
                $lines = [];
            }
            $commit = $line->getCommit();
            // By number in the file, like in gitlib 2.0, where getLines() numbers them so too.
            $lines[$line->getNumber()] = $line;
        }

        if (null !== $commit) {
            $hunks[] = new Hunk($commit, $lines);
        }

        return $hunks;
    }

    /**
     * Returns lines grouped by commit.
     *
     * @return array a list of two-elements array (commit, lines)
     *
     * @deprecated since gitonomy/gitlib 1.7, use getHunks() instead
     */
    public function getGroupedLines()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getHunks()" instead.', __METHOD__);

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
    public function getLines()
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

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->getLines());
    }
}
