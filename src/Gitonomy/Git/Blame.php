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

namespace Gitonomy\Git;

use Gitonomy\Git\Blame\Hunk;
use Gitonomy\Git\Blame\Line;
use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Blame implements \Countable
{
    /**
     * @var array<int, Line>|null By number
     */
    private ?array $lines = null;

    private readonly ?int $firstLine;

    private readonly ?int $lastLine;

    /**
     * Like "git blame -L 5,3", a last line before the first one blames the lines 3 to 5.
     *
     * @param string   $file      the file the blame is about
     * @param int|null $firstLine The first line to blame, the first one of the file when null
     * @param int|null $lastLine  The last line to blame, the last one of the file when null
     *
     * @throws InvalidArgumentException An invalid range of lines
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly Revision $revision,
        private readonly string $file,
        ?int $firstLine = null,
        ?int $lastLine = null,
    ) {
        if ((null !== $firstLine && $firstLine < 1) || (null !== $lastLine && $lastLine < 1)) {
            throw new InvalidArgumentException(\sprintf('Invalid range of lines "%s,%s": the lines are numbered from 1.', $firstLine, $lastLine));
        }

        [$this->firstLine, $this->lastLine] = null !== $firstLine && null !== $lastLine && $lastLine < $firstLine ? [$lastLine, $firstLine] : [$firstLine, $lastLine];
    }

    /**
     * @param int $number The number of the line in the file
     *
     * @throws InvalidArgumentException The blame has no line $number
     */
    public function getLine(int $number): Line
    {
        return $this->getLines()[$number] ?? throw new InvalidArgumentException(\sprintf('The blame of "%s" has no line %d.', $this->file, $number));
    }

    /**
     * @return list<Hunk> The consecutive lines last changed by the same commit
     */
    public function getHunks(): array
    {
        $hunks = [];
        $commit = null;
        $lines = [];
        foreach ($this->getLines() as $number => $line) {
            if ($commit !== $line->getCommit() && [] !== $lines) {
                $hunks[] = new Hunk($commit, $lines);
                $lines = [];
            }
            $commit = $line->getCommit();
            $lines[$number] = $line;
        }

        if (null !== $commit) {
            $hunks[] = new Hunk($commit, $lines);
        }

        return $hunks;
    }

    /**
     * Returns lines grouped by commit.
     *
     * @return list<array{Commit, array<int, Line>}>
     *
     * @deprecated since gitonomy/gitlib 1.7, use getHunks() instead
     */
    public function getGroupedLines(): array
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getHunks()" instead.', __METHOD__);

        return array_map(static fn (Hunk $hunk): array => [$hunk->getCommit(), $hunk->getLines()], $this->getHunks());
    }

    /**
     * @return array<int, Line> The lines of the blame, by number
     */
    public function getLines(): array
    {
        if (null !== $this->lines) {
            return $this->lines;
        }

        $hash = $this->revision->getCommit()->getHash();
        $this->lines = [];
        foreach ($this->repository->getRepositoryDriver()->getBlameLines($hash, $this->file, $this->firstLine, $this->lastLine) as $data) {
            $this->lines[$data->number] = new Line($this->repository->getCommit($data->commitHash), $data->number, $data->originalNumber, $data->content);
        }

        return $this->lines;
    }

    public function count(): int
    {
        return \count($this->getLines());
    }
}
