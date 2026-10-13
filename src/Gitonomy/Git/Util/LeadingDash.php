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
 * git reads an argument starting with a dash as an option: the revision
 * "--output=/path" of a log writes a file, the URL "--upload-pack=..." of
 * ls-remote runs a command. git 1.8.4 has no "--end-of-options", and what
 * follows "--" is a path for most commands: such a revision, reference, hash
 * or URL is refused, by both drivers.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class LeadingDash
{
    /**
     * @throws InvalidArgumentException One of the values starts with a dash
     */
    public static function assertNone(string ...$values): void
    {
        foreach ($values as $value) {
            if (str_starts_with($value, '-')) {
                throw new InvalidArgumentException(\sprintf('"%s" starts with a dash, which git would read as an option.', $value));
            }
        }
    }
}
