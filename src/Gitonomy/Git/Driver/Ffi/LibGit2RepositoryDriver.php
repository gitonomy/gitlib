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
use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Driver\Data\BlameLineData;
use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Driver\Data\TreeEntryData;
use Gitonomy\Git\Driver\Data\TreeEntryType;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Symfony\Component\String\ByteString;
use Symfony\Component\String\Exception\InvalidArgumentException;

/**
 * Runs libgit2 in-process on a repository, through PHP's FFI extension.
 *
 * Every libgit2 object it allocates is freed in a `finally` block, so that a
 * failure halfway through an operation doesn't leak native memory.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal
 */
final class LibGit2RepositoryDriver implements RepositoryDriverInterface
{
    private const int GIT_ENOTFOUND = -3;
    private const int GIT_EUNBORNBRANCH = -9;
    private const int GIT_EINVALIDSPEC = -12;
    private const int GIT_EPEEL = -19;
    private const int GIT_ITEROVER = -31;

    private const int GIT_SORT_NONE = 0;
    private const int GIT_SORT_TOPOLOGICAL = 1 << 0;
    private const int GIT_DIFF_FIND_RENAMES = 1 << 0;
    private const int GIT_STATUS_OPT_INCLUDE_UNTRACKED = 1 << 0;
    private const int GIT_STATUS_OPT_RECURSE_UNTRACKED_DIRS = 1 << 4;
    private const int GIT_STATUS_WT_NEW = 1 << 7;

    private readonly CData $repo;

    public function __construct(
        private readonly \FFI $ffi,
        string $gitDir,
        private readonly ?string $workingDir,
    ) {
        $out = $this->ffi->new('git_repository*');
        LibGit2::check($this->ffi, $this->ffi->git_repository_open(\FFI::addr($out), $gitDir), 'Unable to open repository');

        if (null !== $workingDir) {
            try {
                LibGit2::check($this->ffi, $this->ffi->git_repository_set_workdir($out, $workingDir, 0), 'Unable to set working directory');
            } catch (DriverException $e) {
                // __destruct() isn't called when the constructor throws.
                $this->ffi->git_repository_free($out);

                throw $e;
            }
        }

        $this->repo = $out;
    }

    public function __destruct()
    {
        $this->ffi->git_repository_free($this->repo);
    }

    /**
     * Two drivers sharing one git_repository would free it twice.
     */
    private function __clone()
    {
    }

    public function resolveRevision(string $revision): string
    {
        $object = $this->revparse($revision);

        try {
            return $this->oidToHex($this->ffi->git_object_id($object));
        } finally {
            $this->ffi->git_object_free($object);
        }
    }

    public function getObjectType(string $revision): string
    {
        $object = $this->revparse($revision);

        try {
            return $this->objectTypeName($this->ffi->git_object_type($object));
        } finally {
            $this->ffi->git_object_free($object);
        }
    }

    public function getCommitData(string $hash): CommitData
    {
        $commit = $this->lookupCommit($hash);

        try {
            return $this->commitToData($commit);
        } finally {
            $this->ffi->git_commit_free($commit);
        }
    }

    public function getCommitLog(?array $revisions, array $paths, ?int $offset, ?int $limit): array
    {
        $result = [];
        if (0 === $limit) {
            return $result;
        }

        $skipped = 0;
        foreach ($this->walk($revisions, $paths) as $hash) {
            if (null !== $offset && $skipped < $offset) {
                ++$skipped;

                continue;
            }

            $result[$hash] = $this->getCommitData($hash);

            // Stops before walking to the next commit: it can be far away in the history.
            if (null !== $limit && \count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function countCommits(?array $revisions, array $paths): int
    {
        // Like CliDriver's `rev-list --count`, which counts everything when given no revision.
        return iterator_count($this->walk([] === $revisions ? null : $revisions, $paths));
    }

    public function getShortHash(string $revision): string
    {
        $object = $this->revparse($revision);

        try {
            $buf = $this->ffi->new('git_buf');
            LibGit2::check($this->ffi, $this->ffi->git_object_short_id(\FFI::addr($buf), $object), 'Unable to compute short hash');

            try {
                return \FFI::string($buf->ptr, $buf->size);
            } finally {
                $this->ffi->git_buf_dispose(\FFI::addr($buf));
            }
        } finally {
            $this->ffi->git_object_free($object);
        }
    }

    public function getTreeEntries(string $hash): array
    {
        $tree = $this->lookupTree($hash);

        try {
            $count = $this->ffi->git_tree_entrycount($tree);
            $result = [];
            for ($i = 0; $i < $count; ++$i) {
                $entry = $this->ffi->git_tree_entry_byindex($tree, $i);
                $result[] = new TreeEntryData(
                    \sprintf('%06o', $this->ffi->git_tree_entry_filemode($entry)),
                    $this->treeEntryType($this->ffi->git_tree_entry_type($entry)),
                    $this->oidToHex($this->ffi->git_tree_entry_id($entry)),
                    $this->ffi->git_tree_entry_name($entry),
                );
            }

            return $result;
        } finally {
            $this->ffi->git_tree_free($tree);
        }
    }

    public function getBlobContent(string $hash): string
    {
        $oid = $this->hexToOid($hash);
        $blob = $this->ffi->new('git_blob*');
        LibGit2::check($this->ffi, $this->ffi->git_blob_lookup_prefix(\FFI::addr($blob), $this->repo, \FFI::addr($oid), \strlen($hash)), \sprintf('Unable to find blob "%s"', $hash));

        try {
            return \FFI::string($this->ffi->git_blob_rawcontent($blob), $this->ffi->git_blob_rawsize($blob));
        } finally {
            $this->ffi->git_blob_free($blob);
        }
    }

    public function getTagData(string $revision): TagData
    {
        $object = $this->revparse($revision);

        try {
            if ($this->ffi->git_object_type($object) !== $this->ffi->GIT_OBJECT_TAG) {
                throw new DriverException(\sprintf('"%s" is not an annotated tag.', $revision));
            }

            $tag = $this->ffi->new('git_tag*');
            LibGit2::check($this->ffi, $this->ffi->git_tag_lookup(\FFI::addr($tag), $this->repo, $this->ffi->git_object_id($object)), 'Unable to look up tag');
        } finally {
            $this->ffi->git_object_free($object);
        }

        try {
            $tagger = $this->ffi->git_tag_tagger($tag);
            if (null === $tagger) {
                throw new DriverException(\sprintf('Tag "%s" has no tagger.', $revision));
            }

            [$message, $gpgSignature] = TagData::splitSignature($this->ffi->git_tag_message($tag) ?? '');

            return new TagData(
                $this->oidToHex($this->ffi->git_tag_target_id($tag)),
                $this->objectTypeName($this->ffi->git_tag_target_type($tag)),
                $this->ffi->git_tag_name($tag),
                \FFI::string($tagger->name),
                \FFI::string($tagger->email),
                $this->signatureDate($tagger),
                $message,
                $gpgSignature,
            );
        } finally {
            $this->ffi->git_tag_free($tag);
        }
    }

    public function getReferences(): array
    {
        $result = [];
        foreach ($this->iterateReferences() as $ref) {
            $hash = $this->referenceTargetHex($ref);
            if (null !== $hash) {
                $result[] = new ReferenceData($this->ffi->git_reference_name($ref), $hash);
            }
        }

        return $result;
    }

    public function updateReference(string $fullname, string $hash): void
    {
        $oid = $this->hexToOid($hash);
        $ref = $this->ffi->new('git_reference*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_create(\FFI::addr($ref), $this->repo, $fullname, \FFI::addr($oid), 1, null), \sprintf('Unable to update reference "%s"', $fullname));
        $this->ffi->git_reference_free($ref);
    }

    public function deleteReference(string $fullname): void
    {
        $ref = $this->ffi->new('git_reference*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_lookup(\FFI::addr($ref), $this->repo, $fullname), \sprintf('Unable to find reference "%s"', $fullname));

        try {
            LibGit2::check($this->ffi, $this->ffi->git_reference_delete($ref), \sprintf('Unable to delete reference "%s"', $fullname));
        } finally {
            $this->ffi->git_reference_free($ref);
        }
    }

    public function getIncludingBranches(string $hash): array
    {
        $tips = [];
        foreach ($this->iterateBranches() as [$fullname, $ref]) {
            $tips[$fullname] = $this->oidToHex($this->ffi->git_reference_target($ref));
        }

        // One walk from every branch down to $hash, instead of a merge base
        // per branch: parents first, a commit reaches $hash when one of its
        // parents does.
        $reaching = [$hash => true];
        $commits = iterator_to_array($this->revwalk(array_values($tips), $this->parentHashes($hash), self::GIT_SORT_TOPOLOGICAL), false);
        foreach (array_reverse($commits) as $commit) {
            foreach ($this->parentHashes($commit) as $parent) {
                if (isset($reaching[$parent])) {
                    $reaching[$commit] = true;

                    break;
                }
            }
        }

        return array_keys(array_filter($tips, static fn (string $tip): bool => isset($reaching[$tip])));
    }

    public function getDiff(array $revisions): Diff
    {
        if (2 === \count($revisions) && !str_contains($revisions[0], '..')) {
            [$old, $new] = $revisions;
        } elseif (1 === \count($revisions) && str_contains($revisions[0], '...')) {
            [$a, $new] = $this->rangeEnds($revisions[0], '...');
            $old = $this->getMergeBase($a, $new) ?? throw new DriverException(\sprintf('"%s" and "%s" have no common ancestor.', $a, $new));
        } elseif (1 === \count($revisions) && str_contains($revisions[0], '..')) {
            [$old, $new] = $this->rangeEnds($revisions[0], '..');
        } elseif (1 === \count($revisions)) {
            return $this->diffToWorkdir($revisions[0]);
        } else {
            throw new UnsupportedOperationException(\sprintf('LibGit2Driver cannot diff "%s".', implode(' ', $revisions)));
        }

        $oldTree = $this->treeForRevision($old);

        try {
            $newTree = $this->treeForRevision($new);

            try {
                return $this->diffTrees($oldTree, $newTree);
            } finally {
                $this->ffi->git_tree_free($newTree);
            }
        } finally {
            $this->ffi->git_tree_free($oldTree);
        }
    }

    public function getCommitDiff(string $hash): Diff
    {
        $commit = $this->lookupCommit($hash);

        try {
            $tree = $this->commitTree($commit);

            try {
                $parentCount = $this->ffi->git_commit_parentcount($commit);
                if (0 === $parentCount) {
                    return $this->diffTrees(null, $tree);
                }

                // Like `git diff-tree -m`: one diff per parent of a merge commit.
                $files = [];
                for ($i = 0; $i < $parentCount; ++$i) {
                    $parentTree = $this->parentTree($commit, $i);

                    try {
                        $files = [...$files, ...$this->diffTrees($parentTree, $tree)->getFiles()];
                    } finally {
                        $this->ffi->git_tree_free($parentTree);
                    }
                }

                return new Diff($files, '');
            } finally {
                $this->ffi->git_tree_free($tree);
            }
        } finally {
            $this->ffi->git_commit_free($commit);
        }
    }

    public function getDiffPending(): Diff
    {
        $diff = $this->ffi->new('git_diff*');
        $opts = $this->diffOptions();
        LibGit2::check($this->ffi, $this->ffi->git_diff_index_to_workdir(\FFI::addr($diff), $this->repo, null, \FFI::addr($opts)), 'Unable to compute working copy diff');

        return $this->buildDiff($diff);
    }

    public function getDiffStaged(): Diff
    {
        $tree = $this->headTree();

        try {
            $diff = $this->ffi->new('git_diff*');
            $opts = $this->diffOptions();
            LibGit2::check($this->ffi, $this->ffi->git_diff_tree_to_index(\FFI::addr($diff), $this->repo, $tree, null, \FFI::addr($opts)), 'Unable to compute staged diff');
        } finally {
            if (null !== $tree) {
                $this->ffi->git_tree_free($tree);
            }
        }

        return $this->buildDiff($diff);
    }

    public function getMergeBase(string $revision1, string $revision2): ?string
    {
        $one = $this->hexToOid($this->resolveCommit($revision1));
        $two = $this->hexToOid($this->resolveCommit($revision2));
        $out = $this->ffi->new('git_oid');

        $rc = $this->ffi->git_merge_base(\FFI::addr($out), $this->repo, \FFI::addr($one), \FFI::addr($two));
        if (self::GIT_ENOTFOUND === $rc) {
            return null;
        }
        LibGit2::check($this->ffi, $rc, 'Unable to compute merge base');

        return $this->oidToHex(\FFI::addr($out));
    }

    public function getBlameLines(string $revision, string $file, ?string $lineRange): array
    {
        [$firstLine, $lastLine] = $this->parseLineRange($lineRange);

        $options = $this->ffi->new('git_blame_options');
        LibGit2::check($this->ffi, $this->ffi->git_blame_options_init(\FFI::addr($options), 1), 'Unable to initialize blame options');
        $options->newest_commit = $this->hexToOid($this->resolveCommit($revision));
        $options->min_line = $firstLine;
        $options->max_line = $lastLine ?? 0; // 0: up to the end of the file

        $blame = $this->ffi->new('git_blame*');
        LibGit2::check($this->ffi, $this->ffi->git_blame_file(\FFI::addr($blame), $this->repo, $file, \FFI::addr($options)), \sprintf('Unable to blame "%s"', $file));

        try {
            $count = $this->ffi->git_blame_linecount($blame);
            $result = [];
            for ($i = $firstLine; $i <= min($lastLine ?? $count, $count); ++$i) {
                $hunk = $this->ffi->git_blame_hunk_byline($blame, $i);
                $line = $this->ffi->git_blame_line_byindex($blame, $i);

                if (null === $hunk || null === $line) {
                    // git_blame_linecount() can overcount by one for files not
                    // ending in a newline; stop rather than dereference NULL.
                    // (A NULL `const git_blame_line *` return is auto-converted
                    // to PHP null by FFI, unlike a NULL struct-field pointer.)
                    break;
                }

                $content = rtrim(\FFI::string($line->ptr, $line->len), "\n");
                $sourceLine = (string) ($hunk->orig_start_line_number + ($i - $hunk->final_start_line_number));
                $blockLine = ($i === $hunk->final_start_line_number || $i === $firstLine) ? (string) ($hunk->lines_in_hunk - ($i - $hunk->final_start_line_number)) : null;

                $result[] = new BlameLineData($this->oidToHex(\FFI::addr($hunk->final_commit_id)), $sourceLine, (string) $i, $blockLine, $content);
            }

            return $result;
        } finally {
            $this->ffi->git_blame_free($blame);
        }
    }

    public function getUntrackedFiles(): array
    {
        $opts = $this->ffi->new('git_status_options');
        LibGit2::check($this->ffi, $this->ffi->git_status_options_init(\FFI::addr($opts), 1), 'Unable to initialize status options');
        $opts->flags = self::GIT_STATUS_OPT_INCLUDE_UNTRACKED | self::GIT_STATUS_OPT_RECURSE_UNTRACKED_DIRS;

        $list = $this->ffi->new('git_status_list*');
        LibGit2::check($this->ffi, $this->ffi->git_status_list_new(\FFI::addr($list), $this->repo, \FFI::addr($opts)), 'Unable to compute status');

        try {
            $count = $this->ffi->git_status_list_entrycount($list);
            $result = [];
            for ($i = 0; $i < $count; ++$i) {
                $entry = $this->ffi->git_status_byindex($list, $i);
                if (0 !== ($entry->status & self::GIT_STATUS_WT_NEW)) {
                    $result[] = $entry->index_to_workdir->new_file->path;
                }
            }

            return $result;
        } finally {
            $this->ffi->git_status_list_free($list);
        }
    }

    public function checkout(string $revision): void
    {
        $this->checkoutRevision($revision);

        // `git checkout <revision>` attaches HEAD when <revision> names a
        // local branch, and detaches it for anything else (a tag, a hash, a
        // remote branch, a full reference name...).
        if ($this->referenceExists('refs/heads/'.$revision)) {
            LibGit2::check($this->ffi, $this->ffi->git_repository_set_head($this->repo, 'refs/heads/'.$revision), 'Unable to update HEAD');
        } else {
            $oid = $this->hexToOid($this->resolveCommit($revision));
            LibGit2::check($this->ffi, $this->ffi->git_repository_set_head_detached($this->repo, \FFI::addr($oid)), 'Unable to detach HEAD');
        }
    }

    public function checkoutNewBranch(string $branch, string $revision): void
    {
        if ($this->referenceExists('refs/heads/'.$branch)) {
            throw new DriverException(\sprintf('A branch named "%s" already exists.', $branch));
        }

        $this->checkoutRevision($revision);

        $commit = $this->lookupCommit($this->resolveCommit($revision));

        try {
            $reference = $this->ffi->new('git_reference*');
            LibGit2::check($this->ffi, $this->ffi->git_branch_create(\FFI::addr($reference), $this->repo, $branch, $commit, 0), \sprintf('Unable to create branch "%s"', $branch));
            $this->ffi->git_reference_free($reference);
        } finally {
            $this->ffi->git_commit_free($commit);
        }

        LibGit2::check($this->ffi, $this->ffi->git_repository_set_head($this->repo, 'refs/heads/'.$branch), 'Unable to update HEAD');
    }

    public function merge(string $revision, array $args): void
    {
        $noFastForward = false;
        foreach ($args as $arg) {
            if ('--no-ff' !== $arg) {
                throw new UnsupportedOperationException(\sprintf('LibGit2Driver does not support the "%s" merge argument; use CliDriver.', $arg));
            }
            $noFastForward = true;
        }

        $branch = $this->currentBranch();
        $headHash = $this->resolveCommit('HEAD');
        $theirHash = $this->resolveCommit($revision);
        $head = $this->hexToOid($headHash);
        $their = $this->hexToOid($theirHash);

        // Already up to date, even with --no-ff.
        if ($headHash === $theirHash || $this->isDescendantOf($head, $their)) {
            return;
        }

        if (!$noFastForward && $this->isDescendantOf($their, $head)) {
            $target = $this->revparse($theirHash);

            try {
                $this->checkoutObject($target, 'Unable to fast-forward');
            } finally {
                $this->ffi->git_object_free($target);
            }
            $this->moveHead($branch, $theirHash);

            return;
        }

        $tree = $this->mergeTrees($headHash, $theirHash, $revision);

        try {
            $mergeHash = $this->createMergeCommit($tree, $headHash, $theirHash, $this->mergeMessage($revision, $branch));
            $this->checkoutObject($this->ffi->cast('git_object *', $tree), 'Unable to checkout merge result');
        } finally {
            $this->ffi->git_tree_free($tree);
        }
        $this->moveHead($branch, $mergeHash);
    }

    // -- history -----------------------------------------------------------

    /**
     * Walks the history in `git log`'s default order. With $paths, only
     * yields the commits that change them, and simplifies the history like
     * git does.
     *
     * @param string[]|null $revisions null for every reference
     * @param string[]      $paths
     *
     * @return \Generator<int, string> Commit hashes
     */
    private function walk(?array $revisions, array $paths): \Generator
    {
        $tips = [];
        $bottoms = [];
        foreach ([] === $revisions ? ['HEAD'] : $revisions ?? ['--all'] as $spec) {
            [$specTips, $specBottoms] = $this->revspecEnds($spec);
            array_push($tips, ...$specTips);
            array_push($bottoms, ...$specBottoms);
        }

        $pathFilter = $this->pathFilter($paths);

        if (null === $pathFilter) {
            yield from $this->revwalk($tips, $bottoms);

            return;
        }

        // With hidden commits, libgit2 walks the whole range before yielding the first one anyway.
        $interesting = [] === $bottoms ? null : array_fill_keys(iterator_to_array($this->revwalk($tips, $bottoms), false), true);

        yield from $this->simplifiedHistory($tips, $bottoms, $interesting, $pathFilter);
    }

    /**
     * Walks the history with libgit2, whose default sorting is git's.
     *
     * @param string[] $tips    The commits to walk from
     * @param string[] $bottoms The commits to hide, along with their ancestors
     *
     * @return \Generator<int, string>
     */
    private function revwalk(array $tips, array $bottoms, int $sorting = self::GIT_SORT_NONE): \Generator
    {
        $walker = $this->ffi->new('git_revwalk*');
        LibGit2::check($this->ffi, $this->ffi->git_revwalk_new(\FFI::addr($walker), $this->repo), 'Unable to create revwalk');

        try {
            LibGit2::check($this->ffi, $this->ffi->git_revwalk_sorting($walker, $sorting), 'Unable to sort the walk');

            // git starts from the newest tip (the first given one, at equal
            // dates), libgit2 from the last pushed one.
            $times = array_map($this->commitTime(...), $tips);
            uksort($tips, static fn (int $a, int $b): int => $times[$a] <=> $times[$b] ?: $b <=> $a);
            foreach ($tips as $hash) {
                $oid = $this->hexToOid($hash);
                LibGit2::check($this->ffi, $this->ffi->git_revwalk_push($walker, \FFI::addr($oid)), \sprintf('Unable to push "%s"', $hash));
            }
            foreach ($bottoms as $hash) {
                $oid = $this->hexToOid($hash);
                LibGit2::check($this->ffi, $this->ffi->git_revwalk_hide($walker, \FFI::addr($oid)), \sprintf('Unable to hide "%s"', $hash));
            }

            $oid = $this->ffi->new('git_oid');
            while (0 === ($rc = $this->ffi->git_revwalk_next(\FFI::addr($oid), $walker))) {
                yield $this->oidToHex(\FFI::addr($oid));
            }
            $this->checkIterationEnd($rc, 'Unable to walk the history');
        } finally {
            $this->ffi->git_revwalk_free($walker);
        }
    }

    /**
     * @return array{list<string>, list<string>} The commits a revision walks from, and the ones it hides along with their ancestors
     */
    private function revspecEnds(string $spec): array
    {
        if (str_contains($spec, '...')) {
            // Commits reachable from either end but not from both.
            [$a, $b] = $this->rangeEnds($spec, '...');
            $one = $this->resolveCommit($a);
            $two = $this->resolveCommit($b);

            return [[$one, $two], $this->mergeBases($one, $two)];
        }

        if (str_contains($spec, '..')) {
            [$a, $b] = $this->rangeEnds($spec, '..');

            return [[$this->resolveCommit($b)], [$this->resolveCommit($a)]];
        }

        if (str_starts_with($spec, '^')) {
            return [[], [$this->resolveCommit(substr($spec, 1))]];
        }

        if ('--all' === $spec) {
            return [$this->referencedCommits(), []];
        }

        return [[$this->resolveCommit($spec)], []];
    }

    /**
     * @return array{string, string} Both ends of an "a..b" or "a...b" range, an omitted end meaning HEAD
     */
    private function rangeEnds(string $range, string $separator): array
    {
        [$a, $b] = explode($separator, $range, 2);

        return ['' === $a ? 'HEAD' : $a, '' === $b ? 'HEAD' : $b];
    }

    /**
     * @return list<string> No merge base when the commits have no common ancestor
     */
    private function mergeBases(string $one, string $two): array
    {
        $oneOid = $this->hexToOid($one);
        $twoOid = $this->hexToOid($two);
        $bases = $this->ffi->new('git_oidarray');
        $rc = $this->ffi->git_merge_bases(\FFI::addr($bases), $this->repo, \FFI::addr($oneOid), \FFI::addr($twoOid));
        if (self::GIT_ENOTFOUND === $rc) {
            return [];
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to compute the merge bases of "%s" and "%s"', $one, $two));

        try {
            $hashes = [];
            for ($i = 0; $i < $bases->count; ++$i) {
                $hashes[] = $this->oidToHex(\FFI::addr($bases->ids[$i]));
            }

            return $hashes;
        } finally {
            $this->ffi->git_oidarray_dispose(\FFI::addr($bases));
        }
    }

    /**
     * @return list<string> The commits the references point to; like `git log --all`, ignores the references to other objects and the dangling ones
     */
    private function referencedCommits(): array
    {
        $hashes = [];
        foreach ($this->iterateReferences() as $ref) {
            $commit = $this->ffi->new('git_object*');
            $rc = $this->ffi->git_reference_peel(\FFI::addr($commit), $ref, $this->ffi->GIT_OBJECT_COMMIT);
            if (\in_array($rc, [self::GIT_ENOTFOUND, self::GIT_EINVALIDSPEC, self::GIT_EPEEL], true)) {
                continue;
            }
            LibGit2::check($this->ffi, $rc, \sprintf('Unable to peel "%s"', $this->ffi->git_reference_name($ref)));

            try {
                $hashes[] = $this->oidToHex($this->ffi->git_object_id($commit));
            } finally {
                $this->ffi->git_object_free($commit);
            }
        }

        return $hashes;
    }

    /**
     * @param string[] $paths
     */
    private function pathFilter(array $paths): ?PathFilter
    {
        $paths = array_values(array_filter(
            array_map(fn (string $path): string => trim($this->toRepoRelativePath($path), '/'), $paths),
            static fn (string $path): bool => '' !== $path,
        ));

        return [] === $paths ? null : new PathFilter($this->ffi, $this->repo, $paths, $this->diffOptions());
    }

    /**
     * Walks the history like git does with paths: from a merge that has the
     * same paths as one of its parents, it only follows that parent, and it
     * only yields the commits that change the paths.
     *
     * @param string[]                 $tips        The commits to walk from
     * @param string[]                 $bottoms     The commits hidden along with their ancestors
     * @param array<string, true>|null $interesting The commits the bottoms don't hide, null without bottoms
     *
     * @return \Generator<int, string>
     */
    private function simplifiedHistory(array $tips, array $bottoms, ?array $interesting, PathFilter $pathFilter): \Generator
    {
        // git doesn't simplify a merge to a hidden parent, unless it is a bottom.
        $relevant = null === $interesting ? null : $interesting + array_fill_keys($bottoms, true);

        // Newest commit first, then first queued first, like git.
        $queue = new \SplPriorityQueue();
        $queued = [];
        $enqueue = function (string $hash) use ($queue, &$queued, $interesting): void {
            if (isset($queued[$hash]) || (null !== $interesting && !isset($interesting[$hash]))) {
                return;
            }

            $queued[$hash] = true;
            $queue->insert($hash, [$this->commitTime($hash), -\count($queued)]);
        };

        foreach ($tips as $hash) {
            $enqueue($hash);
        }

        while (!$queue->isEmpty()) {
            $hash = $queue->extract();

            [$parents, $changed] = $this->simplifyCommit($hash, $pathFilter, $relevant);
            foreach ($parents as $parent) {
                $enqueue($parent);
            }

            if ($changed) {
                yield $hash;
            }
        }
    }

    /**
     * Compares a commit with its parents, on the paths of $pathFilter, like
     * git's history simplification.
     *
     * @param array<string, true>|null $relevant The parents a merge can be simplified to, null for any
     *
     * @return array{list<string>, bool} The parents to follow, and whether the commit changes the paths
     */
    private function simplifyCommit(string $hash, PathFilter $pathFilter, ?array $relevant): array
    {
        $commit = $this->lookupCommit($hash);

        try {
            $tree = $this->commitTree($commit);

            try {
                $count = $this->ffi->git_commit_parentcount($commit);
                if (0 === $count) {
                    return [[], $pathFilter->differ(null, $tree)];
                }

                $parents = [];
                $changed = false;
                for ($i = 0; $i < $count; ++$i) {
                    $parents[] = $parent = $this->oidToHex($this->ffi->git_commit_parent_id($commit, $i));
                    $parentTree = $this->parentTree($commit, $i);

                    try {
                        $same = !$pathFilter->differ($parentTree, $tree);
                    } finally {
                        $this->ffi->git_tree_free($parentTree);
                    }

                    if ($same && (null === $relevant || isset($relevant[$parent]))) {
                        return [[$parent], false];
                    }

                    $changed = $changed || !$same;
                }

                return [$parents, $changed];
            } finally {
                $this->ffi->git_tree_free($tree);
            }
        } finally {
            $this->ffi->git_commit_free($commit);
        }
    }

    /**
     * git also accepts the absolute paths of files in the working tree, where
     * libgit2 only deals with paths relative to the repository.
     */
    private function toRepoRelativePath(string $path): string
    {
        if (null !== $this->workingDir && str_starts_with($path, $this->workingDir.'/')) {
            return substr($path, \strlen($this->workingDir) + 1);
        }

        return $path;
    }

    // -- diff ----------------------------------------------------------------

    private function diffToWorkdir(string $revision): Diff
    {
        $tree = $this->treeForRevision($revision);

        try {
            $diff = $this->ffi->new('git_diff*');
            $opts = $this->diffOptions();
            LibGit2::check($this->ffi, $this->ffi->git_diff_tree_to_workdir_with_index(\FFI::addr($diff), $this->repo, $tree, \FFI::addr($opts)), 'Unable to compute working copy diff');
        } finally {
            $this->ffi->git_tree_free($tree);
        }

        return $this->buildDiff($diff);
    }

    private function diffTrees(?CData $oldTree, CData $newTree): Diff
    {
        $diff = $this->ffi->new('git_diff*');
        $opts = $this->diffOptions();
        LibGit2::check($this->ffi, $this->ffi->git_diff_tree_to_tree(\FFI::addr($diff), $this->repo, $oldTree, $newTree, \FFI::addr($opts)), 'Unable to compute diff');

        return $this->buildDiff($diff);
    }

    private function diffOptions(): CData
    {
        $opts = $this->ffi->new('git_diff_options');
        LibGit2::check($this->ffi, $this->ffi->git_diff_options_init(\FFI::addr($opts), 1), 'Unable to initialize diff options');
        $opts->context_lines = 3;

        return $opts;
    }

    /**
     * Converts a git_diff to a Diff, and frees it.
     */
    private function buildDiff(CData $diff): Diff
    {
        try {
            // Like CliDriver's `-M`.
            $findOptions = $this->ffi->new('git_diff_find_options');
            LibGit2::check($this->ffi, $this->ffi->git_diff_find_options_init(\FFI::addr($findOptions), 1), 'Unable to initialize rename detection options');
            $findOptions->flags = self::GIT_DIFF_FIND_RENAMES;
            LibGit2::check($this->ffi, $this->ffi->git_diff_find_similar($diff, \FFI::addr($findOptions)), 'Unable to detect renames');

            $files = [];
            $count = $this->ffi->git_diff_num_deltas($diff);
            for ($i = 0; $i < $count; ++$i) {
                $files[] = $this->patchToFile($diff, $i);
            }

            return new Diff($files, '');
        } finally {
            $this->ffi->git_diff_free($diff);
        }
    }

    private function patchToFile(CData $diff, int $index): File
    {
        $patch = $this->ffi->new('git_patch*');
        LibGit2::check($this->ffi, $this->ffi->git_patch_from_diff(\FFI::addr($patch), $diff, $index), 'Unable to compute patch');

        if (\FFI::isNull($patch)) {
            // No patch for a binary or unchanged file: computing it flagged
            // the delta accordingly.
            return $this->deltaToFile($this->ffi->git_diff_get_delta($diff, $index));
        }

        try {
            $file = $this->deltaToFile($this->ffi->git_patch_get_delta($patch));

            $hunkCount = $this->ffi->git_patch_num_hunks($patch);
            for ($hunkIndex = 0; $hunkIndex < $hunkCount; ++$hunkIndex) {
                $hunk = $this->ffi->new('git_diff_hunk*');
                $lineCount = $this->ffi->new('size_t');
                LibGit2::check($this->ffi, $this->ffi->git_patch_get_hunk(\FFI::addr($hunk), \FFI::addr($lineCount), $patch, $hunkIndex), 'Unable to read diff hunk');

                $lines = [];
                for ($lineIndex = 0; $lineIndex < $lineCount->cdata; ++$lineIndex) {
                    $line = $this->ffi->new('git_diff_line*');
                    LibGit2::check($this->ffi, $this->ffi->git_patch_get_line_in_hunk(\FFI::addr($line), $patch, $hunkIndex, $lineIndex), 'Unable to read diff line');

                    // ->origin is a scalar `char`, which PHP FFI represents
                    // as a single-character PHP string.
                    $type = match ($line->origin) {
                        '+' => FileChange::LINE_ADD,
                        '-' => FileChange::LINE_REMOVE,
                        ' ' => FileChange::LINE_CONTEXT,
                        default => null,
                    };

                    if (null !== $type) {
                        $lines[] = [$type, rtrim(\FFI::string($line->content, $line->content_len), "\n")];
                    }
                }

                $file->addChange(new FileChange($hunk->old_start, $hunk->old_lines, $hunk->new_start, $hunk->new_lines, $lines));
            }

            return $file;
        } finally {
            $this->ffi->git_patch_free($patch);
        }
    }

    private function deltaToFile(CData $delta): File
    {
        $status = $delta->status;
        $isCopy = 5 === $status; // GIT_DELTA_COPIED
        $isAdded = 1 === $status; // GIT_DELTA_ADDED
        $isDeleted = 2 === $status; // GIT_DELTA_DELETED
        $isBinary = 0 !== ($delta->flags & (1 << 0)); // GIT_DIFF_FLAG_BINARY

        $oldName = $isAdded ? null : $delta->old_file->path;
        $newName = $isDeleted ? null : $delta->new_file->path;

        $oldMode = $isAdded ? null : \sprintf('%06o', $delta->old_file->mode);
        $newMode = $isDeleted ? null : \sprintf('%06o', $delta->new_file->mode);

        $oldIndex = $this->ffi->git_oid_is_zero(\FFI::addr($delta->old_file->id)) ? null : $this->oidToHex(\FFI::addr($delta->old_file->id));
        $newIndex = $this->ffi->git_oid_is_zero(\FFI::addr($delta->new_file->id)) ? null : $this->oidToHex(\FFI::addr($delta->new_file->id));

        return new File($oldName, $newName, $oldMode, $newMode, $oldIndex, $newIndex, $isBinary, $isCopy);
    }

    // -- working copy --------------------------------------------------------

    private function checkoutRevision(string $revision): void
    {
        $target = $this->revparse($revision);

        try {
            $this->checkoutObject($target, \sprintf('Unable to checkout "%s"', $revision));
        } finally {
            $this->ffi->git_object_free($target);
        }
    }

    /**
     * Safe checkout (libgit2's default strategy): like `git checkout`, it
     * refuses to overwrite local changes, and keeps the ones it doesn't
     * conflict with.
     */
    private function checkoutObject(CData $treeish, string $context): void
    {
        $opts = $this->ffi->new('git_checkout_options');
        LibGit2::check($this->ffi, $this->ffi->git_checkout_options_init(\FFI::addr($opts), 1), 'Unable to initialize checkout options');
        LibGit2::check($this->ffi, $this->ffi->git_checkout_tree($this->repo, $treeish, \FFI::addr($opts)), $context);
    }

    /**
     * Points the checked out branch, or a detached HEAD, to $hash.
     */
    private function moveHead(?string $branch, string $hash): void
    {
        if (null !== $branch) {
            $this->updateReference($branch, $hash);

            return;
        }

        $oid = $this->hexToOid($hash);
        LibGit2::check($this->ffi, $this->ffi->git_repository_set_head_detached($this->repo, \FFI::addr($oid)), 'Unable to update HEAD');
    }

    /**
     * Merges two commits in memory and writes the resulting tree.
     */
    private function mergeTrees(string $oursHash, string $theirsHash, string $revision): CData
    {
        $ours = $this->lookupCommit($oursHash);

        try {
            $theirs = $this->lookupCommit($theirsHash);

            try {
                $mergeOpts = $this->ffi->new('git_merge_options');
                LibGit2::check($this->ffi, $this->ffi->git_merge_options_init(\FFI::addr($mergeOpts), 1), 'Unable to initialize merge options');

                $index = $this->ffi->new('git_index*');
                LibGit2::check($this->ffi, $this->ffi->git_merge_commits(\FFI::addr($index), $this->repo, $ours, $theirs, \FFI::addr($mergeOpts)), \sprintf('Unable to merge "%s"', $revision));

                try {
                    if (0 !== $this->ffi->git_index_has_conflicts($index)) {
                        throw new DriverException(\sprintf('Merging "%s" produces conflicts, which LibGit2Driver does not resolve.', $revision));
                    }

                    $treeOid = $this->ffi->new('git_oid');
                    LibGit2::check($this->ffi, $this->ffi->git_index_write_tree_to(\FFI::addr($treeOid), $index, $this->repo), 'Unable to write merge tree');

                    return $this->lookupTree($this->oidToHex(\FFI::addr($treeOid)));
                } finally {
                    $this->ffi->git_index_free($index);
                }
            } finally {
                $this->ffi->git_commit_free($theirs);
            }
        } finally {
            $this->ffi->git_commit_free($ours);
        }
    }

    private function createMergeCommit(CData $tree, string $oursHash, string $theirsHash, string $message): string
    {
        $signature = $this->ffi->new('git_signature*');
        LibGit2::check($this->ffi, $this->ffi->git_signature_default(\FFI::addr($signature), $this->repo), 'Unable to determine the merge commit author (configure user.name and user.email)');

        try {
            $ours = $this->lookupCommit($oursHash);

            try {
                $theirs = $this->lookupCommit($theirsHash);

                try {
                    $parents = $this->ffi->new('git_commit*[2]');
                    $parents[0] = $ours;
                    $parents[1] = $theirs;

                    $oid = $this->ffi->new('git_oid');
                    LibGit2::check($this->ffi, $this->ffi->git_commit_create(\FFI::addr($oid), $this->repo, null, $signature, $signature, null, $message, $tree, 2, $parents), 'Unable to create merge commit');

                    return $this->oidToHex(\FFI::addr($oid));
                } finally {
                    $this->ffi->git_commit_free($theirs);
                }
            } finally {
                $this->ffi->git_commit_free($ours);
            }
        } finally {
            $this->ffi->git_signature_free($signature);
        }
    }

    /**
     * The message `git merge` writes (see git's fmt-merge-msg).
     */
    private function mergeMessage(string $revision, ?string $branch): string
    {
        $message = null;
        // Same lookup order as git's revision parsing.
        foreach ([
            'refs/tags/' => "Merge tag '%s'",
            'refs/heads/' => "Merge branch '%s'",
            'refs/remotes/' => "Merge remote-tracking branch '%s'",
        ] as $prefix => $format) {
            $name = str_starts_with($revision, $prefix) ? substr($revision, \strlen($prefix)) : $revision;
            if ($this->referenceExists($prefix.$name)) {
                $message = \sprintf($format, $name);

                break;
            }
        }
        $message ??= \sprintf("Merge commit '%s'", $revision);

        // git leaves out the destination when merging into main or master.
        if (null !== $branch && !\in_array($branch, ['refs/heads/main', 'refs/heads/master'], true)) {
            $message .= ' into '.substr($branch, \strlen('refs/heads/'));
        }

        return $message."\n";
    }

    // -- references ----------------------------------------------------------

    /**
     * @return string|null The full name of the checked out branch, or null when HEAD is detached
     */
    private function currentBranch(): ?string
    {
        $detached = $this->ffi->git_repository_head_detached($this->repo);
        LibGit2::check($this->ffi, $detached, 'Unable to resolve HEAD');
        if (1 === $detached) {
            return null;
        }

        $head = $this->ffi->new('git_reference*');
        LibGit2::check($this->ffi, $this->ffi->git_repository_head(\FFI::addr($head), $this->repo), 'Unable to resolve HEAD');

        try {
            return $this->ffi->git_reference_name($head);
        } finally {
            $this->ffi->git_reference_free($head);
        }
    }

    private function referenceExists(string $fullname): bool
    {
        $ref = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_reference_lookup(\FFI::addr($ref), $this->repo, $fullname);
        if (self::GIT_ENOTFOUND === $rc || self::GIT_EINVALIDSPEC === $rc) {
            return false;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to look up reference "%s"', $fullname));
        $this->ffi->git_reference_free($ref);

        return true;
    }

    /**
     * @return \Generator<int, CData> The git_reference* of every reference under refs/, freed once the consumer moves on
     */
    private function iterateReferences(): \Generator
    {
        $iter = $this->ffi->new('git_reference_iterator*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_iterator_glob_new(\FFI::addr($iter), $this->repo, 'refs/*'), 'Unable to create reference iterator');

        try {
            $ref = $this->ffi->new('git_reference*');
            while (0 === ($rc = $this->ffi->git_reference_next(\FFI::addr($ref), $iter))) {
                try {
                    yield $ref;
                } finally {
                    $this->ffi->git_reference_free($ref);
                }
            }
            $this->checkIterationEnd($rc, 'Unable to list references');
        } finally {
            $this->ffi->git_reference_iterator_free($iter);
        }
    }

    /**
     * @return \Generator<int, array{string, CData}> The full name and the git_reference* of every local and remote branch, symbolic ones excluded; the reference is freed once the consumer moves on
     */
    private function iterateBranches(): \Generator
    {
        $iter = $this->ffi->new('git_branch_iterator*');
        LibGit2::check($this->ffi, $this->ffi->git_branch_iterator_new(\FFI::addr($iter), $this->repo, $this->ffi->GIT_BRANCH_ALL), 'Unable to create branch iterator');

        try {
            $ref = $this->ffi->new('git_reference*');
            $type = $this->ffi->new('git_branch_t');
            while (0 === ($rc = $this->ffi->git_branch_next(\FFI::addr($ref), \FFI::addr($type), $iter))) {
                try {
                    if ($this->ffi->git_reference_type($ref) === $this->ffi->GIT_REFERENCE_DIRECT) {
                        yield [$this->ffi->git_reference_name($ref), $ref];
                    }
                } finally {
                    $this->ffi->git_reference_free($ref);
                }
            }
            $this->checkIterationEnd($rc, 'Unable to list branches');
        } finally {
            $this->ffi->git_branch_iterator_free($iter);
        }
    }

    private function referenceTargetHex(CData $ref): ?string
    {
        if ($this->ffi->git_reference_type($ref) === $this->ffi->GIT_REFERENCE_SYMBOLIC) {
            $resolved = $this->ffi->new('git_reference*');
            if (0 !== $this->ffi->git_reference_resolve(\FFI::addr($resolved), $ref)) {
                // Dangling symbolic reference.
                return null;
            }

            try {
                return $this->oidToHex($this->ffi->git_reference_target($resolved));
            } finally {
                $this->ffi->git_reference_free($resolved);
            }
        }

        $target = $this->ffi->git_reference_target($ref);
        if (null === $target || \FFI::isNull($target)) {
            return null;
        }

        return $this->oidToHex($target);
    }

    private function isDescendantOf(CData $commit, CData $ancestor): bool
    {
        $rc = $this->ffi->git_graph_descendant_of($this->repo, \FFI::addr($commit), \FFI::addr($ancestor));
        LibGit2::check($this->ffi, $rc, 'Unable to compare commits');

        return 1 === $rc;
    }

    // -- objects -------------------------------------------------------------

    private function revparse(string $spec): CData
    {
        $out = $this->ffi->new('git_object*');
        LibGit2::check($this->ffi, $this->ffi->git_revparse_single(\FFI::addr($out), $this->repo, $spec), \sprintf('Unable to resolve "%s"', $spec));

        return $out;
    }

    /**
     * Resolves a revision to a commit hash, peeling annotated tags.
     */
    private function resolveCommit(string $revision): string
    {
        return $this->resolveRevision($revision.'^{commit}');
    }

    private function lookupCommit(string $hash): CData
    {
        $oid = $this->hexToOid($hash);
        $out = $this->ffi->new('git_commit*');
        LibGit2::check($this->ffi, $this->ffi->git_commit_lookup_prefix(\FFI::addr($out), $this->repo, \FFI::addr($oid), \strlen($hash)), \sprintf('Unable to find commit "%s"', $hash));

        return $out;
    }

    /**
     * @return list<string>
     */
    private function parentHashes(string $hash): array
    {
        $commit = $this->lookupCommit($hash);

        try {
            $parents = [];
            for ($i = 0, $count = $this->ffi->git_commit_parentcount($commit); $i < $count; ++$i) {
                $parents[] = $this->oidToHex($this->ffi->git_commit_parent_id($commit, $i));
            }

            return $parents;
        } finally {
            $this->ffi->git_commit_free($commit);
        }
    }

    private function commitTime(string $hash): int
    {
        $commit = $this->lookupCommit($hash);

        try {
            return $this->ffi->git_commit_committer($commit)->when->time;
        } finally {
            $this->ffi->git_commit_free($commit);
        }
    }

    private function lookupTree(string $hash): CData
    {
        $oid = $this->hexToOid($hash);
        $out = $this->ffi->new('git_tree*');
        LibGit2::check($this->ffi, $this->ffi->git_tree_lookup_prefix(\FFI::addr($out), $this->repo, \FFI::addr($oid), \strlen($hash)), \sprintf('Unable to find tree "%s"', $hash));

        return $out;
    }

    private function commitTree(CData $commit): CData
    {
        $out = $this->ffi->new('git_tree*');
        LibGit2::check($this->ffi, $this->ffi->git_commit_tree(\FFI::addr($out), $commit), 'Unable to get commit tree');

        return $out;
    }

    private function parentTree(CData $commit, int $n): CData
    {
        $parent = $this->ffi->new('git_commit*');
        LibGit2::check($this->ffi, $this->ffi->git_commit_parent(\FFI::addr($parent), $commit, $n), 'Unable to get parent commit');

        try {
            return $this->commitTree($parent);
        } finally {
            $this->ffi->git_commit_free($parent);
        }
    }

    private function treeForRevision(string $spec): CData
    {
        $object = $this->revparse($spec);

        try {
            $peeled = $this->ffi->new('git_object*');
            LibGit2::check($this->ffi, $this->ffi->git_object_peel(\FFI::addr($peeled), $object, $this->ffi->GIT_OBJECT_TREE), \sprintf('Unable to resolve "%s" to a tree', $spec));

            try {
                // git_object_peel() hands back a generic git_object*; look it
                // up again as the git_tree* the diff functions expect.
                return $this->lookupTree($this->oidToHex($this->ffi->git_object_id($peeled)));
            } finally {
                $this->ffi->git_object_free($peeled);
            }
        } finally {
            $this->ffi->git_object_free($object);
        }
    }

    /**
     * @return CData|null The tree of HEAD, or null on an unborn branch
     */
    private function headTree(): ?CData
    {
        $head = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_repository_head(\FFI::addr($head), $this->repo);
        if (self::GIT_EUNBORNBRANCH === $rc || self::GIT_ENOTFOUND === $rc) {
            return null;
        }
        LibGit2::check($this->ffi, $rc, 'Unable to resolve HEAD');

        try {
            $peeled = $this->ffi->new('git_object*');
            LibGit2::check($this->ffi, $this->ffi->git_reference_peel(\FFI::addr($peeled), $head, $this->ffi->GIT_OBJECT_TREE), 'Unable to peel HEAD to a tree');

            try {
                return $this->lookupTree($this->oidToHex($this->ffi->git_object_id($peeled)));
            } finally {
                $this->ffi->git_object_free($peeled);
            }
        } finally {
            $this->ffi->git_reference_free($head);
        }
    }

    private function commitToData(CData $commit): CommitData
    {
        $parentCount = $this->ffi->git_commit_parentcount($commit);
        $parents = [];
        for ($i = 0; $i < $parentCount; ++$i) {
            $parents[] = $this->oidToHex($this->ffi->git_commit_parent_id($commit, $i));
        }

        $author = $this->ffi->git_commit_author($commit);
        $committer = $this->ffi->git_commit_committer($commit);
        $encoding = $this->ffi->git_commit_message_encoding($commit);

        return new CommitData(
            $this->oidToHex($this->ffi->git_commit_tree_id($commit)),
            $parents,
            $this->toUtf8(\FFI::string($author->name), $encoding),
            $this->toUtf8(\FFI::string($author->email), $encoding),
            $this->signatureDate($author),
            $this->toUtf8(\FFI::string($committer->name), $encoding),
            $this->toUtf8(\FFI::string($committer->email), $encoding),
            $this->signatureDate($committer),
            self::cleanMessage($this->toUtf8($this->ffi->git_commit_message($commit), $encoding)),
        );
    }

    /**
     * Like `git log`, which CliDriver reads commits with: without
     * blank lines around it, nor whitespace at the end of its lines.
     */
    private static function cleanMessage(string $message): string
    {
        $message = trim(preg_replace('/[ \t\r]+$/m', '', $message), "\n");

        return '' === $message ? '' : $message."\n";
    }

    /**
     * Re-encodes a text of a commit from its "encoding" header, like `git log --encoding=UTF-8`.
     */
    private function toUtf8(string $text, ?string $encoding): string
    {
        if (null === $encoding) {
            return $text;
        }

        try {
            return new ByteString($text)->toCodePointString($encoding)->toString();
        } catch (\ValueError|InvalidArgumentException) {
            // Like git, keep the text as is when the encoding is unknown or wrong.
            return $text;
        }
    }

    private function signatureDate(CData $signature): \DateTimeImmutable
    {
        // Same timezone as CliDriver's parsers.
        return new \DateTimeImmutable('@'.$signature->when->time)->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @return array{int, int|null} The first line, and the last one (null for the end of the file)
     */
    private function parseLineRange(?string $lineRange): array
    {
        if (null === $lineRange) {
            return [1, null];
        }

        if (!preg_match('/^(\d+),(\+?)(\d*)$/', $lineRange, $vars)) {
            throw new UnsupportedOperationException(\sprintf('LibGit2Driver only supports "<start>,<end>" and "<start>,+<count>" line ranges, got "%s"; use CliDriver.', $lineRange));
        }

        $first = (int) $vars[1];
        if ('' === $vars[3]) {
            return [$first, null];
        }

        return [$first, '+' === $vars[2] ? $first + (int) $vars[3] - 1 : (int) $vars[3]];
    }

    private function treeEntryType(int $type): TreeEntryType
    {
        return match ($type) {
            2 => TreeEntryType::Tree,
            3 => TreeEntryType::Blob,
            default => TreeEntryType::Commit,
        };
    }

    private function objectTypeName(int $type): string
    {
        return match ($type) {
            1 => 'commit',
            2 => 'tree',
            3 => 'blob',
            4 => 'tag',
            default => throw new DriverException(\sprintf('Unknown git object type %d.', $type)),
        };
    }

    /**
     * @param CData $oidPtr a `git_oid*` — callers holding a `git_oid` value must pass `FFI::addr($value)`
     */
    private function oidToHex(CData $oidPtr): string
    {
        return LibGit2::oidToHex($this->ffi, $oidPtr);
    }

    /**
     * Also accepts an abbreviated hash: lookups then go through the *_lookup_prefix() functions.
     */
    private function hexToOid(string $hash): CData
    {
        $oid = $this->ffi->new('git_oid');
        LibGit2::check($this->ffi, $this->ffi->git_oid_fromstrn(\FFI::addr($oid), $hash, \strlen($hash)), \sprintf('Invalid hash "%s"', $hash));

        return $oid;
    }

    /**
     * libgit2 iterators return GIT_ITEROVER once exhausted, and another
     * negative code when they stop on an error.
     */
    private function checkIterationEnd(int $rc, string $context): void
    {
        if (self::GIT_ITEROVER !== $rc) {
            LibGit2::check($this->ffi, $rc, $context);
        }
    }
}
