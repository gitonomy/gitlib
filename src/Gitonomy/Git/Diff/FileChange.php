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

namespace Gitonomy\Git\Diff;

use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * A hunk of a file of a diff.
 *
 * @phpstan-type FileChangeArray array{range_old_start: int, range_old_count: int, range_new_start: int, range_new_count: int, lines: list<array{int, string}>}
 */
final readonly class FileChange
{
    /**
     * @var list<DiffLine>
     */
    private array $lines;

    /**
     * @param DiffLine[] $lines
     */
    public function __construct(
        private int $rangeOldStart,
        private int $rangeOldCount,
        private int $rangeNewStart,
        private int $rangeNewCount,
        array $lines,
    ) {
        $this->lines = array_values($lines);
    }

    public function getAdditions(): int
    {
        return $this->count(DiffLineType::Addition);
    }

    public function getDeletions(): int
    {
        return $this->count(DiffLineType::Deletion);
    }

    public function getRangeOldStart(): int
    {
        return $this->rangeOldStart;
    }

    public function getRangeOldCount(): int
    {
        return $this->rangeOldCount;
    }

    public function getRangeNewStart(): int
    {
        return $this->rangeNewStart;
    }

    public function getRangeNewCount(): int
    {
        return $this->rangeNewCount;
    }

    /**
     * @return list<DiffLine>
     */
    public function getDiffLines(): array
    {
        return $this->lines;
    }

    /**
     * @return FileChangeArray
     */
    public function toArray(): array
    {
        return [
            'range_old_start' => $this->rangeOldStart,
            'range_old_count' => $this->rangeOldCount,
            'range_new_start' => $this->rangeNewStart,
            'range_new_count' => $this->rangeNewCount,
            'lines' => array_map(static fn (DiffLine $line): array => [$line->getType()->value, $line->getContent()], $this->lines),
        ];
    }

    /**
     * @param FileChangeArray $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['range_old_start'],
            $array['range_old_count'],
            $array['range_new_start'],
            $array['range_new_count'],
            array_map(static fn (array $line): DiffLine => new DiffLine(self::lineType($line[0]), $line[1]), $array['lines']),
        );
    }

    /**
     * @throws InvalidArgumentException $type is none of the values of DiffLineType
     */
    private static function lineType(int $type): DiffLineType
    {
        return DiffLineType::tryFrom($type) ?? throw new InvalidArgumentException(\sprintf('Unknown type of line %d: the types are 1 (an addition), 0 (a context line) and -1 (a deletion).', $type));
    }

    private function count(DiffLineType $type): int
    {
        return \count(array_filter($this->lines, static fn (DiffLine $line): bool => $line->getType() === $type));
    }
}
