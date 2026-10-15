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

use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * @final since gitonomy/gitlib 1.7
 */
class FileChange
{
    /**
     * @deprecated since gitonomy/gitlib 1.7, use DiffLineType::Context instead
     */
    const LINE_CONTEXT = 0;

    /**
     * @deprecated since gitonomy/gitlib 1.7, use DiffLineType::Deletion instead
     */
    const LINE_REMOVE = -1;

    /**
     * @deprecated since gitonomy/gitlib 1.7, use DiffLineType::Addition instead
     */
    const LINE_ADD = 1;

    protected $rangeOldStart;
    protected $rangeOldCount;
    protected $rangeNewStart;
    protected $rangeNewCount;
    protected $lines;

    /**
     * @param int                               $rangeOldStart
     * @param int                               $rangeOldCount
     * @param int                               $rangeNewStart
     * @param int                               $rangeNewCount
     * @param list<DiffLine|array{int, string}> $lines         The lines as arrays, [$type, $content], are deprecated
     *
     * @return void
     */
    public function __construct($rangeOldStart, $rangeOldCount, $rangeNewStart, $rangeNewCount, $lines)
    {
        foreach ($lines as $line) {
            if (!$line instanceof DiffLine) {
                trigger_deprecation('gitonomy/gitlib', '1.7', 'Passing the lines to "%s()" as arrays is deprecated, pass "%s" objects instead.', __METHOD__, DiffLine::class);

                break;
            }
        }

        $this->rangeOldStart = $rangeOldStart;
        $this->rangeOldCount = $rangeOldCount;
        $this->rangeNewStart = $rangeNewStart;
        $this->rangeNewCount = $rangeNewCount;
        // As arrays, like in 1.6: the subclasses may read them.
        $this->lines = array_map(static fn ($line) => $line instanceof DiffLine ? [$line->getType()->value, $line->getContent()] : $line, $lines);
    }

    /**
     * @return int
     *
     * @deprecated since gitonomy/gitlib 1.7, use getAdditions() or getDeletions() instead
     */
    public function getCount($type)
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getAdditions()" or "getDeletions()" instead.', __METHOD__);

        return $this->countLines($type);
    }

    public function getAdditions(): int
    {
        return $this->countLines(DiffLineType::Addition->value);
    }

    public function getDeletions(): int
    {
        return $this->countLines(DiffLineType::Deletion->value);
    }

    /**
     * @return int
     */
    public function getRangeOldStart()
    {
        return $this->rangeOldStart;
    }

    /**
     * @return int
     */
    public function getRangeOldCount()
    {
        return $this->rangeOldCount;
    }

    /**
     * @return int
     */
    public function getRangeNewStart()
    {
        return $this->rangeNewStart;
    }

    /**
     * @return int
     */
    public function getRangeNewCount()
    {
        return $this->rangeNewCount;
    }

    /**
     * @return list<DiffLine>
     */
    public function getDiffLines(): array
    {
        return array_map(static fn (array $line): DiffLine => new DiffLine(self::lineType($line[0]), $line[1]), array_values($this->lines));
    }

    /**
     * @return array The lines, as arrays: [$type, $content]
     *
     * @deprecated since gitonomy/gitlib 1.7, use getDiffLines() instead
     */
    public function getLines()
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "getDiffLines()" instead.', __METHOD__);

        return $this->lines;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'range_old_start' => $this->rangeOldStart,
            'range_old_count' => $this->rangeOldCount,
            'range_new_start' => $this->rangeNewStart,
            'range_new_count' => $this->rangeNewCount,
            'lines'           => $this->lines,
        ];
    }

    /**
     * @param array $array
     *
     * @return self
     */
    public static function fromArray(array $array)
    {
        $change = new self($array['range_old_start'], $array['range_old_count'], $array['range_new_start'], $array['range_new_count'], []);
        // The lines as they are, like in 1.6.
        $change->lines = $array['lines'];

        return $change;
    }

    /**
     * @throws InvalidArgumentException $type is none of the values of DiffLineType
     */
    private static function lineType(int $type): DiffLineType
    {
        return DiffLineType::tryFrom($type) ?? throw new InvalidArgumentException(sprintf('Unknown type of line %d: the types are 1 (an addition), 0 (a context line) and -1 (a deletion).', $type));
    }

    private function countLines($type): int
    {
        $result = 0;
        foreach ($this->lines as $line) {
            if ($line[0] === $type) {
                $result++;
            }
        }

        return $result;
    }
}
