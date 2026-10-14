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
     * @deprecated since gitonomy/gitlib 2.0, use DiffLineType::Context instead
     */
    public const int LINE_CONTEXT = 0;

    /**
     * @deprecated since gitonomy/gitlib 2.0, use DiffLineType::Deletion instead
     */
    public const int LINE_REMOVE = -1;

    /**
     * @deprecated since gitonomy/gitlib 2.0, use DiffLineType::Addition instead
     */
    public const int LINE_ADD = 1;

    /**
     * @param DiffLine[] $lines
     */
    public function __construct(
        private int $rangeOldStart,
        private int $rangeOldCount,
        private int $rangeNewStart,
        private int $rangeNewCount,
        private array $lines,
    ) {
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use getAdditions() or getDeletions() instead
     */
    public function getCount(int $type): int
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s()" method is deprecated, use "getAdditions()" or "getDeletions()" instead.', __METHOD__);

        return $this->count(self::lineType($type));
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
     * @return DiffLine[]
     */
    public function getLines(): array
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
            'lines' => array_map(static fn (DiffLine $line): array => [$line->getType()->value, $line->getContent()], array_values($this->lines)),
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
     * @throws InvalidArgumentException $type is none of the LINE_* constants
     */
    private static function lineType(int $type): DiffLineType
    {
        return DiffLineType::tryFrom($type) ?? throw new InvalidArgumentException(\sprintf('Unknown type of line %d: the types are FileChange::LINE_ADD, LINE_CONTEXT and LINE_REMOVE.', $type));
    }

    private function count(DiffLineType $type): int
    {
        return \count(array_filter($this->lines, static fn (DiffLine $line): bool => $line->getType() === $type));
    }
}
