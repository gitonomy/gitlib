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

namespace Gitonomy\Git\Diff;

final readonly class FileChange
{
    public const int LINE_CONTEXT = 0;
    public const int LINE_REMOVE = -1;
    public const int LINE_ADD = 1;

    /**
     * @param array<int, array{int, string}> $lines
     */
    public function __construct(
        private readonly int $rangeOldStart,
        private readonly int $rangeOldCount,
        private readonly int $rangeNewStart,
        private readonly int $rangeNewCount,
        private readonly array $lines,
    ) {
    }

    public function getCount(int $type): int
    {
        $result = 0;
        foreach ($this->lines as $line) {
            if ($line[0] === $type) {
                $result++;
            }
        }

        return $result;
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
     * @return array<int, array{int, string}>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function toArray(): array
    {
        return [
            'range_old_start' => $this->rangeOldStart,
            'range_old_count' => $this->rangeOldCount,
            'range_new_start' => $this->rangeNewStart,
            'range_new_count' => $this->rangeNewCount,
            'lines'           => $this->lines,
        ];
    }

    public static function fromArray(array $array): self
    {
        return new self($array['range_old_start'], $array['range_old_count'], $array['range_new_start'], $array['range_new_count'], $array['lines']);
    }
}
