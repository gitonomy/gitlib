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
     * Replaces the user information of the URLs of $text, a token or a user
     * and a password, by "***".
     */
    public static function redact(#[\SensitiveParameter] string $text): string
    {
        // Up to the last "@" before the host, as a password may hold one, or a
        // "#" or a "?", which git accepts unencoded there.
        return preg_replace('#\b([a-z][a-z0-9+.-]*+://)(?:[^/\s@]*+@)++#i', '$1***@', $text);
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
}
