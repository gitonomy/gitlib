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

namespace Gitonomy\Git\Driver;

use Gitonomy\Git\Driver\Data\BlameLineData;
use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Driver\Data\DiffData;
use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Driver\Data\TreeEntryData;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\FastForward;

/**
 * Operations on one repository, opened by {@see DriverInterface::open()}.
 *
 * Implementations know nothing of Repository: they read and write git data,
 * and the domain objects (Commit, Tree, Reference...) build on it.
 *
 * resolveRevision(), and the methods reading an object by its hash, like
 * getCommitData(), throw a {@see ReferenceNotFoundException} when it doesn't
 * exist. Every method throws a {@see DriverException} when git reports
 * another failure (a conflict, a lock...), an
 * {@see UnsupportedOperationException} when the driver can't perform the
 * operation at all, and an {@see InvalidArgumentException} when an argument
 * contains a NUL byte, which git doesn't accept.
 *
 * git reads an argument starting with a dash as an option: the revision
 * "--output=/path" of a log writes a file. Every method refuses, with an
 * {@see InvalidArgumentException}, a revision, a reference or a hash starting
 * with a dash, before running git. A path may start with one.
 *
 * gitlib may add methods to this interface in a minor version: the promise of
 * backward compatibility covers the code calling it, not the drivers
 * implementing it outside gitlib, like a stub in tests.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
interface RepositoryDriverInterface
{
    /**
     * @return string The absolute path of the git directory
     */
    public function getGitDir(): string;

    /**
     * @return string|null The working tree, null for a bare repository
     */
    public function getWorkingDir(): ?string;

    /**
     * @return string|null The full name of the reference HEAD points to (a branch, which may have no commit yet), or null when HEAD is detached
     */
    public function getHeadReference(): ?string;

    /**
     * Resolves a revision to the hash of the object it names (``rev-parse --verify``).
     *
     * @throws ReferenceNotFoundException Nothing has this name
     */
    public function resolveRevision(string $revision): string;

    /**
     * @throws ReferenceNotFoundException $hash doesn't exist
     */
    public function getShortHash(string $hash): string;

    /**
     * @throws ReferenceNotFoundException $hash doesn't exist, or isn't a commit
     */
    public function getCommitData(string $hash): CommitData;

    /**
     * @param list<string>|null $includes The commits whose history to list, null for every reference and HEAD
     * @param list<string>      $excludes The commits whose history to leave out
     * @param string[]          $paths    Paths, relative to the root of the repository or absolute in the working tree, with git's wildcards
     *
     * @return list<CommitData> Newest first
     *
     * @throws DriverException An absolute path is outside the working tree
     */
    public function getCommitLog(?array $includes, array $excludes, array $paths, int $offset, ?int $limit): array;

    /**
     * @param list<string>|null $includes Same as {@see getCommitLog()}
     * @param list<string>      $excludes
     * @param string[]          $paths
     */
    public function countCommits(?array $includes, array $excludes, array $paths): int;

    /**
     * @return list<string> The best common ancestors of both commits, none when they have no common history
     */
    public function getMergeBases(string $hash1, string $hash2): array;

    /**
     * @return TreeEntryData[]
     *
     * @throws ReferenceNotFoundException $hash doesn't exist, or isn't a tree
     */
    public function getTreeEntries(string $hash): array;

    /**
     * @throws ReferenceNotFoundException $hash doesn't exist, or isn't a blob
     */
    public function getBlobContent(string $hash): string;

    /**
     * @param string $hash An annotated tag object
     *
     * @throws ReferenceNotFoundException $hash doesn't exist, or isn't an annotated tag
     */
    public function getTagData(string $hash): TagData;

    /**
     * @return ReferenceData[] Every reference under ``refs/``, none for an empty repository
     */
    public function getReferences(): array;

    /**
     * @param string $fullname The full name of a reference under ``refs/``, like those getReferences() lists
     *
     * @throws DriverException          $fullname exists already
     * @throws InvalidArgumentException $fullname isn't under ``refs/``, like "HEAD" or "master"
     */
    public function createReference(string $fullname, string $hash): void;

    /**
     * A symbolic reference is written itself, never the reference it points to.
     *
     * @param string $fullname The full name of a reference under ``refs/``
     *
     * @throws InvalidArgumentException $fullname isn't under ``refs/``, like "HEAD" or "master"
     */
    public function updateReference(string $fullname, string $hash): void;

    /**
     * Like ``git update-ref -d``, deleting a missing reference does nothing.
     * A symbolic reference is deleted itself, never the reference it points to.
     *
     * @param string $fullname The full name of a reference under ``refs/``
     *
     * @throws InvalidArgumentException $fullname isn't under ``refs/``, like "HEAD" or "master"
     */
    public function deleteReference(string $fullname): void;

    /**
     * @return string[] Full names of the local and remote branches whose history contains $hash, symbolic ones excluded
     */
    public function getIncludingBranches(string $hash): array;

    /**
     * Changes from the tree of $from to the one of $to.
     *
     * @param string|null $from A commit, null for the empty tree
     * @param string      $to   A commit
     */
    public function getDiff(?string $from, string $to): DiffData;

    /**
     * Changes of the working tree, staged or not, since a commit.
     *
     * During a merge conflict, like ``git diff <commit>``, the unmerged
     * files compare their content, conflict markers included, to the commit.
     */
    public function getWorkingTreeDiff(string $hash): DiffData;

    /**
     * Changes of the working tree not staged yet, the unmerged files of a conflict left out.
     */
    public function getDiffPending(): DiffData;

    /**
     * Changes staged in the index, the unmerged files of a conflict left out.
     */
    public function getDiffStaged(): DiffData;

    /**
     * @param int|null $firstLine The first line to blame, from 1, the first one of the file when null
     * @param int|null $lastLine  The last line to blame, the last one of the file when null
     *
     * @return BlameLineData[]
     */
    public function getBlameLines(string $hash, string $path, ?int $firstLine, ?int $lastLine): array;

    /**
     * @return string[] Paths of the untracked files, relative to the root of the working tree
     */
    public function getUntrackedFiles(): array;

    /**
     * Checks a revision out, like ``git checkout``: HEAD points to the branch
     * when $revision is the name of a local branch, or @{-<n>}, the nth
     * branch checked out before, stays as it is for "HEAD", and points to
     * the commit otherwise (a tag, a hash, a remote branch...).
     *
     * $revision names a commit, which WorkingCopy::checkout() checks before:
     * it throws a {@see ReferenceNotFoundException} for a name that no
     * revision has, where ``git checkout`` would create the local branch of a
     * remote one, like "feature" for "origin/feature". $revision is never a
     * path either, whose changes ``git checkout`` would discard.
     */
    public function checkout(string $revision): void;

    /**
     * Creates the branch $branch at $startPoint and checks it out, like
     * ``git checkout -b``: the branch tracks $startPoint when it is a remote
     * branch, like "origin/feature", as with branch.autoSetupMerge=true,
     * git's default, whatever the configuration. A failure leaves the
     * branches and the files as they were: like ``git checkout -b``, no
     * branch is created when the checkout fails.
     *
     * @param string $startPoint A revision naming a commit, which WorkingCopy::checkoutNewBranch() checks before
     *
     * @throws DriverException $branch exists already or isn't a valid name, $startPoint is the short name of several references, which git refuses, or the checkout would overwrite local changes
     */
    public function checkoutNewBranch(string $branch, string $startPoint): void;

    /**
     * Merges $revision, a name the message of the merge commit names, into the current branch.
     *
     * @param string $revision A revision naming a commit, which WorkingCopy::merge() checks before
     */
    public function merge(string $revision, FastForward $fastForward): void;
}
