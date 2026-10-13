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
use Gitonomy\Git\Exception\LogicException;

/**
 * A line of a hunk.
 *
 * Reading it as an array, [$type, $content] with the type as one of the
 * FileChange::LINE_* constants, is deprecated.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @implements \ArrayAccess<int, int|string>
 */
final readonly class DiffLine implements \ArrayAccess
{
    public function __construct(
        private DiffLineType $type,
        private string $content,
    ) {
    }

    public function getType(): DiffLineType
    {
        return $this->type;
    }

    /**
     * @return string The line, without its "+", "-" or " " prefix, nor its line break
     */
    public function getContent(): string
    {
        return $this->content;
    }

    public function offsetExists(mixed $offset): bool
    {
        return 0 === $offset || 1 === $offset;
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use getType() and getContent() instead
     */
    public function offsetGet(mixed $offset): int|string
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'Reading a "%s" as an array is deprecated, use its "getType()" and "getContent()" methods instead.', self::class);

        return match ($offset) {
            0 => $this->type->value,
            1 => $this->content,
            default => throw new InvalidArgumentException(\sprintf('A diff line has no offset %s.', var_export($offset, true))),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('A diff line is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('A diff line is immutable.');
    }
}
