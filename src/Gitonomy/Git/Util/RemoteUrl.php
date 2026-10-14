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
 * Refuses the URLs of a repository that make git run a command, or use a
 * file descriptor of PHP, as an application may get them from its users.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class RemoteUrl
{
    /**
     * @throws InvalidArgumentException $url starts with a dash, uses the "ext::" or "fd::" transport, or has a host or a user starting with a dash, encoded or not
     */
    public static function assertSafe(#[\SensitiveParameter] string $url): void
    {
        LeadingDash::assertNone($url);

        // "ext::sh -c ..." runs the command, to talk to it: git refuses it by
        // default since 2.12 only, through its protocol.allow setting.
        if (str_starts_with($url, 'ext::')) {
            throw new InvalidArgumentException(\sprintf('"%s" uses the "ext::" transport, which runs a command.', Credentials::redact($url)));
        }

        // "fd::7" talks through the file descriptors git inherits from PHP:
        // an open file, the socket of a database... git waits on it until it
        // times out, an hour by default.
        if (str_starts_with($url, 'fd::')) {
            throw new InvalidArgumentException(\sprintf('"%s" uses the "fd::" transport, which talks through a file descriptor of PHP.', Credentials::redact($url)));
        }

        // Before 2.14.1 (CVE-2017-1000117), git passes the "user@host" of an ssh
        // URL to ssh, without its brackets: ssh reads "ssh://-oProxyCommand=..."
        // as an option, and runs the command. The command of core.gitProxy gets
        // the host of a git URL the same way. A valid host never starts with a dash.
        $authority = self::authority($url);
        if (null !== $authority && 1 === preg_match('/(?:^|@)\[?-/', $authority)) {
            throw new InvalidArgumentException(\sprintf('"%s" has a host or a user starting with a dash, which ssh would read as an option.', Credentials::redact($url)));
        }
    }

    /**
     * @return string|null The "user@host:port" of an ssh or git URL, decoded,
     *                     or of an address like "user@host:path", which git
     *                     reaches through ssh; null for any other URL, or a
     *                     local path
     */
    private static function authority(#[\SensitiveParameter] string $url): ?string
    {
        // git decodes a URL before it reads its host: "%2D" is a dash, "%5B" a bracket.
        if (1 === preg_match('#^(?:git|ssh|git\+ssh|ssh\+git)://([^/]*)#', $url, $matches)) {
            return rawurldecode($matches[1]);
        }

        // Like git: a colon before any slash, but not the one of "scheme://".
        if (1 === preg_match('#^([^/:]*):(?!//)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
