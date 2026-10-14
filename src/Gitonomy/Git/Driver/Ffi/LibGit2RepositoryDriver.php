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
use Gitonomy\Git\Diff\DiffLine;
use Gitonomy\Git\Diff\DiffLineType;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Driver\Data\BlameLineData;
use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Driver\Data\DiffData;
use Gitonomy\Git\Driver\Data\DiffFileData;
use Gitonomy\Git\Driver\Data\ObjectType;
use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Driver\Data\TreeEntryData;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\FastForward;
use Gitonomy\Git\Util\LeadingDash;
use Gitonomy\Git\Util\NulByte;
use Gitonomy\Git\Util\ReferenceName;
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
final readonly class LibGit2RepositoryDriver implements RepositoryDriverInterface
{
    private const int GIT_EAMBIGUOUS = -5;
    private const int GIT_EUNBORNBRANCH = -9;
    private const int GIT_EINVALIDSPEC = -12;
    private const int GIT_EPEEL = -19;
    private const int GIT_ITEROVER = -31;

    private const int GIT_SORT_NONE = 0;
    private const int GIT_SORT_TOPOLOGICAL = 1 << 0;
    private const int GIT_DELTA_ADDED = 1;
    private const int GIT_DELTA_CONFLICTED = 10;
    private const int GIT_DELTA_COPIED = 5;
    private const int GIT_DELTA_DELETED = 2;
    private const int GIT_DIFF_DISABLE_PATHSPEC_MATCH = 1 << 12;
    private const int GIT_DIFF_FLAG_BINARY = 1 << 0;
    private const int GIT_DIFF_INDENT_HEURISTIC = 1 << 18;
    private const int GIT_DIFF_FIND_RENAMES = 1 << 0;
    private const int GIT_DIRECTION_FETCH = 0;
    private const int GIT_INDEX_ENTRY_INTENT_TO_ADD = 1 << 13;
    private const int GIT_STATUS_OPT_INCLUDE_UNTRACKED = 1 << 0;
    private const int GIT_STATUS_OPT_RECURSE_UNTRACKED_DIRS = 1 << 4;
    private const int GIT_STATUS_WT_NEW = 1 << 7;

    /**
     * The hash of the empty file, which `git add -N` stages.
     */
    private const string EMPTY_BLOB = 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391';

    private CData $repo;

    /**
     * @param string      $gitDir     The git directory
     * @param string|null $workingDir The working tree, null for a bare repository
     *
     * @throws UnsupportedOperationException The repository stores its references in the reftable format
     */
    public function __construct(
        private \FFI $ffi,
        private string $gitDir,
        private ?string $workingDir,
    ) {
        // libgit2 1.9 refuses to open it, as any repository with an extension it doesn't know.
        if (is_dir($gitDir.'/reftable')) {
            throw new UnsupportedOperationException(\sprintf('The repository "%s" stores its references in the reftable format, which LibGit2Driver doesn\'t support; use CliDriver.', $gitDir));
        }

        $out = $this->ffi->new('git_repository*');
        LibGit2::check($this->ffi, $this->ffi->git_repository_open(\FFI::addr($out), $gitDir), \sprintf('Unable to open the repository "%s"', $gitDir));

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

    public function getGitDir(): string
    {
        return $this->gitDir;
    }

    public function getWorkingDir(): ?string
    {
        return $this->workingDir;
    }

    public function getHeadReference(): ?string
    {
        $head = $this->ffi->new('git_reference*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_lookup(\FFI::addr($head), $this->repo, 'HEAD'), 'Unable to read HEAD');

        try {
            if ($this->ffi->git_reference_type($head) !== $this->ffi->GIT_REFERENCE_SYMBOLIC) {
                return null;
            }

            return $this->ffi->git_reference_symbolic_target($head);
        } finally {
            $this->ffi->git_reference_free($head);
        }
    }

    public function resolveRevision(string $revision): string
    {
        NulByte::assertNone($revision);
        LeadingDash::assertNone($revision);

        $object = $this->ffi->new('git_object*');
        $rc = $this->ffi->git_revparse_single(\FFI::addr($object), $this->repo, self::expandAt($revision));
        if (\in_array($rc, [LibGit2::GIT_ENOTFOUND, self::GIT_EAMBIGUOUS, self::GIT_EINVALIDSPEC, self::GIT_EPEEL], true)) {
            throw new ReferenceNotFoundException(\sprintf('The revision "%s" does not exist.', $revision));
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to resolve "%s"', $revision));

        try {
            return $this->oidToHex($this->ffi->git_object_id($object));
        } finally {
            $this->ffi->git_object_free($object);
        }
    }

    public function getCommitData(string $hash): CommitData
    {
        NulByte::assertNone($hash);
        LeadingDash::assertNone($hash);

        $commit = $this->lookupCommit($hash);

        try {
            return $this->commitToData($commit);
        } finally {
            $this->ffi->git_commit_free($commit);
        }
    }

    public function getCommitLog(?array $includes, array $excludes, array $paths, int $offset, ?int $limit): array
    {
        NulByte::assertNone(...$includes ?? [], ...$excludes, ...$paths);
        LeadingDash::assertNone(...$includes ?? [], ...$excludes);

        $result = [];
        if (0 === $limit) {
            return $result;
        }

        $skipped = 0;
        foreach ($this->walk($includes, $excludes, $paths) as $hash) {
            if ($skipped < $offset) {
                ++$skipped;

                continue;
            }

            $result[] = $this->getCommitData($hash);

            // Stops before walking to the next commit: it can be far away in the history.
            if (null !== $limit && \count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function countCommits(?array $includes, array $excludes, array $paths): int
    {
        NulByte::assertNone(...$includes ?? [], ...$excludes, ...$paths);
        LeadingDash::assertNone(...$includes ?? [], ...$excludes);

        return iterator_count($this->walk($includes, $excludes, $paths));
    }

    public function getMergeBases(string $hash1, string $hash2): array
    {
        NulByte::assertNone($hash1, $hash2);
        LeadingDash::assertNone($hash1, $hash2);

        $oneOid = $this->hexToOid($hash1);
        $twoOid = $this->hexToOid($hash2);
        $bases = $this->ffi->new('git_oidarray');
        $rc = $this->ffi->git_merge_bases(\FFI::addr($bases), $this->repo, \FFI::addr($oneOid), \FFI::addr($twoOid));
        if (LibGit2::GIT_ENOTFOUND === $rc) {
            return [];
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to compute the merge bases of "%s" and "%s"', $hash1, $hash2));

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

    public function getShortHash(string $hash): string
    {
        NulByte::assertNone($hash);
        LeadingDash::assertNone($hash);

        $object = $this->lookupObject($hash);

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
        NulByte::assertNone($hash);

        $tree = $this->lookupTree($hash);

        try {
            $count = $this->ffi->git_tree_entrycount($tree);
            $result = [];
            for ($i = 0; $i < $count; ++$i) {
                $entry = $this->ffi->git_tree_entry_byindex($tree, $i);
                $result[] = new TreeEntryData(
                    \sprintf('%06o', $this->ffi->git_tree_entry_filemode($entry)),
                    $this->objectType($this->ffi->git_tree_entry_type($entry)),
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
        NulByte::assertNone($hash);

        $oid = $this->hexToOid($hash);
        $blob = $this->ffi->new('git_blob*');
        $this->checkFound($this->ffi->git_blob_lookup_prefix(\FFI::addr($blob), $this->repo, \FFI::addr($oid), \strlen($hash)), $hash, ObjectType::Blob, 'Unable to read the blob');

        try {
            return \FFI::string($this->ffi->git_blob_rawcontent($blob), $this->ffi->git_blob_rawsize($blob));
        } finally {
            $this->ffi->git_blob_free($blob);
        }
    }

    public function getTagData(string $hash): TagData
    {
        NulByte::assertNone($hash);

        $object = $this->lookupObject($hash);

        try {
            if ($this->ffi->git_object_type($object) !== $this->ffi->GIT_OBJECT_TAG) {
                throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a tag.', $hash));
            }

            $tag = $this->ffi->new('git_tag*');
            LibGit2::check($this->ffi, $this->ffi->git_tag_lookup(\FFI::addr($tag), $this->repo, $this->ffi->git_object_id($object)), 'Unable to look up tag');
        } finally {
            $this->ffi->git_object_free($object);
        }

        try {
            // The oldest tags, like the first ones of git itself, have no tagger.
            $tagger = $this->ffi->git_tag_tagger($tag);

            return TagData::fromSignedMessage(
                null === $tagger ? null : \FFI::string($tagger->name),
                null === $tagger ? null : \FFI::string($tagger->email),
                null === $tagger ? null : $this->signatureDate($tagger),
                $this->ffi->git_tag_message($tag) ?? '',
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
            if (null === $hash) {
                continue;
            }

            $fullname = $this->ffi->git_reference_name($ref);
            // Like git, through an annotated tag, whatever the name of the reference: a fetch can put one under refs/remotes/.
            $commitHash = $this->peeledHex($ref);
            $result[$fullname] = new ReferenceData($fullname, $commitHash, $commitHash === $hash ? null : $hash);
        }

        // Like git, sorted by name: libgit2 lists the loose references first.
        ksort($result, \SORT_STRING);

        return array_values($result);
    }

    public function createReference(string $fullname, string $hash): void
    {
        NulByte::assertNone($fullname, $hash);
        LeadingDash::assertNone($fullname, $hash);
        ReferenceName::assertUnderRefs($fullname);

        $oid = $this->hexToOid($hash);
        $ref = $this->ffi->new('git_reference*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_create(\FFI::addr($ref), $this->repo, $fullname, \FFI::addr($oid), 0, null), \sprintf('Unable to create reference "%s"', $fullname));
        $this->ffi->git_reference_free($ref);
    }

    public function updateReference(string $fullname, string $hash): void
    {
        NulByte::assertNone($fullname, $hash);
        LeadingDash::assertNone($fullname, $hash);
        ReferenceName::assertUnderRefs($fullname);

        $oid = $this->hexToOid($hash);
        $ref = $this->ffi->new('git_reference*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_create(\FFI::addr($ref), $this->repo, $fullname, \FFI::addr($oid), 1, null), \sprintf('Unable to update reference "%s"', $fullname));
        $this->ffi->git_reference_free($ref);
    }

    public function deleteReference(string $fullname): void
    {
        NulByte::assertNone($fullname);
        LeadingDash::assertNone($fullname);
        ReferenceName::assertUnderRefs($fullname);

        $ref = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_reference_lookup(\FFI::addr($ref), $this->repo, $fullname);
        // Like git update-ref -d, deleting a missing reference does nothing.
        if (LibGit2::GIT_ENOTFOUND === $rc) {
            return;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to find reference "%s"', $fullname));

        try {
            LibGit2::check($this->ffi, $this->ffi->git_reference_delete($ref), \sprintf('Unable to delete reference "%s"', $fullname));
        } finally {
            $this->ffi->git_reference_free($ref);
        }
    }

    public function getIncludingBranches(string $hash): array
    {
        NulByte::assertNone($hash);
        LeadingDash::assertNone($hash);

        $tips = [];
        foreach ($this->iterateBranches() as [$fullname, $ref]) {
            // Like git, through an annotated tag, and without the branches pointing to no commit.
            $commit = $this->peeledCommitHex($ref);
            if (null !== $commit) {
                $tips[$fullname] = $commit;
            }
        }
        // Like git: the local branches, then the remote ones, sorted by name.
        ksort($tips, \SORT_STRING);

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

    public function getDiff(?string $from, string $to): DiffData
    {
        NulByte::assertNone($from ?? '', $to);
        LeadingDash::assertNone($from ?? '', $to);

        $oldTree = null === $from ? null : $this->treeForRevision($from);

        try {
            $newTree = $this->treeForRevision($to);

            try {
                return $this->diffTrees($oldTree, $newTree);
            } finally {
                $this->ffi->git_tree_free($newTree);
            }
        } finally {
            if (null !== $oldTree) {
                $this->ffi->git_tree_free($oldTree);
            }
        }
    }

    public function getWorkingTreeDiff(string $hash): DiffData
    {
        NulByte::assertNone($hash);
        LeadingDash::assertNone($hash);

        $tree = $this->treeForRevision($hash);

        try {
            $diff = $this->ffi->new('git_diff*');
            $opts = $this->diffOptions();
            LibGit2::check($this->ffi, $this->ffi->git_diff_tree_to_workdir_with_index(\FFI::addr($diff), $this->repo, $tree, \FFI::addr($opts)), 'Unable to compute working copy diff');
            $unmerged = $this->unmergedPaths($diff);
            // libgit2 keeps a file the working tree changes back to the commit, after the index changed it, or `git add -N` added it.
            $files = array_values(array_filter($this->buildDiff($diff)->files, static fn (DiffFileData $file): bool => [] !== $file->changes || $file->oldName !== $file->newName || $file->oldMode !== $file->newMode || $file->oldIndex !== $file->newIndex));

            if ([] !== $unmerged) {
                // Like git diff <commit>, which compares the unmerged files, conflict markers included, to the commit.
                $unmergedDiff = $this->ffi->new('git_diff*');
                $opts = $this->diffOptions();
                // Keeps the C strings of the pathspec alive until the diff is computed.
                $pathspec = $this->pathspec($opts, $unmerged);
                $opts->flags |= self::GIT_DIFF_DISABLE_PATHSPEC_MATCH;
                LibGit2::check($this->ffi, $this->ffi->git_diff_tree_to_workdir(\FFI::addr($unmergedDiff), $this->repo, $tree, \FFI::addr($opts)), 'Unable to compute the diff of the unmerged files');

                $files = [...$files, ...$this->buildDiff($unmergedDiff)->files];
                // Sorted by name, like git.
                usort($files, static fn (DiffFileData $a, DiffFileData $b): int => strcmp($a->name, $b->name));
            }
        } finally {
            $this->ffi->git_tree_free($tree);
        }

        return new DiffData($files, '');
    }

    public function getDiffPending(): DiffData
    {
        $diff = $this->ffi->new('git_diff*');
        $opts = $this->diffOptions();
        LibGit2::check($this->ffi, $this->ffi->git_diff_index_to_workdir(\FFI::addr($diff), $this->repo, null, \FFI::addr($opts)), 'Unable to compute working copy diff');

        // Like git since 2.19, which diffs the files `git add -N` added as
        // absent from the index: created. Like any git, one deleted since is
        // deleted, from the empty file the index has for it.
        $intentToAdd = $this->intentToAddFiles();
        $files = [];
        foreach ($this->buildDiff($diff)->files as $file) {
            if (isset($intentToAdd[$file->name]) && null !== $file->newName) {
                $files[] = new DiffFileData(null, $file->newName, null, $file->newMode, null, $file->newIndex, $file->isBinary, $file->isCopy, $file->changes);
            } else {
                $files[] = $file;
            }
            unset($intentToAdd[$file->name]);
        }

        if ([] !== $intentToAdd) {
            // Still empty, as `git add -N` staged them: libgit2 sees no change.
            foreach ($intentToAdd as $path => $mode) {
                $files[] = new DiffFileData(null, $path, null, $mode, null, self::EMPTY_BLOB, false, false, []);
            }
            // Sorted by name, like git.
            usort($files, static fn (DiffFileData $a, DiffFileData $b): int => strcmp($a->name, $b->name));
        }

        return new DiffData($files, '');
    }

    public function getDiffStaged(): DiffData
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

        // Like git since 2.19, which diffs the files `git add -N` added as absent from the index: not staged, or deleted when HEAD has them.
        $intentToAdd = $this->intentToAddFiles();
        $files = [];
        foreach ($this->buildDiff($diff)->files as $file) {
            if (!isset($intentToAdd[$file->name])) {
                $files[] = $file;
            } elseif (null !== $file->oldName) {
                $files[] = new DiffFileData($file->oldName, null, $file->oldMode, null, $file->oldIndex, null, $file->isBinary, $file->isCopy, $file->changes);
            }
        }

        return new DiffData($files, '');
    }

    public function getBlameLines(string $hash, string $path, ?int $firstLine, ?int $lastLine): array
    {
        NulByte::assertNone($hash, $path);
        LeadingDash::assertNone($hash);

        $range = null !== $firstLine || null !== $lastLine;
        $firstLine ??= 1;

        $options = $this->ffi->new('git_blame_options');
        LibGit2::check($this->ffi, $this->ffi->git_blame_options_init(\FFI::addr($options), 1), 'Unable to initialize blame options');
        $options->newest_commit = $this->hexToOid($this->resolveCommit($hash));

        $blame = $this->ffi->new('git_blame*');
        LibGit2::check($this->ffi, $this->ffi->git_blame_file(\FFI::addr($blame), $this->repo, $this->toRepoRelativePath($path), \FFI::addr($options)), \sprintf('Unable to blame "%s"', $path));

        try {
            $count = $this->ffi->git_blame_linecount($blame);
            // git_blame_linecount() counts one more line for a file not ending with a newline.
            if ($count > 0 && null === $this->ffi->git_blame_line_byindex($blame, $count)) {
                --$count;
            }
            // Like git, which stops a range at the end of the file, but refuses one starting after it.
            if ($range && $firstLine > $count) {
                throw new DriverException(\sprintf('The file "%s" has only %d lines.', $path, $count));
            }

            $result = [];
            for ($i = $firstLine; $i <= min($lastLine ?? $count, $count); ++$i) {
                $hunk = $this->ffi->git_blame_hunk_byline($blame, $i);
                $line = $this->ffi->git_blame_line_byindex($blame, $i);
                $content = rtrim(\FFI::string($line->ptr, $line->len), "\n");
                $originalNumber = $hunk->orig_start_line_number + ($i - $hunk->final_start_line_number);

                $result[] = new BlameLineData($this->oidToHex(\FFI::addr($hunk->final_commit_id)), $i, $originalNumber, $content);
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
        NulByte::assertNone($revision);
        LeadingDash::assertNone($revision);

        // `git checkout <revision>` attaches HEAD when <revision> names a
        // local branch, before a tag of the same name, or is @{-<n>}, the nth
        // branch checked out before. It leaves HEAD as it is for HEAD, or @,
        // and detaches it for anything else (a tag, a hash, a remote branch, a
        // full reference name...).
        $name = $this->expandPriorCheckout($revision);
        $branch = $this->referenceExists('refs/heads/'.$name) ? 'refs/heads/'.$name : null;
        // Resolved before changing anything.
        $hash = $this->resolveCommit($branch ?? $revision);

        $this->checkoutRevision($hash);

        if (null !== $branch) {
            LibGit2::check($this->ffi, $this->ffi->git_repository_set_head($this->repo, $branch), 'Unable to update HEAD');
        } elseif ('HEAD' !== self::expandAt($revision)) {
            $oid = $this->hexToOid($hash);
            LibGit2::check($this->ffi, $this->ffi->git_repository_set_head_detached($this->repo, \FFI::addr($oid)), 'Unable to detach HEAD');
        }
    }

    public function checkoutNewBranch(string $branch, string $startPoint): void
    {
        NulByte::assertNone($branch, $startPoint);
        LeadingDash::assertNone($branch, $startPoint);

        if ($this->referenceExists('refs/heads/'.$branch)) {
            throw new DriverException(\sprintf('A branch named "%s" already exists.', $branch));
        }

        // Validated before changing anything.
        $valid = $this->ffi->new('int');
        LibGit2::check($this->ffi, $this->ffi->git_branch_name_is_valid(\FFI::addr($valid), $branch), 'Unable to validate the branch name');
        if (0 === $valid->cdata) {
            throw new DriverException(\sprintf('"%s" is not a valid branch name.', $branch));
        }
        $startHash = $this->resolveCommit($startPoint);
        $upstream = $this->upstreamOf($startPoint);
        $commit = $this->lookupCommit($startHash);

        $reference = $this->ffi->new('git_reference*');

        try {
            LibGit2::check($this->ffi, $this->ffi->git_branch_create(\FFI::addr($reference), $this->repo, $branch, $commit, 0), \sprintf('Unable to create branch "%s"', $branch));
        } finally {
            $this->ffi->git_commit_free($commit);
        }

        // The branch and its upstream before the files, which may conflict
        // with local changes: then, like git, nothing is left behind.
        try {
            if (null !== $upstream) {
                $this->setUpstream($branch, ...$upstream);
            }
            $this->checkoutRevision($startHash);
        } catch (DriverException $e) {
            // Its configuration with it.
            LibGit2::check($this->ffi, $this->ffi->git_branch_delete($reference), \sprintf('Unable to delete the branch "%s" after a failed checkout', $branch));

            throw $e;
        } finally {
            $this->ffi->git_reference_free($reference);
        }

        LibGit2::check($this->ffi, $this->ffi->git_repository_set_head($this->repo, 'refs/heads/'.$branch), 'Unable to update HEAD');
    }

    public function merge(string $revision, FastForward $fastForward): void
    {
        NulByte::assertNone($revision);
        LeadingDash::assertNone($revision);

        $branch = $this->getHeadReference();
        $theirHash = $this->resolveCommit($revision);

        // Like git, which fast-forwards a branch yet to be born.
        if (null !== $branch && !$this->referenceExists($branch)) {
            if (FastForward::Never === $fastForward) {
                throw new DriverException(\sprintf('Unable to merge "%s" without a fast-forward: the branch "%s" has no commit yet.', $revision, $branch));
            }

            $this->fastForward($branch, $theirHash);

            return;
        }

        $headHash = $this->resolveCommit('HEAD');
        $head = $this->hexToOid($headHash);
        $their = $this->hexToOid($theirHash);

        // Already up to date, even with --no-ff.
        if ($headHash === $theirHash || $this->isDescendantOf($head, $their)) {
            return;
        }

        $canFastForward = $this->isDescendantOf($their, $head);
        if (FastForward::Only === $fastForward && !$canFastForward) {
            throw new DriverException(\sprintf('Unable to fast-forward to "%s".', $revision));
        }

        if (FastForward::Never !== $fastForward && $canFastForward) {
            $this->fastForward($branch, $theirHash);

            return;
        }

        // Like git.
        if ([] === $this->getMergeBases($headHash, $theirHash)) {
            throw new DriverException(\sprintf('Refusing to merge "%s", whose history is unrelated.', $revision));
        }
        if ([] !== $this->getDiffStaged()->files) {
            throw new DriverException(\sprintf('Unable to merge "%s": commit or stash the staged changes first.', $revision));
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
     * @param list<string>|null $includes null for every reference and HEAD
     * @param list<string>      $excludes
     * @param string[]          $paths
     *
     * @return \Generator<int, string> Commit hashes
     */
    private function walk(?array $includes, array $excludes, array $paths): \Generator
    {
        $tips = $includes ?? $this->referencedCommits();
        $bottoms = $excludes;
        if ([] === $tips) {
            return;
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
     * @return list<string> The commits HEAD and the references point to; like `git log --all`, ignores the references to other objects and the dangling ones
     */
    private function referencedCommits(): array
    {
        $hashes = [];

        // A detached HEAD may name a commit no reference has.
        $head = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_repository_head(\FFI::addr($head), $this->repo);
        if (self::GIT_EUNBORNBRANCH !== $rc && LibGit2::GIT_ENOTFOUND !== $rc) {
            LibGit2::check($this->ffi, $rc, 'Unable to resolve HEAD');

            try {
                $commit = $this->peeledCommitHex($head);
                if (null !== $commit) {
                    $hashes[] = $commit;
                }
            } finally {
                $this->ffi->git_reference_free($head);
            }
        }

        foreach ($this->iterateReferences() as $ref) {
            $commit = $this->peeledCommitHex($ref);
            if (null !== $commit) {
                $hashes[] = $commit;
            }
        }

        return $hashes;
    }

    /**
     * @param string[] $paths
     *
     * @throws UnsupportedOperationException A path uses the magic of git's pathspecs
     */
    private function pathFilter(array $paths): ?PathFilter
    {
        if ([] === $paths) {
            return null;
        }

        foreach ($paths as $path) {
            // git reads magic in a path starting with ":", like ":(exclude)src" or ":/src": libgit2 would match it as a name.
            if (str_starts_with($path, ':')) {
                throw new UnsupportedOperationException(\sprintf('The path "%s" uses the magic of git\'s pathspecs, which LibGit2Driver doesn\'t support; use CliDriver.', $path));
            }
        }

        return new PathFilter($this->ffi, $this->repo, array_values(array_map($this->toRepoRelativePath(...), $paths)), $this->diffOptions());
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
            /** @var string $hash SplPriorityQueue extracts the data alone by default. */
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
     * git normalizes paths, and also accepts the absolute paths of files in
     * the working tree, where libgit2 only deals with normalized paths
     * relative to the repository.
     *
     * @return string $path relative to the root of the repository, which "" names, without "." nor ".." components, nor extra slashes
     *
     * @throws DriverException $path is outside the repository
     */
    private function toRepoRelativePath(string $path): string
    {
        $components = self::pathComponents($path);

        if (str_starts_with($path, '/')) {
            $root = null === $this->workingDir ? null : self::pathComponents($this->workingDir);
            if (null === $root || \array_slice($components, 0, \count($root)) !== $root) {
                // Like git.
                throw new DriverException(\sprintf('The path "%s" is outside the repository.', $path));
            }

            $components = \array_slice($components, \count($root));
        }

        return implode('/', $components);
    }

    /**
     * @return list<string> The components of $path, once "." and ".." are resolved, like git does
     *
     * @throws DriverException ".." goes above the first component of $path
     */
    private static function pathComponents(string $path): array
    {
        $components = [];
        foreach (explode('/', $path) as $component) {
            if ('..' === $component && [] === $components) {
                // Like git.
                throw new DriverException(\sprintf('The path "%s" is outside the repository.', $path));
            }

            if ('..' === $component) {
                array_pop($components);
            } elseif ('' !== $component && '.' !== $component) {
                $components[] = $component;
            }
        }

        return $components;
    }

    // -- diff ----------------------------------------------------------------

    private function diffTrees(?CData $oldTree, CData $newTree): DiffData
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
        // git's default since 2.14.
        $opts->flags |= self::GIT_DIFF_INDENT_HEURISTIC;

        return $opts;
    }

    /**
     * @return list<string> The paths a merge conflict left unmerged, which buildDiff() leaves out
     */
    private function unmergedPaths(CData $diff): array
    {
        $paths = [];
        for ($i = 0, $count = $this->ffi->git_diff_num_deltas($diff); $i < $count; ++$i) {
            $delta = $this->ffi->git_diff_get_delta($diff, $i);
            if (self::GIT_DELTA_CONFLICTED === $delta->status) {
                $paths[] = $delta->new_file->path;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, string> The mode of the files `git add -N` added to the index, by path
     */
    private function intentToAddFiles(): array
    {
        $index = $this->ffi->new('git_index*');
        LibGit2::check($this->ffi, $this->ffi->git_repository_index(\FFI::addr($index), $this->repo), 'Unable to open the index');

        try {
            LibGit2::check($this->ffi, $this->ffi->git_index_read($index, 0), 'Unable to read the index');
            // git writes an index of version 2, without the extended flags, unless an entry has one.
            if ($this->ffi->git_index_version($index) < 3) {
                return [];
            }

            $files = [];
            for ($i = 0, $count = $this->ffi->git_index_entrycount($index); $i < $count; ++$i) {
                $entry = $this->ffi->git_index_get_byindex($index, $i);
                if (0 !== ($entry->flags_extended & self::GIT_INDEX_ENTRY_INTENT_TO_ADD)) {
                    $files[$entry->path] = \sprintf('%06o', $entry->mode);
                }
            }

            return $files;
        } finally {
            $this->ffi->git_index_free($index);
        }
    }

    /**
     * Restricts a diff to $paths, matched as they are.
     *
     * @param list<string> $paths
     *
     * @return list<CData> The C strings of the pathspec, to keep alive as long as $opts
     */
    private function pathspec(CData $opts, array $paths): array
    {
        $buffers = [];
        $strings = $this->ffi->new('char*['.\count($paths).']');
        foreach ($paths as $i => $path) {
            $buffers[] = $buffer = LibGit2::cString($this->ffi, $path);
            $strings[$i] = $this->ffi->cast('char *', $buffer);
        }
        $buffers[] = $strings;

        $opts->pathspec->strings = $this->ffi->cast('char **', $strings);
        $opts->pathspec->count = \count($paths);

        return $buffers;
    }

    /**
     * Converts a git_diff, and frees it.
     */
    private function buildDiff(CData $diff): DiffData
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
                // Like git, leave the unmerged paths of a conflict out.
                if (self::GIT_DELTA_CONFLICTED === $this->ffi->git_diff_get_delta($diff, $i)->status) {
                    continue;
                }

                $file = $this->patchToFile($diff, $i);

                // libgit2 splits a type change into a creation, then a deletion: git into a deletion, then a creation.
                $previous = [] === $files ? null : $files[\count($files) - 1];
                if (null !== $previous && null === $file->newName && null === $previous->oldName && $previous->newName === $file->oldName) {
                    array_pop($files);
                    $files[] = $file;
                    $files[] = $previous;

                    continue;
                }

                $files[] = $file;
            }

            return new DiffData($files, '');
        } finally {
            $this->ffi->git_diff_free($diff);
        }
    }

    private function patchToFile(CData $diff, int $index): DiffFileData
    {
        $patch = $this->ffi->new('git_patch*');
        LibGit2::check($this->ffi, $this->ffi->git_patch_from_diff(\FFI::addr($patch), $diff, $index), 'Unable to compute patch');

        try {
            $changes = [];
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
                        '+' => DiffLineType::Addition,
                        '-' => DiffLineType::Deletion,
                        ' ' => DiffLineType::Context,
                        default => null,
                    };

                    if (null !== $type) {
                        $lines[] = new DiffLine($type, rtrim(\FFI::string($line->content, $line->content_len), "\n"));
                    }
                }

                $changes[] = new FileChange($hunk->old_start, $hunk->old_lines, $hunk->new_start, $hunk->new_lines, $lines);
            }

            return $this->deltaToFile($this->ffi->git_patch_get_delta($patch), $changes);
        } finally {
            $this->ffi->git_patch_free($patch);
        }
    }

    /**
     * @param list<FileChange> $changes
     */
    private function deltaToFile(CData $delta, array $changes): DiffFileData
    {
        $status = $delta->status;
        $isCopy = self::GIT_DELTA_COPIED === $status;
        $isAdded = self::GIT_DELTA_ADDED === $status;
        $isDeleted = self::GIT_DELTA_DELETED === $status;
        $isBinary = 0 !== ($delta->flags & self::GIT_DIFF_FLAG_BINARY);

        $oldName = $isAdded ? null : $delta->old_file->path;
        $newName = $isDeleted ? null : $delta->new_file->path;

        $oldMode = $isAdded ? null : \sprintf('%06o', $delta->old_file->mode);
        $newMode = $isDeleted ? null : \sprintf('%06o', $delta->new_file->mode);

        $oldIndex = $this->ffi->git_oid_is_zero(\FFI::addr($delta->old_file->id)) ? null : $this->oidToHex(\FFI::addr($delta->old_file->id));
        $newIndex = $this->ffi->git_oid_is_zero(\FFI::addr($delta->new_file->id)) ? null : $this->oidToHex(\FFI::addr($delta->new_file->id));

        return new DiffFileData($oldName, $newName, $oldMode, $newMode, $oldIndex, $newIndex, $isBinary, $isCopy, $changes);
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
     * Checks $hash out, and points the checked out branch, or a detached HEAD, to it.
     */
    private function fastForward(?string $branch, string $hash): void
    {
        $target = $this->revparse($hash);

        try {
            $this->checkoutObject($target, 'Unable to fast-forward');
        } finally {
            $this->ffi->git_object_free($target);
        }
        $this->moveHead($branch, $hash);
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
        // Like git, GIT_AUTHOR_* and GIT_COMMITTER_* before the configuration.
        $author = $this->ffi->new('git_signature*');
        $committer = $this->ffi->new('git_signature*');
        LibGit2::check($this->ffi, $this->ffi->git_signature_default_from_env(\FFI::addr($author), \FFI::addr($committer), $this->repo), 'Unable to determine the merge commit author (configure user.name and user.email)');

        try {
            $ours = $this->lookupCommit($oursHash);

            try {
                $theirs = $this->lookupCommit($theirsHash);

                try {
                    $parents = $this->ffi->new('git_commit*[2]');
                    $parents[0] = $ours;
                    $parents[1] = $theirs;

                    $oid = $this->ffi->new('git_oid');
                    LibGit2::check($this->ffi, $this->ffi->git_commit_create(\FFI::addr($oid), $this->repo, null, $author, $committer, null, $message, $tree, 2, $parents), 'Unable to create merge commit');

                    return $this->oidToHex(\FFI::addr($oid));
                } finally {
                    $this->ffi->git_commit_free($theirs);
                }
            } finally {
                $this->ffi->git_commit_free($ours);
            }
        } finally {
            $this->ffi->git_signature_free($author);
            $this->ffi->git_signature_free($committer);
        }
    }

    /**
     * The message `git merge` writes (see git's merge_name() and fmt-merge-msg).
     */
    private function mergeMessage(string $revision, ?string $branch): string
    {
        $object = $this->revparse($revision);

        try {
            $tag = $this->ffi->git_object_type($object) === $this->ffi->GIT_OBJECT_TAG ? $this->oidToHex($this->ffi->git_object_id($object)) : null;
        } finally {
            $this->ffi->git_object_free($object);
        }

        $message = 'Merge '.$this->mergedName($this->expandPriorCheckout($revision), $tag);

        // git leaves out the destination when merging into main or master.
        if (null === $branch) {
            $message .= ' into HEAD';
        } elseif (!\in_array($branch, ['refs/heads/main', 'refs/heads/master'], true)) {
            $message .= ' into '.substr($branch, \strlen('refs/heads/'));
        }

        // Then the message of the annotated tag, without its signature.
        if (null !== $tag) {
            $message .= "\n\n".$this->getTagData($tag)->message;
        }

        return $this->prettifyMessage($message);
    }

    /**
     * @param string      $name The revision merged, @{-<n>} expanded
     * @param string|null $tag  The annotated tag $name names, if any
     *
     * @return string How git's merge_name() names $name: as given, after what it names
     */
    private function mergedName(string $name, ?string $tag): string
    {
        $reference = $this->dwimReference($name) ?? '';
        foreach (['refs/heads/' => 'branch', 'refs/tags/' => 'tag', 'refs/remotes/' => 'remote-tracking branch'] as $prefix => $kind) {
            if (str_starts_with($reference, $prefix)) {
                return \sprintf("%s '%s'", $kind, $name);
            }
        }

        // <branch>~<n> or <branch>^: an early part of the branch, unless <n> is 0.
        if (1 === preg_match('/^(.+?)(\^+|~\d*)$/', $name, $match) && $this->referenceExists('refs/heads/'.$match[1])) {
            return \sprintf("branch '%s'%s", $match[1], 1 === preg_match('/^~0+$/', $match[2]) ? '' : ' (early part)');
        }

        return null === $tag ? \sprintf("commit '%s'", $name) : \sprintf("tag '%s'", $name);
    }

    /**
     * Cleans a message up like git does without an editor: without
     * whitespace at the end of its lines, nor blank lines at its start, at
     * its end or in a row.
     */
    private function prettifyMessage(string $message): string
    {
        $buffer = $this->ffi->new('git_buf');
        LibGit2::check($this->ffi, $this->ffi->git_message_prettify(\FFI::addr($buffer), $message, 0, '#'), 'Unable to clean the message up');

        try {
            return \FFI::string($buffer->ptr, $buffer->size);
        } finally {
            $this->ffi->git_buf_dispose(\FFI::addr($buffer));
        }
    }

    // -- references ----------------------------------------------------------

    /**
     * @return string|null The full name of the reference $name names, like git's dwim_ref(), null when it names none
     */
    private function dwimReference(string $name): ?string
    {
        $ref = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_reference_dwim(\FFI::addr($ref), $this->repo, $name);
        if (LibGit2::GIT_ENOTFOUND === $rc || self::GIT_EINVALIDSPEC === $rc) {
            return null;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to look up the reference "%s"', $name));

        try {
            return $this->ffi->git_reference_name($ref);
        } finally {
            $this->ffi->git_reference_free($ref);
        }
    }

    /**
     * Like git's dwim_ref(), which counts the references a name may be the
     * short name of, unlike git_reference_dwim().
     *
     * @return list<string> The full names of the references $name names, after the symbolic ones: several when $name is ambiguous
     */
    private function dwimReferences(string $name): array
    {
        $references = [];
        // The rules of git's ref_rev_parse_rules.
        foreach (['%s', 'refs/%s', 'refs/tags/%s', 'refs/heads/%s', 'refs/remotes/%s', 'refs/remotes/%s/HEAD'] as $rule) {
            $fullname = \sprintf($rule, $name);
            $ref = $this->ffi->new('git_reference*');
            $rc = $this->ffi->git_reference_lookup(\FFI::addr($ref), $this->repo, $fullname);
            if (LibGit2::GIT_ENOTFOUND === $rc || self::GIT_EINVALIDSPEC === $rc) {
                continue;
            }
            LibGit2::check($this->ffi, $rc, \sprintf('Unable to look up the reference "%s"', $fullname));

            try {
                $resolved = $this->ffi->new('git_reference*');
                $rc = $this->ffi->git_reference_resolve(\FFI::addr($resolved), $ref);
                // Like git, which ignores a symbolic reference to nothing.
                if (LibGit2::GIT_ENOTFOUND === $rc) {
                    continue;
                }
                LibGit2::check($this->ffi, $rc, \sprintf('Unable to resolve the reference "%s"', $fullname));

                try {
                    $references[] = $this->ffi->git_reference_name($resolved);
                } finally {
                    $this->ffi->git_reference_free($resolved);
                }
            } finally {
                $this->ffi->git_reference_free($ref);
            }
        }

        return $references;
    }

    /**
     * Like git, reads "@" as HEAD, alone or at the start of $revision before
     * "~", "^" or ":": libgit2 only reads it alone. "@{...}" is left as it is.
     */
    private static function expandAt(string $revision): string
    {
        if ('@' === $revision || (str_starts_with($revision, '@') && \in_array($revision[1], ['~', '^', ':'], true))) {
            return 'HEAD'.substr($revision, 1);
        }

        return $revision;
    }

    /**
     * Like git, replaces @{-<n>} at the start of $revision with the nth
     * branch checked out before, or commit when HEAD was detached.
     */
    private function expandPriorCheckout(string $revision): string
    {
        if (1 !== preg_match('/^@\{-([1-9]\d*)\}/', $revision, $match)) {
            return $revision;
        }

        $prior = $this->priorCheckout((int) $match[1]);

        return null === $prior ? $revision : $prior.substr($revision, \strlen($match[0]));
    }

    /**
     * @return string|null The nth branch checked out before, or commit when HEAD was detached, read from the reflog of HEAD like git does; null after fewer checkouts
     */
    private function priorCheckout(int $n): ?string
    {
        $reflog = $this->ffi->new('git_reflog*');
        LibGit2::check($this->ffi, $this->ffi->git_reflog_read(\FFI::addr($reflog), $this->repo, 'HEAD'), 'Unable to read the reflog of HEAD');

        try {
            // The newest entry first.
            for ($i = 0, $count = $this->ffi->git_reflog_entrycount($reflog); $i < $count; ++$i) {
                $message = $this->ffi->git_reflog_entry_message($this->ffi->git_reflog_entry_byindex($reflog, $i));
                if (null !== $message && 1 === preg_match('/^checkout: moving from (.+?) to /', $message, $match) && 0 === --$n) {
                    return $match[1];
                }
            }

            return null;
        } finally {
            $this->ffi->git_reflog_free($reflog);
        }
    }

    /**
     * Like git with branch.autoSetupMerge=true, its default, a branch created
     * from a remote branch tracks it.
     *
     * @return array{string, string}|null The remote, and its branch, that $startPoint names, like ["origin", "refs/heads/feature"] for "origin/feature"; null when $startPoint names another revision, or a branch no remote fetches
     *
     * @throws DriverException $startPoint names several references, or a branch several remotes fetch
     */
    private function upstreamOf(string $startPoint): ?array
    {
        $references = $this->dwimReferences($startPoint);
        // Like git, which refuses to create a branch from an ambiguous name.
        if (\count($references) > 1) {
            throw new DriverException(\sprintf('The name "%s" is ambiguous: "%s".', $startPoint, implode('", "', $references)));
        }
        $reference = $references[0] ?? null;
        if (null === $reference || !str_starts_with($reference, 'refs/remotes/')) {
            return null;
        }

        // Like git, which only tracks the branch of a remote it knows.
        $remotes = $this->ffi->new('git_strarray');
        LibGit2::check($this->ffi, $this->ffi->git_remote_list(\FFI::addr($remotes), $this->repo), 'Unable to list the remotes');

        try {
            $upstream = null;
            for ($i = 0; $i < $remotes->count; ++$i) {
                $remote = \FFI::string($remotes->strings[$i]);
                $branch = $this->fetchedBranch($remote, $reference);
                if (null === $branch) {
                    continue;
                }
                if (null !== $upstream) {
                    throw new DriverException(\sprintf('Unable to tell the upstream of "%s": the remotes "%s" and "%s" fetch it.', $startPoint, $upstream[0], $remote));
                }
                $upstream = [$remote, $branch];
            }

            return $upstream;
        } finally {
            $this->ffi->git_strarray_dispose(\FFI::addr($remotes));
        }
    }

    /**
     * @return string|null The branch of $remote a fetch writes to $reference, like "refs/heads/feature" for "refs/remotes/origin/feature", null when no fetch of $remote writes it
     */
    private function fetchedBranch(string $remote, string $reference): ?string
    {
        $handle = $this->ffi->new('git_remote*');
        LibGit2::check($this->ffi, $this->ffi->git_remote_lookup(\FFI::addr($handle), $this->repo, $remote), \sprintf('Unable to look up the remote "%s"', $remote));

        try {
            for ($i = 0, $count = $this->ffi->git_remote_refspec_count($handle); $i < $count; ++$i) {
                $refspec = $this->ffi->git_remote_get_refspec($handle, $i);
                if (self::GIT_DIRECTION_FETCH !== $this->ffi->git_refspec_direction($refspec) || 0 === $this->ffi->git_refspec_dst_matches($refspec, $reference)) {
                    continue;
                }

                $branch = $this->ffi->new('git_buf');
                LibGit2::check($this->ffi, $this->ffi->git_refspec_rtransform(\FFI::addr($branch), $refspec, $reference), \sprintf('Unable to find the branch of the remote "%s" fetched to "%s"', $remote, $reference));

                try {
                    return \FFI::string($branch->ptr, $branch->size);
                } finally {
                    $this->ffi->git_buf_dispose(\FFI::addr($branch));
                }
            }

            return null;
        } finally {
            $this->ffi->git_remote_free($handle);
        }
    }

    /**
     * Writes the configuration git_branch_set_upstream() would, which looks
     * for a local branch of the name of the remote one first.
     *
     * @param string $merge The branch on the remote, like "refs/heads/feature"
     */
    private function setUpstream(string $branch, string $remote, string $merge): void
    {
        $config = $this->ffi->new('git_config*');
        LibGit2::check($this->ffi, $this->ffi->git_repository_config(\FFI::addr($config), $this->repo), 'Unable to open the repository configuration');

        try {
            foreach (['remote' => $remote, 'merge' => $merge] as $key => $value) {
                LibGit2::check($this->ffi, $this->ffi->git_config_set_string($config, 'branch.'.$branch.'.'.$key, $value), \sprintf('Unable to set the upstream of "%s"', $branch));
            }
        } finally {
            $this->ffi->git_config_free($config);
        }
    }

    private function referenceExists(string $fullname): bool
    {
        $ref = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_reference_lookup(\FFI::addr($ref), $this->repo, $fullname);
        if (LibGit2::GIT_ENOTFOUND === $rc || self::GIT_EINVALIDSPEC === $rc) {
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
        $this->assertGitDirExists();

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
        $this->assertGitDirExists();

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

    /**
     * libgit2 lists no reference, instead of failing, once the repository is removed.
     *
     * @throws DriverException
     */
    private function assertGitDirExists(): void
    {
        if (!is_dir($this->gitDir)) {
            throw new DriverException(\sprintf('The repository "%s" does not exist anymore.', $this->gitDir));
        }
    }

    /**
     * @return string|null The object $ref points to, null for a dangling symbolic reference
     */
    private function referenceTargetHex(CData $ref): ?string
    {
        if ($this->ffi->git_reference_type($ref) !== $this->ffi->GIT_REFERENCE_SYMBOLIC) {
            return $this->oidToHex($this->ffi->git_reference_target($ref));
        }

        $resolved = $this->ffi->new('git_reference*');
        $rc = $this->ffi->git_reference_resolve(\FFI::addr($resolved), $ref);
        // Like git show-ref, which skips it.
        if (LibGit2::GIT_ENOTFOUND === $rc) {
            return null;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to resolve "%s"', $this->ffi->git_reference_name($ref)));

        try {
            return $this->oidToHex($this->ffi->git_reference_target($resolved));
        } finally {
            $this->ffi->git_reference_free($resolved);
        }
    }

    /**
     * @return string The object $ref points to, through annotated tags
     */
    private function peeledHex(CData $ref): string
    {
        $object = $this->ffi->new('git_object*');
        LibGit2::check($this->ffi, $this->ffi->git_reference_peel(\FFI::addr($object), $ref, $this->ffi->GIT_OBJECT_ANY), \sprintf('Unable to peel "%s"', $this->ffi->git_reference_name($ref)));

        try {
            return $this->oidToHex($this->ffi->git_object_id($object));
        } finally {
            $this->ffi->git_object_free($object);
        }
    }

    /**
     * @return string|null The commit $ref points to, through annotated tags, or null when it points to another object or to nothing
     */
    private function peeledCommitHex(CData $ref): ?string
    {
        $commit = $this->ffi->new('git_object*');
        $rc = $this->ffi->git_reference_peel(\FFI::addr($commit), $ref, $this->ffi->GIT_OBJECT_COMMIT);
        if (\in_array($rc, [LibGit2::GIT_ENOTFOUND, self::GIT_EINVALIDSPEC, self::GIT_EPEEL], true)) {
            return null;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to peel "%s"', $this->ffi->git_reference_name($ref)));

        try {
            return $this->oidToHex($this->ffi->git_object_id($commit));
        } finally {
            $this->ffi->git_object_free($commit);
        }
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
        LibGit2::check($this->ffi, $this->ffi->git_revparse_single(\FFI::addr($out), $this->repo, self::expandAt($spec)), \sprintf('Unable to resolve "%s"', $spec));

        return $out;
    }

    /**
     * Resolves a revision to a commit hash, peeling annotated tags: the
     * object it names, as "^{commit}" would be part of the text of ":/text".
     */
    private function resolveCommit(string $revision): string
    {
        $object = $this->revparse($revision);

        try {
            $commit = $this->ffi->new('git_object*');
            LibGit2::check($this->ffi, $this->ffi->git_object_peel(\FFI::addr($commit), $object, $this->ffi->GIT_OBJECT_COMMIT), \sprintf('Unable to resolve "%s" to a commit', $revision));
        } finally {
            $this->ffi->git_object_free($object);
        }

        try {
            return $this->oidToHex($this->ffi->git_object_id($commit));
        } finally {
            $this->ffi->git_object_free($commit);
        }
    }

    private function lookupCommit(string $hash): CData
    {
        $oid = $this->hexToOid($hash);
        $out = $this->ffi->new('git_commit*');
        $this->checkFound($this->ffi->git_commit_lookup_prefix(\FFI::addr($out), $this->repo, \FFI::addr($oid), \strlen($hash)), $hash, ObjectType::Commit, 'Unable to read the commit');

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
        $this->checkFound($this->ffi->git_tree_lookup_prefix(\FFI::addr($out), $this->repo, \FFI::addr($oid), \strlen($hash)), $hash, ObjectType::Tree, 'Unable to read the tree');

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
        if (self::GIT_EUNBORNBRANCH === $rc || LibGit2::GIT_ENOTFOUND === $rc) {
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
            $this->oidToHex($this->ffi->git_commit_id($commit)),
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
        // @phpstan-ignore argument.type (Without the "u" modifier, preg_replace() fails on no subject.)
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

    /**
     * @return \DateTimeImmutable The date at the UTC offset of the signature, like CliDriver's parsers
     */
    private function signatureDate(CData $signature): \DateTimeImmutable
    {
        // In minutes. libgit2 gives 0 for an offset of more than 14 hours or
        // 59 minutes, which no timezone has, in a corrupt object.
        $offset = $signature->when->offset;
        // @phpstan-ignore argument.type (libgit2.h declares the offset an int, which PHPStan can't see.)
        $timezone = new \DateTimeZone(\sprintf('%s%02d%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 60), abs($offset) % 60));

        return new \DateTimeImmutable('@'.$signature->when->time)->setTimezone($timezone);
    }

    private function objectType(int $type): ObjectType
    {
        return match ($type) {
            $this->ffi->GIT_OBJECT_BLOB => ObjectType::Blob,
            $this->ffi->GIT_OBJECT_COMMIT => ObjectType::Commit,
            $this->ffi->GIT_OBJECT_TAG => ObjectType::Tag,
            $this->ffi->GIT_OBJECT_TREE => ObjectType::Tree,
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
     * @param string $hash A hash, abbreviated or not
     *
     * @throws ReferenceNotFoundException The object $hash doesn't exist
     */
    private function lookupObject(string $hash): CData
    {
        $oid = $this->hexToOid($hash);
        $object = $this->ffi->new('git_object*');
        $this->checkFound($this->ffi->git_object_lookup_prefix(\FFI::addr($object), $this->repo, \FFI::addr($oid), \strlen($hash), $this->ffi->GIT_OBJECT_ANY), $hash, null, 'Unable to read the object');

        return $object;
    }

    /**
     * @param ObjectType|null $type The type the lookup asked for, null for any
     *
     * @throws ReferenceNotFoundException The object $hash doesn't exist, or isn't of the type $type
     */
    private function checkFound(int $rc, string $hash, ?ObjectType $type, string $context): void
    {
        if (LibGit2::GIT_ENOTFOUND === $rc) {
            // libgit2 reports an object of another type as not found.
            if (null !== $type && $this->objectExists($hash)) {
                throw new ReferenceNotFoundException(\sprintf('The object "%s" is not a %s.', $hash, $type->value));
            }

            throw new ReferenceNotFoundException(\sprintf('The object "%s" does not exist.', $hash));
        }
        LibGit2::check($this->ffi, $rc, \sprintf('%s "%s"', $context, $hash));
    }

    private function objectExists(string $hash): bool
    {
        $oid = $this->hexToOid($hash);
        $object = $this->ffi->new('git_object*');
        $rc = $this->ffi->git_object_lookup_prefix(\FFI::addr($object), $this->repo, \FFI::addr($oid), \strlen($hash), $this->ffi->GIT_OBJECT_ANY);
        if (LibGit2::GIT_ENOTFOUND === $rc) {
            return false;
        }
        LibGit2::check($this->ffi, $rc, \sprintf('Unable to read the object "%s"', $hash));
        $this->ffi->git_object_free($object);

        return true;
    }

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
