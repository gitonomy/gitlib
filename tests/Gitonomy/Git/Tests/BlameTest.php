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

use Gitonomy\Git\Blame\Line;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

class BlameTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testBlame(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md');

        $this->assertCount(7, $blame);

        $this->assertSame('Foo Bar project', $blame->getLine(1)->getContent());

        $this->assertSame('alice', $blame->getLine(1)->getCommit()->getAuthorName());
        $this->assertSame(self::INITIAL_COMMIT, $blame->getLine(1)->getCommit()->getHash());

        $this->assertSame('alice', $blame->getLine(5)->getCommit()->getAuthorName());
        $this->assertNotSame(self::INITIAL_COMMIT, $blame->getLine(5)->getCommit()->getHash());
    }

    #[DataProvider('provideBare')]
    public function testHunks(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $hunks = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md')->getHunks();

        $this->assertCount(3, $hunks);
        $this->assertSame(self::INITIAL_COMMIT, $hunks[0]->getCommit()->getHash());
        $this->assertSame(range(1, \count($hunks[0]->getLines())), array_keys($hunks[0]->getLines()));
    }

    #[DataProvider('provideBare')]
    public function testBlameLineRange(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $full = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md');

        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 3, 5);

        $this->assertCount(3, $blame);
        $this->assertSame([3, 4, 5], array_keys($blame->getLines()));
        foreach ([3, 4, 5] as $number) {
            $this->assertSame($number, $blame->getLine($number)->getNumber());
            $this->assertSame($full->getLine($number)->getOriginalNumber(), $blame->getLine($number)->getOriginalNumber());
            $this->assertSame($full->getLine($number)->getCommit()->getHash(), $blame->getLine($number)->getCommit()->getHash());
            $this->assertSame($full->getLine($number)->getContent(), $blame->getLine($number)->getContent());
        }
        $this->assertCount(5, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 3));
        $this->assertCount(2, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', lastLine: 2));
    }

    public function testBlameRefusesAnInvalidRange(): void
    {
        $repository = self::createFoobarRepository();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid range of lines "0,3": the lines are numbered from 1.');

        $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 0, 3);
    }

    public function testBlameOfAnInvertedRangeIsTheRangeInOrder(): void
    {
        $repository = self::createFoobarRepository();

        // Like git blame -L 5,3.
        $this->assertSame([3, 4, 5], array_keys($repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 5, 3)->getLines()));
    }

    public function testBlameOfARangeAfterTheEndThrows(): void
    {
        $repository = self::createFoobarRepository();

        $this->expectException(DriverException::class);

        $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', 100)->getLines();
    }

    /**
     * After a merge whose sides kept a file under two names, git blames a
     * commit under both: "git blame -p" repeats its "filename" line on each
     * of its groups of lines.
     */
    public function testBlameOfACommitUnderTwoPaths(): void
    {
        $repository = Repository::init(self::createTempDir(), self::getDriver());
        $dir = $repository->getWorkingDir();
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        file_put_contents($dir.'/a', "L1\nL2\nL3\n");
        file_put_contents($dir.'/b', "L1\nL2\nL3\n");
        self::git($repository, 'add', ['a', 'b']);
        self::git($repository, 'commit', ['-q', '-m', 'Both']);
        $both = trim(self::git($repository, 'rev-parse', ['HEAD']));
        self::git($repository, 'checkout', ['-q', '-b', 'side']);
        self::git($repository, 'rm', ['-q', 'a']);
        self::git($repository, 'commit', ['-q', '-m', 'Without a']);
        self::git($repository, 'checkout', ['-q', 'master']);
        file_put_contents($dir.'/a', "L1\nL2\nNEW\n");
        self::git($repository, 'rm', ['-q', 'b']);
        self::git($repository, 'commit', ['-q', '-a', '-m', 'Without b']);

        try {
            // A conflict: a is deleted on one side, and changed on the other.
            self::git($repository, 'merge', ['-q', '--no-edit', 'side']);
        } catch (ProcessException) {
        }
        file_put_contents($dir.'/a', "L1\nL2x\nL3\n");
        self::git($repository, 'add', ['a']);
        self::git($repository, 'commit', ['-q', '-m', 'Merge']);
        $repository = new Repository($repository->getPath(), driver: self::getDriver());

        $lines = array_map(static fn (Line $line): array => [$line->getCommit()->getHash(), $line->getContent()], $repository->getBlame('HEAD', 'a')->getLines());

        $this->assertSame([$both, 'L1'], $lines[1]);
        $this->assertSame('L2x', $lines[2][1]);
        $this->assertSame('L3', $lines[3][1]);
    }

    public function testBlameOfAPathNormalizesIt(): void
    {
        $repository = self::createFoobarRepository();
        $lines = static fn (string $path): array => array_map(static fn (Line $line): array => [$line->getCommit()->getHash(), $line->getContent()], $repository->getBlame(self::LONGFILE_COMMIT, $path)->getLines());

        // Like git.
        $this->assertSame($lines('README.md'), $lines('./README.md'));
        $this->assertSame($lines('README.md'), $lines('dir/../README.md'));
    }

    #[DataProvider('provideBare')]
    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyBlameLineRange(bool $bare): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 1.7: Passing a range of lines as a string to "Gitonomy\Git\Repository::getBlame()" is deprecated, pass the first and the last lines instead.');

        $repository = self::createFoobarRepository($bare);

        foreach (['3,+3', '3,5'] as $lineRange) {
            $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', $lineRange);

            $this->assertSame([3, 4, 5], array_keys($blame->getLines()), $lineRange);
            $this->assertSame('3', $blame->getLine(3)->getLine(), $lineRange);
        }
        $this->assertSame(self::INITIAL_COMMIT, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md')->getGroupedLines()[0][0]->getHash());
    }
}
