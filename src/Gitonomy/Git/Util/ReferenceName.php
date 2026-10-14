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
 * gitlib writes the references under "refs/" only, the ones it lists. "git
 * update-ref" writes any other name: it deletes HEAD, which leaves no
 * repository, and writes the short name "master" next to HEAD. Both drivers
 * refuse such a name.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class ReferenceName
{
    /**
     * @throws InvalidArgumentException $fullname isn't under "refs/"
     */
    public static function assertUnderRefs(string $fullname): void
    {
        if (!str_starts_with($fullname, 'refs/')) {
            throw new InvalidArgumentException(\sprintf('"%s" is not the full name of a reference under "refs/", like "refs/heads/master".', $fullname));
        }
    }
}
