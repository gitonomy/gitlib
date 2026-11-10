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
     * What ends the "user:password@host" of a URL: a slash or a whitespace.
     */
    private const string DELIMITERS = "/ \t\n\r\v\f";

    /**
     * Replaces the user information of the URLs of $text, a token or a user
     * and a password, by "***".
     *
     * Without a regular expression over $text, which may be the megabytes of
     * a blob in the logs: it would reach the limits of PCRE, or take seconds.
     */
    public static function redact(#[\SensitiveParameter] string $text): string
    {
        $redacted = '';
        $offset = 0;
        while (false !== $at = strpos($text, '@', $offset)) {
            // The characters around the "@", from $offset at most: one pass over $text.
            $start = $at - strcspn(strrev(substr($text, $offset, $at - $offset)), self::DELIMITERS);
            $end = $at + strcspn($text, self::DELIMITERS, $at);
            $authority = substr($text, $start, $end - $start);
            // After the last "@", as a password may hold one, or a "#" or a
            // "?", which git and libgit2 accept unencoded there.
            $host = substr($authority, \strlen($authority) - strcspn(strrev($authority), '@'));

            $redacted .= substr($text, $offset, $start - $offset);
            $redacted .= self::isAfterScheme($text, $start) || self::hasPassword($text, $start, $authority, $host) ? '***@'.$host : $authority;
            $offset = $end;
        }

        return $redacted.substr($text, $offset);
    }

    private static function isAfterScheme(#[\SensitiveParameter] string $text, int $start): bool
    {
        return $start >= 3 && '://' === substr($text, $start - 3, 3);
    }

    /**
     * Without a scheme: git prints "user:token@host" when it can't resolve
     * the host of a git:// URL, and ssh "user:token@host: Permission denied".
     * A token alone stays, as nothing tells it from the "git@host" of the
     * errors of ssh, and so does what git quotes, a revision like
     * 'HEAD:logo@2x.png', which ends with a quote instead of a host.
     */
    private static function hasPassword(#[\SensitiveParameter] string $text, int $start, #[\SensitiveParameter] string $authority, string $host): bool
    {
        // After a slash, the "@" is in the path of a URL.
        if ($start > 0 && '/' === $text[$start - 1]) {
            return false;
        }

        $user = strstr($authority, ':', true);
        if (false === $user || '' === $user || str_contains($user, '@')) {
            return false;
        }

        // A host, its port, and the colon of "host: Permission denied".
        // Anything but 0: an error of PCRE hides the text too.
        return 0 !== preg_match('/^(?:[a-z0-9.-]++|\[[0-9a-f:.]++\])(?::\d++)?:?$/Di', $host);
    }
}
