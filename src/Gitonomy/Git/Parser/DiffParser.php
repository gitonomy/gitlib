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

use Gitonomy\Git\Diff\DiffLine;
use Gitonomy\Git\Diff\DiffLineType;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Driver\Data\DiffFileData;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\RuntimeException;

/**
 * Parses the output of ``git diff --raw --patch``, with ``-z`` or not: the
 * names, modes, hashes and status of the files come from the raw
 * information, and the hunks from the patches.
 *
 * Unmerged paths, during a conflict, are left out.
 */
final class DiffParser extends ParserBase
{
    private const string HASH = '([0-9a-f]{7,64})(?:\.\.\.)?';

    /**
     * The extended header lines of a patch, but the "index" one.
     */
    private const array HEADERS = ['copy from ', 'copy to ', 'deleted file mode ', 'dissimilarity index ', 'new file mode ', 'new mode ', 'old mode ', 'rename from ', 'rename to ', 'similarity index '];

    /**
     * @var list<DiffFileData>
     */
    public array $files = [];

    protected function doParse(): void
    {
        $this->files = [];

        if ($this->isFinished()) {
            return;
        }

        if (!str_starts_with($this->content, ':')) {
            throw new InvalidArgumentException('The diff has no raw information: Diff::parse() parses the output of "git diff --raw --patch".');
        }

        foreach ($this->consumeRawEntries() as $entry) {
            // A type change (a file replaced by a symbolic link...) has the patch of a deletion, then of a creation.
            if ('T' === $entry['status']) {
                $this->files[] = $this->consumePatch($entry['oldName'], null, $entry['oldMode'], null, $entry['oldHash'], null, false);
                $this->files[] = $this->consumePatch(null, $entry['newName'], null, $entry['newMode'], null, $entry['newHash'], false);

                continue;
            }

            $this->files[] = $this->consumePatch(
                'A' === $entry['status'] ? null : $entry['oldName'],
                'D' === $entry['status'] ? null : $entry['newName'],
                $entry['oldMode'],
                $entry['newMode'],
                $entry['oldHash'],
                $entry['newHash'],
                'C' === $entry['status'],
            );
        }
    }

    /**
     * @return list<array{status: string, oldName: string, newName: string, oldMode: string|null, newMode: string|null, oldHash: string|null, newHash: string|null}> The entries of merged paths
     */
    private function consumeRawEntries(): array
    {
        $entries = [];
        $nul = null;
        while ($this->expects(':')) {
            // A combined entry, "::" and more, is an unmerged path.
            $parents = 1;
            while ($this->expects(':')) {
                ++$parents;
            }

            $modes = [];
            for ($i = 0; $i <= $parents; ++$i) {
                $modes[] = $this->consumeRegexp('/(\d{6}) /')[1];
            }
            $hashes = [];
            for ($i = 0; $i <= $parents; ++$i) {
                $hashes[] = $this->consumeRegexp('/'.self::HASH.' /')[1];
            }
            $status = $this->consumeRegexp('/([A-Z]+)\d*/')[1];

            // -z ends the fields with NUL characters, and doesn't quote names.
            $nul ??= "\0" === ($this->content[$this->cursor] ?? '');
            $this->consume($nul ? "\0" : "\t");
            $oldName = $this->consumeName($nul);
            $newName = $oldName;
            if (\in_array($status, ['C', 'R'], true)) {
                if (!$nul) {
                    $this->consume("\t");
                }
                $newName = $this->consumeName($nul);
            }
            if (!$nul) {
                $this->consumeNewLine();
            }

            if (1 === $parents && 'U' !== $status) {
                $entries[] = [
                    'status' => $status,
                    'oldName' => $oldName,
                    'newName' => $newName,
                    'oldMode' => self::nullIfZeros($modes[0]),
                    'newMode' => self::nullIfZeros($modes[1]),
                    'oldHash' => self::nullIfZeros($hashes[0]),
                    'newHash' => self::nullIfZeros($hashes[1]),
                ];
            }
        }

        // The raw information ends with an empty field, or line, when the patches follow.
        if (!$this->isFinished()) {
            $this->consume($nul ? "\0" : "\n");
        }

        return $entries;
    }

    private function consumeName(bool $nul): string
    {
        if ($nul) {
            $name = $this->consumeTo("\0");
            $this->consume("\0");

            return $name;
        }

        return self::unquote($this->consumeRegexp('/("(?:[^"\\\]|\\\.)*"|[^\t\n]*)/')[1]);
    }

    private function consumePatch(?string $oldName, ?string $newName, ?string $oldMode, ?string $newMode, ?string $oldHash, ?string $newHash, bool $isCopy): DiffFileData
    {
        while ($this->expects('* Unmerged path ')) {
            $this->consumeTo("\n");
            $this->consumeNewLine();
        }

        // Without --patch.
        if (!$this->expects('diff --git ')) {
            return new DiffFileData($oldName, $newName, $oldMode, $newMode, $oldHash, $newHash, false, $isCopy, []);
        }
        // The names of the header are ambiguous with spaces: the raw information gives them.
        $this->consumeTo("\n");
        $this->consumeNewLine();

        while (true) {
            // The hashes of the files of the working tree, which the raw information leaves out.
            if ($this->expects('index ')) {
                $vars = $this->consumeRegexp('/'.self::HASH.'\.\.'.self::HASH.'/');
                $oldHash = self::nullIfZeros($vars[1]);
                $newHash = self::nullIfZeros($vars[2]);
            } elseif (!$this->expectsOneOf(self::HEADERS)) {
                break;
            }

            $this->consumeTo("\n");
            $this->consumeNewLine();
        }

        $isBinary = false;
        if ($this->expects('--- ')) {
            $this->consumeTo("\n");
            $this->consumeNewLine();
            $this->consume('+++ ');
            $this->consumeTo("\n");
            $this->consumeNewLine();
        } elseif ($this->expects('Binary files ')) {
            $isBinary = true;
            $this->consumeTo("\n");
            $this->consumeNewLine();
        }

        $changes = [];
        while ($this->expects('@@ ')) {
            $changes[] = $this->consumeHunk();
        }

        return new DiffFileData($oldName, $newName, $oldMode, $newMode, $oldHash, $newHash, $isBinary, $isCopy, $changes);
    }

    private function consumeHunk(): FileChange
    {
        if (!preg_match('/-(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n/A', $this->content, $vars, \PREG_UNMATCHED_AS_NULL, $this->cursor)) {
            throw new RuntimeException(\sprintf('Unable to parse the hunk header "%s".', substr($this->content, $this->cursor - 3, 50)));
        }
        $this->cursor += \strlen($vars[0]);

        $lines = [];
        while (true) {
            if ($this->expects(' ')) {
                $lines[] = new DiffLine(DiffLineType::Context, $this->consumeTo("\n"));
            } elseif ($this->expects('+')) {
                $lines[] = new DiffLine(DiffLineType::Addition, $this->consumeTo("\n"));
            } elseif ($this->expects('-')) {
                $lines[] = new DiffLine(DiffLineType::Deletion, $this->consumeTo("\n"));
            } elseif ($this->expects('\ No newline at end of file')) {
                // The previous line has no line break.
            } else {
                break;
            }

            $this->consumeNewLine();
        }

        // A count is left out when it is 1.
        return new FileChange((int) $vars[1], (int) ($vars[2] ?? 1), (int) $vars[3], (int) ($vars[4] ?? 1), $lines);
    }

    /**
     * @param string[] $prefixes
     */
    private function expectsOneOf(array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($this->expects($prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function nullIfZeros(string $value): ?string
    {
        return '' === trim($value, '0') ? null : $value;
    }

    private static function unquote(string $name): string
    {
        if (!str_starts_with($name, '"')) {
            return $name;
        }

        return stripcslashes(substr($name, 1, -1));
    }
}
