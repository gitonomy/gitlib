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

namespace Gitonomy\Git\Driver\Ffi;

use FFI\CData;

/**
 * Tells whether two trees differ on some paths, matched like git's pathspecs.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final readonly class PathFilter
{
    private const int GIT_ENOTFOUND = -3;

    /**
     * The part of each path before its first wildcard.
     *
     * @var list<string>
     */
    private array $prefixes;

    private CData $diffOptions;

    /**
     * The C strings the diff options point to, to keep alive as long as them.
     *
     * @var list<CData>
     */
    private array $buffers; // @phpstan-ignore property.onlyWritten

    /**
     * @param list<string> $paths Relative to the repository, without leading or trailing slash
     */
    public function __construct(
        private \FFI $ffi,
        private CData $repo,
        private array $paths,
        CData $diffOptions,
    ) {
        $prefixes = [];
        foreach ($paths as $path) {
            $wildcard = strcspn($path, '*?[\\');
            // A wildcard can match more than a name: keep the directories before it.
            $prefixes[] = \strlen($path) === $wildcard ? $path : substr($path, 0, (int) strrpos(substr($path, 0, $wildcard), '/'));
        }
        $this->prefixes = $prefixes;

        $buffers = [];
        $strings = $ffi->new('char*['.\count($paths).']');
        foreach ($paths as $i => $path) {
            $buffers[] = $buffer = LibGit2::cString($ffi, $path);
            $strings[$i] = $ffi->cast('char *', $buffer);
        }
        $buffers[] = $strings;
        $this->buffers = $buffers;

        $diffOptions->pathspec->strings = $ffi->cast('char **', $strings);
        $diffOptions->pathspec->count = \count($paths);
        $this->diffOptions = $diffOptions;
    }

    /**
     * @param CData|null $oldTree null for the empty tree
     */
    public function differ(?CData $oldTree, CData $newTree): bool
    {
        // Comparing the entries at the paths is much cheaper than a diff, and
        // enough for a path without wildcards.
        $needsDiff = false;
        foreach ($this->paths as $i => $path) {
            $prefix = $this->prefixes[$i];
            if ($this->entryId($oldTree, $prefix) === $this->entryId($newTree, $prefix)) {
                continue;
            }

            if ($prefix === $path) {
                return true;
            }

            $needsDiff = true;
        }

        return $needsDiff && $this->diffHasDeltas($oldTree, $newTree);
    }

    /**
     * @return string|null The hash and the mode of the entry at $path ('' for the tree itself), or null when there is none
     */
    private function entryId(?CData $tree, string $path): ?string
    {
        if (null === $tree) {
            return null;
        }

        if ('' === $path) {
            return LibGit2::oidToHex($this->ffi, $this->ffi->git_tree_id($tree));
        }

        $entry = $this->ffi->new('git_tree_entry*');
        $rc = $this->ffi->git_tree_entry_bypath(\FFI::addr($entry), $tree, $path);
        if (self::GIT_ENOTFOUND === $rc) {
            return null;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to look up "%s"', $path));

        try {
            return LibGit2::oidToHex($this->ffi, $this->ffi->git_tree_entry_id($entry)).' '.$this->ffi->git_tree_entry_filemode($entry);
        } finally {
            $this->ffi->git_tree_entry_free($entry);
        }
    }

    private function diffHasDeltas(?CData $oldTree, CData $newTree): bool
    {
        $diff = $this->ffi->new('git_diff*');
        LibGit2::check($this->ffi, $this->ffi->git_diff_tree_to_tree(\FFI::addr($diff), $this->repo, $oldTree, $newTree, \FFI::addr($this->diffOptions)), 'Unable to compute diff');

        try {
            return $this->ffi->git_diff_num_deltas($diff) > 0;
        } finally {
            $this->ffi->git_diff_free($diff);
        }
    }
}
