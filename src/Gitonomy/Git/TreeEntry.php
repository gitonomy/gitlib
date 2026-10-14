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

use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\LogicException;

/**
 * An entry of a tree: a blob, a tree or a submodule, with its name and mode.
 *
 * Reading it as an array, [$mode, $object], is deprecated.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @implements \ArrayAccess<int, string|Blob|Tree|Submodule>
 */
final readonly class TreeEntry implements \ArrayAccess
{
    public function __construct(
        private string $name,
        private string $mode,
        private Blob|Tree|Submodule $object,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string The mode git stores, like "100644" for a file or "040000" for a tree
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    public function getObject(): Blob|Tree|Submodule
    {
        return $this->object;
    }

    public function offsetExists(mixed $offset): bool
    {
        return 0 === $offset || 1 === $offset;
    }

    /**
     * @deprecated since gitonomy/gitlib 2.0, use getMode() and getObject() instead
     */
    public function offsetGet(mixed $offset): string|Blob|Tree|Submodule
    {
        trigger_deprecation('gitonomy/gitlib', '2.0', 'Reading a "%s" as an array is deprecated, use its "getMode()" and "getObject()" methods instead.', self::class);

        return match ($offset) {
            0 => $this->mode,
            1 => $this->object,
            default => throw new InvalidArgumentException(\sprintf('A tree entry has no offset %s.', var_export($offset, true))),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('A tree entry is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('A tree entry is immutable.');
    }
}
