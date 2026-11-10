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

use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\FastForward;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An application may call a driver itself: it refuses the arguments git would
 * misread, whatever Repository and the domain objects check before calling it.
 *
 * LeadingDashTest, NulByteTest and RemoteUrlTest call it through them.
 */
class DriverArgumentsTest extends AbstractTestCase
{
    private const string TREE = 'b06890c7b10904979d2f69613c2ccda30aafe262';
    private const string BLOB = '225fce09b8029e4c44cff4513ef86d89ca53f1cc';
    private const string ANNOTATED_TAG = 'b61d8c2cac78a443729ca16cad12b96e3d98365d';

    /**
     * The sprintf() format making an invalid argument of a valid one.
     */
    private const string NUL_BYTE = "%s\0junk";

    /**
     * @param \Closure(RepositoryDriverInterface, string): mixed $call
     */
    #[DataProvider('provideRepositoryDriverCallsWithARevision')]
    #[DataProvider('provideRepositoryDriverCallsWithAnObject')]
    public function testARepositoryDriverRefusesALeadingDash(\Closure $call): void
    {
        $driver = self::createFoobarRepository(false)->getRepositoryDriver();
        $file = self::createTempDir().'/written';

        try {
            $call($driver, self::leadingDash($file));
            $this->fail('A leading dash is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(\sprintf('"--output=%s" starts with a dash, which git would read as an option.', $file), $e->getMessage());
        }

        $this->assertFileDoesNotExist($file);
        $this->assertSame('refs/heads/master', $driver->getHeadReference(), 'Nothing is done');
    }

    /**
     * Without the NUL byte and what follows it, every argument is valid: read
     * as a C string, it would name something.
     *
     * @param \Closure(RepositoryDriverInterface, string): mixed $call
     */
    #[DataProvider('provideRepositoryDriverCallsWithARevision')]
    #[DataProvider('provideRepositoryDriverCallsWithAnObject')]
    #[DataProvider('provideRepositoryDriverCallsWithAPath')]
    public function testARepositoryDriverRefusesANulByte(\Closure $call): void
    {
        $driver = self::createFoobarRepository(false)->getRepositoryDriver();
        $references = array_column($driver->getReferences(), 'commitHash', 'fullname');

        try {
            $call($driver, self::NUL_BYTE);
            $this->fail('A NUL byte is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringEndsWith('\000junk" contains a NUL byte, which git doesn\'t accept.', $e->getMessage());
        }

        $this->assertSame('refs/heads/master', $driver->getHeadReference(), 'Nothing is done');
        $this->assertSame($references, array_column($driver->getReferences(), 'commitHash', 'fullname'), 'Nothing is done');
    }

    /**
     * The arguments naming a revision, a hash or a reference: $invalid is the
     * sprintf() format making an invalid one of a valid one.
     *
     * @return iterable<string, array{\Closure(RepositoryDriverInterface, string): mixed}>
     */
    public static function provideRepositoryDriverCallsWithARevision(): iterable
    {
        yield 'resolveRevision()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->resolveRevision(\sprintf($invalid, 'master'))];
        yield 'getShortHash()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getShortHash(\sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'getCommitData()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getCommitData(\sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'getCommitLog(), a commit to include' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getCommitLog([\sprintf($invalid, self::LONGFILE_COMMIT)], [], [], 0, null)];
        yield 'getCommitLog(), a commit to exclude' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getCommitLog([self::LONGFILE_COMMIT], [\sprintf($invalid, self::INITIAL_COMMIT)], [], 0, null)];
        yield 'getCommitLog(), a commit to exclude from every reference' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getCommitLog(null, [\sprintf($invalid, self::INITIAL_COMMIT)], [], 0, null)];
        yield 'countCommits(), a commit to include' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->countCommits([\sprintf($invalid, self::LONGFILE_COMMIT)], [], [])];
        yield 'countCommits(), a commit to exclude' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->countCommits([self::LONGFILE_COMMIT], [\sprintf($invalid, self::INITIAL_COMMIT)], [])];
        yield 'countCommits(), a commit to exclude from every reference' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->countCommits(null, [\sprintf($invalid, self::INITIAL_COMMIT)], [])];
        yield 'getMergeBases(), the first commit' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getMergeBases(\sprintf($invalid, self::LONGFILE_COMMIT), self::INITIAL_COMMIT)];
        yield 'getMergeBases(), the second commit' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getMergeBases(self::LONGFILE_COMMIT, \sprintf($invalid, self::INITIAL_COMMIT))];
        yield 'createReference(), the reference' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->createReference(\sprintf($invalid, 'refs/heads/new'), self::LONGFILE_COMMIT)];
        yield 'createReference(), the hash' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->createReference('refs/heads/new', \sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'updateReference(), the reference' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->updateReference(\sprintf($invalid, 'refs/heads/master'), self::LONGFILE_COMMIT)];
        yield 'updateReference(), the hash' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->updateReference('refs/heads/master', \sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'deleteReference()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->deleteReference(\sprintf($invalid, 'refs/tags/0.1'))];
        yield 'getIncludingBranches()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getIncludingBranches(\sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'getDiff(), the first commit' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getDiff(\sprintf($invalid, self::INITIAL_COMMIT), self::LONGFILE_COMMIT)];
        yield 'getDiff(), the second commit' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getDiff(self::INITIAL_COMMIT, \sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'getDiff() from the empty tree' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getDiff(null, \sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'getWorkingTreeDiff()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getWorkingTreeDiff(\sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'getBlameLines(), the commit' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getBlameLines(\sprintf($invalid, self::LONGFILE_COMMIT), 'README.md', null, null)];
        yield 'checkout()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->checkout(\sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'checkoutNewBranch(), the branch' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->checkoutNewBranch(\sprintf($invalid, 'new'), self::LONGFILE_COMMIT)];
        yield 'checkoutNewBranch(), the start point' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->checkoutNewBranch('new', \sprintf($invalid, self::LONGFILE_COMMIT))];
        yield 'merge()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->merge(\sprintf($invalid, 'origin/new-feature'), FastForward::Allow)];
    }

    /**
     * The methods reading an object by its hash.
     *
     * @return iterable<string, array{\Closure(RepositoryDriverInterface, string): mixed}>
     */
    public static function provideRepositoryDriverCallsWithAnObject(): iterable
    {
        yield 'getTreeEntries()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getTreeEntries(\sprintf($invalid, self::TREE))];
        yield 'getBlobContent()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getBlobContent(\sprintf($invalid, self::BLOB))];
        yield 'getTagData()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getTagData(\sprintf($invalid, self::ANNOTATED_TAG))];
    }

    /**
     * A path may start with a dash.
     *
     * @return iterable<string, array{\Closure(RepositoryDriverInterface, string): mixed}>
     */
    public static function provideRepositoryDriverCallsWithAPath(): iterable
    {
        yield 'getCommitLog(), a path' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getCommitLog([self::LONGFILE_COMMIT], [], [\sprintf($invalid, 'README.md')], 0, null)];
        yield 'countCommits(), a path' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->countCommits([self::LONGFILE_COMMIT], [], [\sprintf($invalid, 'README.md')])];
        yield 'getBlameLines(), the path' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getBlameLines(self::LONGFILE_COMMIT, \sprintf($invalid, 'README.md'), null, null)];
    }

    /**
     * CliDriver writes these hashes on the standard input of git, one per
     * line: what follows a new line would be another one.
     *
     * @param \Closure(RepositoryDriverInterface, string): mixed $call
     */
    #[DataProvider('provideCliRepositoryDriverCallsWritingAHashOnTheStandardInput')]
    public function testCliDriverRefusesANewLineInAHashOfTheStandardInput(\Closure $call, string $hash): void
    {
        $this->skipUnlessCliDriver();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('"%s\n%s" contains a new line, which git doesn\'t accept.', $hash, self::INITIAL_COMMIT));

        $call(self::createFoobarRepository(false)->getRepositoryDriver(), $hash."\n".self::INITIAL_COMMIT);
    }

    /**
     * @return iterable<string, array{\Closure(RepositoryDriverInterface, string): mixed, string}>
     */
    public static function provideCliRepositoryDriverCallsWritingAHashOnTheStandardInput(): iterable
    {
        yield 'getCommitLog(), a commit to include' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getCommitLog([$invalid], [], [], 0, null), self::LONGFILE_COMMIT];
        yield 'getCommitLog(), a commit to exclude' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getCommitLog(null, [$invalid], [], 0, null), self::LONGFILE_COMMIT];
        yield 'countCommits()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->countCommits([$invalid], [], []), self::LONGFILE_COMMIT];
        yield 'getBlobContent()' => [static fn (RepositoryDriverInterface $driver, string $invalid) => $driver->getBlobContent($invalid), self::BLOB];
    }

    /**
     * @param \Closure(DriverInterface, string, string, string): mixed $call
     */
    #[DataProvider('provideDriverCallsWithAUrl')]
    public function testADriverRefusesAUrlStartingWithADash(\Closure $call): void
    {
        $dir = self::createTempDir();
        $file = $dir.'/written';

        try {
            $call(self::getDriver(), self::leadingDash($file), self::repositoryUrl(), $dir);
            $this->fail('A leading dash is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(\sprintf('"--output=%s" starts with a dash, which git would read as an option.', $file), $e->getMessage());
        }

        $this->assertSame(['.', '..'], scandir($dir), 'Nothing is created');
    }

    /**
     * @param \Closure(DriverInterface, string, string, string): mixed $call
     */
    #[DataProvider('provideDriverCallsWithAUrl')]
    #[DataProvider('provideDriverCallsWithAPathOrABranch')]
    public function testADriverRefusesANulByte(\Closure $call): void
    {
        $dir = self::createTempDir();

        try {
            $call(self::getDriver(), self::NUL_BYTE, self::repositoryUrl(), $dir);
            $this->fail('A NUL byte is refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringEndsWith('\000junk" contains a NUL byte, which git doesn\'t accept.', $e->getMessage());
        }

        $this->assertSame(['.', '..'], scandir($dir), 'Nothing is created');
    }

    /**
     * $invalid is the sprintf() format making an invalid argument of a valid
     * one; then the URL of a repository, and an empty directory.
     *
     * @return iterable<string, array{\Closure(DriverInterface, string, string, string): mixed}>
     */
    public static function provideDriverCallsWithAUrl(): iterable
    {
        yield 'cloneFrom()' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->cloneFrom(\sprintf($invalid, $url), $dir.'/clone', null)];
        yield 'cloneBareFrom()' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->cloneBareFrom(\sprintf($invalid, $url), $dir.'/clone', null)];
        yield 'mirrorFrom()' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->mirrorFrom(\sprintf($invalid, $url), $dir.'/clone')];
        yield 'isValidRepository()' => [static fn (DriverInterface $driver, string $invalid, string $url) => $driver->isValidRepository(\sprintf($invalid, $url))];
        yield 'isValidRepositoryAndBranch()' => [static fn (DriverInterface $driver, string $invalid, string $url) => $driver->isValidRepositoryAndBranch(\sprintf($invalid, $url), 'master')];
    }

    /**
     * A path may start with a dash, and git reads a branch after an option.
     *
     * @return iterable<string, array{\Closure(DriverInterface, string, string, string): mixed}>
     */
    public static function provideDriverCallsWithAPathOrABranch(): iterable
    {
        yield 'open(), the directory' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->open(\sprintf($invalid, self::createFoobarRepository()->getGitDir()), null)];
        yield 'open(), the working directory' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->open(self::createFoobarRepository()->getGitDir(), \sprintf($invalid, $dir))];
        yield 'init()' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->init(\sprintf($invalid, $dir.'/repository'))];
        yield 'initBare()' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->initBare(\sprintf($invalid, $dir.'/repository'))];
        yield 'cloneFrom(), the path' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->cloneFrom($url, \sprintf($invalid, $dir.'/clone'), null)];
        yield 'cloneFrom(), the branch' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->cloneFrom($url, $dir.'/clone', \sprintf($invalid, 'master'))];
        yield 'cloneBareFrom(), the path' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->cloneBareFrom($url, \sprintf($invalid, $dir.'/clone'), null)];
        yield 'cloneBareFrom(), the branch' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->cloneBareFrom($url, $dir.'/clone', \sprintf($invalid, 'master'))];
        yield 'mirrorFrom(), the path' => [static fn (DriverInterface $driver, string $invalid, string $url, string $dir) => $driver->mirrorFrom($url, \sprintf($invalid, $dir.'/clone'))];
        yield 'isValidRepositoryAndBranch(), the branch' => [static fn (DriverInterface $driver, string $invalid, string $url) => $driver->isValidRepositoryAndBranch($url, \sprintf($invalid, 'master'))];
    }

    /**
     * The "--output" option of git log writes a file.
     *
     * @return string A sprintf() format, like NUL_BYTE: whatever the valid argument, an option
     */
    private static function leadingDash(string $file): string
    {
        return str_replace('%', '%%', '--output='.$file);
    }
}
