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

use Gitonomy\Git\Commit;
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Revision;
use Gitonomy\Git\RevisionList;
use PHPUnit\Framework\Attributes\DataProvider;

class RevisionTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testGetCommit(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $revision = $repository->getRevision(self::LONGFILE_COMMIT.'^');

        $commit = $revision->getCommit();

        $this->assertSame(self::BEFORE_LONGFILE_COMMIT, $commit->getHash(), 'Resolution is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetFailingReference(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The revision "non-existent-commit" does not exist, or names no commit.');

        $repository->getRevision('non-existent-commit')->getCommit();
    }

    /**
     * "git rev-parse" prints "^<hash>" for it: the commits to leave out of a log.
     */
    public function testARevisionToExcludeNamesNoCommit(): void
    {
        $repository = self::createFoobarRepository();

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The revision "^HEAD" does not exist, or names no commit.');

        $repository->getRevision('^HEAD')->getCommit();
    }

    /**
     * "git rev-parse" takes a full hash at its word.
     */
    public function testTheDriverResolvesNoHashWithoutAnObject(): void
    {
        $driver = self::createFoobarRepository()->getRepositoryDriver();
        $this->assertSame(self::LONGFILE_COMMIT, $driver->resolveRevision(self::LONGFILE_COMMIT));
        $hash = str_repeat('1', 40);

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('The revision "%s" does not exist.', $hash));

        $driver->resolveRevision($hash);
    }

    /**
     * "@" is HEAD for git since 1.8.5, and for libgit2 alone only.
     */
    #[DataProvider('provideAtRevisions')]
    public function testAtIsHead(string $revision, string $expected): void
    {
        if (self::getDriver() instanceof CliDriver && version_compare(self::gitVersion(), '1.8.5', '<')) {
            $this->markTestSkipped('git reads "@" as HEAD since 1.8.5.');
        }

        $repository = self::createFoobarRepository();
        $head = $repository->getHeadCommit();

        $this->assertSame('HEAD' === $expected ? $head->getHash() : $head->getParentHashes()[0], $repository->getRevision($revision)->getCommit()->getHash());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAtRevisions(): iterable
    {
        yield 'alone' => ['@', 'HEAD'];
        yield 'ancestor' => ['@~1', 'parent'];
        yield 'parent' => ['@^', 'parent'];
        yield 'peeled' => ['@^{commit}', 'HEAD'];
    }

    public function testALogOfARangeFromAt(): void
    {
        if (self::getDriver() instanceof CliDriver && version_compare(self::gitVersion(), '1.8.5', '<')) {
            $this->markTestSkipped('git reads "@" as HEAD since 1.8.5.');
        }

        $repository = self::createFoobarRepository();

        $hashes = static fn (string $range): array => array_map(static fn (Commit $commit): string => $commit->getHash(), $repository->getLog($range)->getCommits());

        $this->assertNotSame([], $hashes('HEAD~2..HEAD'));
        $this->assertSame($hashes('HEAD~2..HEAD'), $hashes('@~2..@'));
    }

    /**
     * The message names the revision, without the "^{commit}" gitlib adds.
     */
    public function testARevisionStartingWithADashIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"--output=/x" starts with a dash, which git would read as an option.');

        self::createFoobarRepository()->getRevision('--output=/x')->getCommit();
    }

    #[DataProvider('provideBare')]
    public function testGetLog(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $revision = $repository->getRevision(self::LONGFILE_COMMIT);

        $log = $revision->getLog(null, 2, 3);
        $this->assertSame(2, $log->getOffset(), 'Log offset is passed');
        $this->assertSame(3, $log->getLimit(), 'Log limit is passed');
        $this->assertSame([$revision], $log->getRevisions()?->getAll(), 'Revision is passed');
    }

    public function testGetRepository(): void
    {
        $repository = self::createFoobarRepository();

        $this->assertSame($repository, $repository->getRevision('master')->getRepository());
        $this->assertSame($repository, new Revision($repository, self::LONGFILE_COMMIT)->getRepository());
    }

    public function testARevisionListCountsItsRevisions(): void
    {
        $repository = self::createFoobarRepository();

        $this->assertCount(1, new RevisionList($repository, 'master'));
        $this->assertCount(1, new RevisionList($repository, $repository->getRevision('master')));
        $this->assertCount(3, new RevisionList($repository, ['master', self::LONGFILE_COMMIT, $repository->getRevision('0.1')]));
    }

    public function testARevisionListIteratesOverItsRevisions(): void
    {
        $repository = self::createFoobarRepository();
        $tag = $repository->getRevision('0.1');

        $revisions = iterator_to_array(new RevisionList($repository, ['master', $tag]));

        $this->assertCount(2, $revisions);
        $this->assertSame('master', $revisions[0]->getRevision(), 'A name becomes a revision');
        $this->assertSame($tag, $revisions[1]);
    }

    public function testARevisionListAsTextArray(): void
    {
        $repository = self::createFoobarRepository();

        $this->assertSame(['master', self::LONGFILE_COMMIT, '0.1'], new RevisionList($repository, ['master', $repository->getCommit(self::LONGFILE_COMMIT), $repository->getRevision('0.1')])->getAsTextArray());
    }
}
