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

use Gitonomy\Git\Exception\InvalidArgumentException;

/**
 * git reads an argument starting with a dash as an option: the revision
 * "--output=/path" of a log writes a file. git before 2.24 has no
 * "--end-of-options", and what follows "--" is a path for most commands.
 *
 * 2.0 refuses them all. 1.x refuses them all only where no option can be
 * used: the options of "git log" as revisions are deprecated, not removed, so
 * logs and blames keep them, but the ones reading or writing a file.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class LeadingDash
{
    /**
     * Not the abbreviations of --output, which "git log" doesn't accept.
     * --output-indicator-new and the like write no file.
     */
    private const LOG_FILE_OPTION = '/^--output(?:=|$)/';

    /**
     * "git blame" takes the options of "git log", and reads files: it returns
     * the lines of --contents, and prints the first one of --ignore-revs-file
     * or -S in its error. It accepts abbreviations, of any length that isn't
     * ambiguous for the git in use, and a short option in a group, like "-wS".
     */
    private const BLAME_FILE_OPTION = '/^(?:--output(?:=|$)|--c(?:o|on|ont|onte|onten|ontent|ontents)?(?:=|$)|--ignore-revs|-[^-]*S)/';

    /**
     * @throws InvalidArgumentException One of the values starts with a dash
     */
    public static function assertNone(string ...$values): void
    {
        foreach ($values as $value) {
            if (str_starts_with($value, '-')) {
                throw new InvalidArgumentException(sprintf('"%s" starts with a dash, which git would read as an option.', $value));
            }
        }
    }

    /**
     * @param mixed ...$revisions The revisions of a log or a diff
     *
     * @throws InvalidArgumentException One of the revisions is an option making git write a file
     */
    public static function assertNoLogFileOption(...$revisions): void
    {
        foreach ($revisions as $revision) {
            if (1 === preg_match(self::LOG_FILE_OPTION, (string) $revision)) {
                throw new InvalidArgumentException(sprintf('"%s" is an option of git writing a file, not a revision.', $revision));
            }
        }
    }

    /**
     * @param mixed $revision The revision of a blame
     *
     * @throws InvalidArgumentException The revision is an option making git read or write a file
     */
    public static function assertNoBlameFileOption($revision): void
    {
        if (1 === preg_match(self::BLAME_FILE_OPTION, (string) $revision)) {
            throw new InvalidArgumentException(sprintf('"%s" is an option of git reading or writing a file, not a revision.', $revision));
        }
    }
}
