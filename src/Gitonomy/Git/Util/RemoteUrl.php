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

/**
 * Tells the URLs of a repository that make git run a command, or use a file
 * descriptor of PHP, as an application may get them from its users.
 *
 * Unlike 2.0, 1.x accepts "ext::" URLs: an application may use this transport
 * on purpose, through git's protocol.ext.allow setting or GIT_ALLOW_PROTOCOL.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class RemoteUrl
{
    /**
     * @return string|null Why git must not get $url, null when it can
     */
    public static function unsafeReason(#[\SensitiveParameter] string $url): ?string
    {
        // Before the URL, git would read it as an option: "--upload-pack=..."
        // runs the command. ls-remote only takes "--" since git 2.8.
        if (str_starts_with($url, '-')) {
            return sprintf('"%s" starts with a dash, which git would read as an option.', Credentials::redact($url));
        }

        // "fd::7" talks through the file descriptors git inherits from PHP:
        // an open file, the socket of a database... git waits on it until it
        // times out, an hour by default.
        if (str_starts_with($url, 'fd::')) {
            return sprintf('"%s" uses the "fd::" transport, which talks through a file descriptor of PHP.', Credentials::redact($url));
        }

        // Before 2.14.1 (CVE-2017-1000117), git passes the "user@host" of an ssh
        // URL to ssh, without its brackets: ssh reads "ssh://-oProxyCommand=..."
        // as an option, and runs the command. The command of core.gitProxy gets
        // the host of a git URL the same way, and whatever follows its colon as
        // the port. A valid host or port never starts with a dash.
        $authority = self::authority($url);
        if (null !== $authority && 1 === preg_match('/(?:^|[@:])\[?-/', $authority)) {
            return sprintf('"%s" has a host, a user or a port starting with a dash, which ssh, or the command of core.gitProxy, would read as an option.', Credentials::redact($url));
        }

        return null;
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
