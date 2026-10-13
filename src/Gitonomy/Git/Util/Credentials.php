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
 * A URL like "https://user:token@host/repository.git" carries credentials:
 * the messages of exceptions and of the logger hide them.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class Credentials
{
    /**
     * Replaces the user information of the URLs of $text, a token or a user
     * and a password, by "***".
     */
    public static function redact(string $text): string
    {
        // Up to the last "@" before the host, as a password may hold one.
        // @phpstan-ignore return.type (possessive quantifiers never reach the backtracking limit, and without "u" there is no UTF-8 to fail on)
        return preg_replace('#\b([a-z][a-z0-9+.-]*+://)(?:[^/?\#\s@]*+@)++#i', '$1***@', $text);
    }
}
