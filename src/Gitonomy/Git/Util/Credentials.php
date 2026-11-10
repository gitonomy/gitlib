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

namespace Gitonomy\Git\Util;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

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
    private const DELIMITERS = "/ \t\n\r\v\f";

    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const SCHEME_CHARACTERS = self::LETTERS.'0123456789+.-';

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
        // Where a scheme may start: after the last "@" read.
        $afterAt = 0;
        while (false !== $at = strpos($text, '@', $offset)) {
            // The characters around the "@", from $offset at most: one pass over $text.
            $start = $at - strcspn(strrev(substr($text, $offset, $at - $offset)), self::DELIMITERS);
            $end = $at + strcspn($text, self::DELIMITERS, $at);
            // Up to the last "@" before the host, as a password may hold one,
            // or a "#" or a "?", which git accepts unencoded there.
            $host = $end - strcspn(strrev(substr($text, $at, $end - $at)), '@');

            $redacted .= substr($text, $offset, $start - $offset);
            $redacted .= self::isAfterScheme($text, $afterAt, $start) ? '***@' : substr($text, $start, $host - $start);
            $redacted .= substr($text, $host, $end - $host);
            $offset = $end;
            $afterAt = $host;
        }

        return $redacted.substr($text, $offset);
    }

    /**
     * Runs $process. The messages of the exceptions of Symfony Process hold
     * the command line, with its URLs: unlike in 2.0, they keep their class,
     * which code written for 1.6 catches.
     */
    public static function runProcess(Process $process): void
    {
        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            (new \ReflectionProperty(\Exception::class, 'message'))->setValue($e, self::redact($e->getMessage()));

            throw $e;
        }
    }

    /**
     * @param int $afterAt Where the scheme may start in $text
     * @param int $start   Where the "user:password@host" starts
     */
    private static function isAfterScheme(#[\SensitiveParameter] string $text, int $afterAt, int $start): bool
    {
        if ($start - 3 <= $afterAt || '://' !== substr($text, $start - 3, 3)) {
            return false;
        }

        $end = $start - 3;
        $length = strspn(strrev(substr($text, $afterAt, $end - $afterAt)), self::SCHEME_CHARACTERS);

        // A scheme starts with a letter, which starts a word: the one of
        // "git+ssh" or its "ssh", but not the "https" of "_https".
        for ($i = $end - $length; $i < $end; ++$i) {
            if (str_contains(self::LETTERS, $text[$i]) && (0 === $i || !str_contains(self::LETTERS.'0123456789_', $text[$i - 1]))) {
                return true;
            }
        }

        return false;
    }
}
