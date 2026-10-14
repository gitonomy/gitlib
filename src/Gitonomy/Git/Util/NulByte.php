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

namespace Gitonomy\Git\Util;

use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * git and libgit2 read their arguments as C strings, which end at the first
 * NUL byte: libgit2 would read "a.txt\0.md" as "a.txt".
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class NulByte
{
    /**
     * @throws InvalidArgumentException One of the values contains a NUL byte
     */
    public static function assertNone(string ...$values): void
    {
        foreach ($values as $value) {
            if (str_contains($value, "\0")) {
                throw new InvalidArgumentException(\sprintf('"%s" contains a NUL byte, which git doesn\'t accept.', addcslashes($value, "\0")));
            }
        }
    }
}
