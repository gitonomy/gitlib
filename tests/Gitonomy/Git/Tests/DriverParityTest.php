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

namespace Gitonomy\Git\Tests;

use Gitonomy\Git\Diff\DiffLine;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Driver\Data\DiffFileData;
use Gitonomy\Git\Driver\Data\ObjectType;
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\Before;

/**
 * Both drivers read the same data from the same repository, but where
 * doc/repository.md documents a difference.
 */
class DriverParityTest extends AbstractTestCase
{
    private RepositoryDriverInterface $cli;

    private RepositoryDriverInterface $libgit2;

    /**
     * The objects compared already: most commits share them.
     *
     * @var array<string, true>
     */
    private array $compared = [];

    #[Before]
    public function openWithBothDrivers(): void
    {
        if (!LibGit2Driver::isSupported()) {
            $this->markTestSkipped('libgit2 1.9 is not available.');
        }

        $dir = self::createFoobarRepository(false)->getPath();
        $this->cli = new Repository($dir, driver: self::getCliDriver())->getRepositoryDriver();
        $this->libgit2 = new Repository($dir, driver: new LibGit2Driver())->getRepositoryDriver();
    }

    public function testReferences(): void
    {
        $this->assertSameData($this->cli->getReferences(), $this->libgit2->getReferences());
        $this->assertSame($this->cli->getHeadReference(), $this->libgit2->getHeadReference());
    }

    public function testHistory(): void
    {
        $this->assertSameData($this->cli->getCommitLog(null, [], [], 0, null), $this->libgit2->getCommitLog(null, [], [], 0, null));
        $this->assertSame($this->cli->countCommits(null, [], []), $this->libgit2->countCommits(null, [], []));

        $master = $this->cli->resolveRevision('master');
        $this->assertSameData($this->cli->getCommitLog([$master], [], ['README.md'], 0, null), $this->libgit2->getCommitLog([$master], [], ['README.md'], 0, null));

        foreach ($this->cli->getCommitLog(null, [], [], 0, null) as $commit) {
            $this->assertSame($this->cli->getIncludingBranches($commit->hash), $this->libgit2->getIncludingBranches($commit->hash), $commit->hash);
        }
    }

    public function testObjects(): void
    {
        foreach ($this->cli->getCommitLog(null, [], [], 0, null) as $commit) {
            $this->assertSameTree($commit->treeHash);
        }

        foreach ($this->cli->getReferences() as $reference) {
            if (null !== $reference->tagHash) {
                $this->assertSameData($this->cli->getTagData($reference->tagHash), $this->libgit2->getTagData($reference->tagHash));
            }
        }
    }

    public function testDiffs(): void
    {
        foreach ($this->cli->getCommitLog(null, [], [], 0, null) as $commit) {
            $from = $commit->parentHashes[0] ?? null;

            // The raw diff is the output of git, which LibGit2Driver doesn't run.
            $this->assertSameData($this->cli->getDiff($from, $commit->hash)->files, $this->libgit2->getDiff($from, $commit->hash)->files, $commit->hash);
        }
    }

    /**
     * doc/repository.md: rename detection in diffs may pair files differently.
     */
    public function testRenameDetectionMayPairFilesDifferently(): void
    {
        $master = $this->cli->resolveRevision('master');
        $cli = $this->cli->getDiff(self::INITIAL_COMMIT, $master)->files;
        $libgit2 = $this->libgit2->getDiff(self::INITIAL_COMMIT, $master)->files;

        // git deletes README and creates README.md, which libgit2 finds similar enough for a rename.
        $this->assertSame([['README', null], [null, 'README.md']], self::readmeNames($cli));
        $this->assertSame([['README', 'README.md']], self::readmeNames($libgit2));
        $this->assertCount(13, $cli);
        $this->assertCount(12, $libgit2);
    }

    public function testBlame(): void
    {
        $master = $this->cli->resolveRevision('master');

        $this->assertSameData($this->cli->getBlameLines($master, 'README.md', null, null), $this->libgit2->getBlameLines($master, 'README.md', null, null));
        $this->assertSameData($this->cli->getBlameLines($master, 'README.md', 2, 3), $this->libgit2->getBlameLines($master, 'README.md', 2, 3));
    }

    private function assertSameTree(string $hash): void
    {
        if (isset($this->compared[$hash])) {
            return;
        }
        $this->compared[$hash] = true;

        $entries = $this->cli->getTreeEntries($hash);
        $this->assertSameData($entries, $this->libgit2->getTreeEntries($hash), $hash);

        foreach ($entries as $entry) {
            match ($entry->type) {
                ObjectType::Tree => $this->assertSameTree($entry->hash),
                ObjectType::Blob => $this->assertSameBlob($entry->hash),
                // A submodule: a commit of another repository.
                default => null,
            };
        }
    }

    private function assertSameBlob(string $hash): void
    {
        if (!isset($this->compared[$hash])) {
            $this->compared[$hash] = true;
            $this->assertSame($this->cli->getBlobContent($hash), $this->libgit2->getBlobContent($hash), $hash);
        }
    }

    /**
     * @param list<DiffFileData> $files
     *
     * @return list<array{string|null, string|null}> The old and new names of the files named README or README.md
     */
    private static function readmeNames(array $files): array
    {
        $names = array_map(static fn (DiffFileData $file): array => [$file->oldName, $file->newName], $files);

        return array_values(array_filter($names, static fn (array $pair): bool => [] !== array_intersect($pair, ['README', 'README.md'])));
    }

    private function assertSameData(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame(self::normalize($expected), self::normalize($actual), $message);
    }

    /**
     * Turns the data objects into arrays, which assertSame() compares by value.
     */
    private static function normalize(mixed $data): mixed
    {
        return match (true) {
            \is_array($data) => array_map(self::normalize(...), $data),
            $data instanceof \DateTimeImmutable => $data->format(\DATE_ATOM),
            $data instanceof \UnitEnum => $data->name,
            $data instanceof FileChange => self::normalize($data->toArray()),
            $data instanceof DiffLine => [$data->getType()->name, $data->getContent()],
            \is_object($data) => self::normalize(get_object_vars($data)),
            default => $data,
        };
    }
}
