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
 * Refuses the URLs of a repository that make git run a command, as an
 * application may get them from its users.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class RemoteUrl
{
    /**
     * @throws InvalidArgumentException $url starts with a dash, or uses the "ext::" transport
     */
    public static function assertSafe(string $url): void
    {
        LeadingDash::assertNone($url);

        // "ext::sh -c ..." runs the command, to talk to it: git refuses it by
        // default since 2.12 only, through its protocol.allow setting.
        if (str_starts_with($url, 'ext::')) {
            throw new InvalidArgumentException(\sprintf('"%s" uses the "ext::" transport, which runs a command.', Credentials::redact($url)));
        }
    }
}
