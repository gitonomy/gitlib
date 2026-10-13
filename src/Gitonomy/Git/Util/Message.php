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

/**
 * Splits the message of a commit or a tag like git does.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class Message
{
    /**
     * @return string The first paragraph of $message, on one line, like "git log --format=%s"
     */
    public static function subject(string $message): string
    {
        return implode(' ', array_map(trim(...), explode("\n", trim(explode("\n\n", $message, 2)[0]))));
    }

    /**
     * @return string What follows the first paragraph of $message and its empty lines, like "git log --format=%b"
     */
    public static function body(string $message): string
    {
        return ltrim(explode("\n\n", trim($message, "\n")."\n", 2)[1] ?? '', "\n");
    }
}
