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

use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Driver\Data\BlameLineData;
use Gitonomy\Git\Driver\Data\CommitData;
use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Driver\Data\TagData;
use Gitonomy\Git\Driver\Data\TreeEntryData;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\UnsupportedOperationException;

/**
 * Operations on one repository, opened by {@see DriverInterface::open()}.
 *
 * Implementations know nothing of Repository: they read and write git data,
 * and the domain objects (Commit, Tree, Reference...) build on it.
 *
 * Every method throws a {@see DriverException} when git reports a failure (an
 * unknown revision, a conflict...), and an {@see UnsupportedOperationException}
 * when the driver can't perform the operation at all.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
interface RepositoryDriverInterface
{
    /**
     * Resolves a revision to the hash of the object it names (``rev-parse --verify``).
     */
    public function resolveRevision(string $revision): string;

    /**
     * @return string The type of the object $revision names: ``commit``, ``tree``, ``blob`` or ``tag``
     */
    public function getObjectType(string $revision): string;

    public function getShortHash(string $revision): string;

    public function getCommitData(string $hash): CommitData;

    /**
     * @param string[]|null $revisions Revisions or ranges (``master``, ``a..b``, ``a...b``, ``^a``), or null for every reference
     * @param string[]      $paths     Paths, relative to the root of the repository
     *
     * @return array<string, CommitData> Indexed by hash, newest first
     */
    public function getCommitLog(?array $revisions, array $paths, ?int $offset, ?int $limit): array;

    /**
     * @param string[]|null $revisions Same as {@see getCommitLog()}
     * @param string[]      $paths
     */
    public function countCommits(?array $revisions, array $paths): int;

    /**
     * @return string|null The best common ancestor, or null if the revisions have none
     */
    public function getMergeBase(string $revision1, string $revision2): ?string;

    /**
     * @return TreeEntryData[]
     */
    public function getTreeEntries(string $hash): array;

    public function getBlobContent(string $hash): string;

    public function getTagData(string $revision): TagData;

    /**
     * @return ReferenceData[] Every reference under ``refs/``, none for an empty repository
     */
    public function getReferences(): array;

    public function updateReference(string $fullname, string $hash): void;

    public function deleteReference(string $fullname): void;

    /**
     * @return string[] Full names of the local and remote branches whose history contains $hash, symbolic ones excluded
     */
    public function getIncludingBranches(string $hash): array;

    /**
     * @param string[] $revisions One revision (compared with the working tree), two revisions, or one range
     */
    public function getDiff(array $revisions): Diff;

    /**
     * Changes introduced by a commit: against each of its parents, or against
     * nothing for a root commit.
     */
    public function getCommitDiff(string $hash): Diff;

    /**
     * Changes of the working tree not staged yet.
     */
    public function getDiffPending(): Diff;

    /**
     * Changes staged in the index.
     */
    public function getDiffStaged(): Diff;

    /**
     * @param string|null $lineRange ``<start>,<end>`` or ``<start>,+<count>``; CliDriver accepts anything ``git blame -L`` does
     *
     * @return BlameLineData[]
     */
    public function getBlameLines(string $revision, string $file, ?string $lineRange): array;

    /**
     * @return string[] Paths of the untracked files, relative to the root of the working tree
     */
    public function getUntrackedFiles(): array;

    public function checkout(string $revision): void;

    public function checkoutNewBranch(string $branch, string $revision): void;

    /**
     * @param string[] $args Extra arguments of ``git merge`` (e.g. ``--no-ff``)
     */
    public function merge(string $revision, array $args): void;
}
