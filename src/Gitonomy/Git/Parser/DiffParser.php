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

namespace Gitonomy\Git\Parser;

use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Exception\InvalidArgumentException;

final class DiffParser extends ParserBase
{
    /**
     * git quotes the names holding special or non-ASCII characters (unless
     * core.quotePath is false, for the latter) like C strings.
     */
    private const string QUOTED_NAME = '"(?:[^"\\\]|\\\.)*"';

    /**
     * @var File[]
     */
    public array $files = [];

    protected function doParse(): void
    {
        $this->files = [];
        $indexes = [];
        // The raw information gives the full hashes, even for empty files.
        if (str_starts_with($this->content, ':')) {
            while ($this->expects(':')) {
                $this->consumeRegexp('/\d{6} \d{6} /');
                $oldIndex = $this->consumeShortHash();
                $this->consume(' ');
                $newIndex = $this->consumeShortHash();
                $this->consumeTo("\n");
                $this->consumeNewLine();
                $indexes[] = [$oldIndex, $newIndex];
            }
            $this->consumeNewLine();
        } elseif (!$this->isFinished()) {
            throw new InvalidArgumentException('The diff has no raw information: Diff::parse() parses the output of "git diff --raw --patch".');
        }

        $fileIndex = 0;
        while (!$this->isFinished()) {
            // 1. title
            // The prefix is normally "a" and "b", but git's diff.mnemonicPrefix option
            // (used by tools such as GrumPHP) can produce other single-letter prefixes
            // ("c"ommit, "i"ndex, "o"bject, "w"ork tree), or "1"/"2" with --no-index.
            $vars = $this->consumeRegexp('/diff --git ('.self::QUOTED_NAME.'|[^\/\s"]\/.*?) ('.self::QUOTED_NAME.'|[^\/\s"]\/.*?)\n/');
            $oldName = self::unquote($vars[1]);
            $newName = self::unquote($vars[2]);
            // Get indexes from raw if it exists
            $oldIndex = isset($indexes[$fileIndex]) ? $indexes[$fileIndex][0] : null;
            $newIndex = isset($indexes[$fileIndex]) ? $indexes[$fileIndex][1] : null;
            ++$fileIndex;
            $oldMode = null;
            $newMode = null;

            // 2. mode
            if ($this->expects('new file mode ')) {
                $newMode = $this->consumeTo("\n");
                $this->consumeNewLine();
                $oldMode = null;
                $oldName = '/dev/null';
            }
            if ($this->expects('old mode ')) {
                $oldMode = $this->consumeTo("\n");
                $this->consumeNewLine();
                $this->consume('new mode ');
                $newMode = $this->consumeTo("\n");
                $this->consumeNewLine();
            }
            if ($this->expects('deleted file mode ')) {
                $oldMode = $this->consumeTo("\n");
                $newMode = null;
                $newName = '/dev/null';
                $this->consumeNewLine();
            }

            $isCopy = false;
            if ($this->expects('similarity index ')) {
                $this->consumeRegexp('/\d{1,3}%\n/');
                $isCopy = $this->expects('copy from ');
                if (!$isCopy) {
                    $this->consume('rename from ');
                }
                $this->consumeTo("\n");
                $this->consumeNewLine();
                $this->consume($isCopy ? 'copy to ' : 'rename to ');
                $this->consumeTo("\n");
                $this->consumeNewLine();
            }

            // 4. File informations
            $isBinary = false;
            if ($this->expects('index ')) {
                $oldIndex = $this->consumeShortHash();
                $this->consume('..');
                $newIndex = $this->consumeShortHash();
                if ($this->expects(' ')) {
                    $vars = $this->consumeRegexp('/\d{6}/');
                    $newMode = $oldMode = $vars[0];
                }
                $this->consumeNewLine();
            }

            // verifying if the file was deleted or created
            // Note: a dirty submodule diff has no "index " line, so this must
            // not be nested inside the block above.
            if ($this->expects('--- ')) {
                $oldName = '/dev/null' === $this->consumeTo("\n") ? '/dev/null' : $oldName;
                $this->consumeNewLine();
                $this->consume('+++ ');
                $newName = '/dev/null' === $this->consumeTo("\n") ? '/dev/null' : $newName;
                $this->consumeNewLine();
            } elseif ($this->expects('Binary files ')) {
                $vars = $this->consumeRegexp('/('.self::QUOTED_NAME.'|.*?) and ('.self::QUOTED_NAME.'|.*?) differ\n/');
                $isBinary = true;
                $oldName = self::unquote($vars[1]);
                $newName = self::unquote($vars[2]);
            }

            $oldName = '/dev/null' === $oldName ? null : substr($oldName, 2);
            $newName = '/dev/null' === $newName ? null : substr($newName, 2);

            $oldIndex = null === $oldIndex ? '' : $oldIndex;
            $newIndex = null === $newIndex ? '' : $newIndex;
            $oldIndex = preg_match('/^0+$/', $oldIndex) ? null : $oldIndex;
            $newIndex = preg_match('/^0+$/', $newIndex) ? null : $newIndex;
            $file = new File($oldName, $newName, $oldMode, $newMode, $oldIndex, $newIndex, $isBinary, $isCopy);

            // 5. Diff
            while ($this->expects('@@ ')) {
                $vars = $this->consumeRegexp('/-(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))?/');
                $rangeOldStart = (int) $vars[1];
                $rangeOldCount = (int) ($vars[2] ?? 1);
                $rangeNewStart = (int) $vars[3];
                $rangeNewCount = (int) ($vars[4] ?? 1);
                $this->consume(' @@');
                $this->consumeTo("\n");
                $this->consumeNewLine();

                // 6. Lines
                $lines = [];
                while (true) {
                    if ($this->expects(' ')) {
                        $lines[] = [FileChange::LINE_CONTEXT, $this->consumeTo("\n")];
                    } elseif ($this->expects('+')) {
                        $lines[] = [FileChange::LINE_ADD, $this->consumeTo("\n")];
                    } elseif ($this->expects('-')) {
                        $lines[] = [FileChange::LINE_REMOVE, $this->consumeTo("\n")];
                    } elseif ($this->expects('\ No newline at end of file')) {
                        // Ignore this case...
                    } else {
                        break;
                    }

                    $this->consumeNewLine();
                }

                $change = new FileChange($rangeOldStart, $rangeOldCount, $rangeNewStart, $rangeNewCount, $lines);

                $file->addChange($change);
            }

            $this->files[] = $file;
        }
    }

    private static function unquote(string $name): string
    {
        if (!str_starts_with($name, '"')) {
            return $name;
        }

        return stripcslashes(substr($name, 1, -1));
    }
}
