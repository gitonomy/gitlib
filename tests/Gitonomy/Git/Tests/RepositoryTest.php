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

use Gitonomy\Git\Blob;
use Gitonomy\Git\Commit;
use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Psr\Log\LoggerInterface;

class RepositoryTest extends AbstractTestCase
{
    public function testInitBare(): void
    {
        $dir = self::createTempDir();
        $repository = Repository::initBare($dir, driver: self::getDriver());

        $this->assertTrue($repository->isBare(), 'Repository is bare');
        $this->assertDirectoryExists($dir.'/objects', 'objects/ folder is present');
        $this->assertEquals($dir, $repository->getGitDir(), 'The folder passed as argument is git dir');
        $this->assertNull($repository->getWorkingDir(), 'No working dir in bare repository');
    }

    public function testInit(): void
    {
        $dir = self::createTempDir();
        $repository = Repository::init($dir, driver: self::getDriver());

        $this->assertFalse($repository->isBare(), 'Repository is not bare');
        $this->assertDirectoryExists($dir.'/.git/objects', 'objects/ folder is present');
        $this->assertEquals($dir.'/.git', $repository->getGitDir(), 'git dir as subfolder of argument');
        $this->assertEquals($dir, $repository->getWorkingDir(), 'working dir present in bare repository');
    }

    public function testInitOnAnExistingFileFails(): void
    {
        $this->expectException(RuntimeException::class);

        $file = self::createTempDir().'/test';
        touch($file);

        Repository::initBare($file, driver: self::getDriver());
    }

    public function testNamedConstructorsGiveTheLoggerToTheRepository(): void
    {
        $logger = $this->createStub(LoggerInterface::class);

        $repository = Repository::initBare(self::createTempDir(), $logger, self::getDriver());

        $this->assertSame($logger, $repository->getLogger());
    }

    public function testCloneBareFrom(): void
    {
        $repository = self::createFoobarRepository(true);

        $newDir = self::createTempDir();
        $new = Repository::cloneBareFrom($repository->getGitDir(), $newDir, driver: self::getDriver());

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);
        $this->assertEquals($newDir, $new->getGitDir());
        $this->assertContains('refs/heads/new-feature', $newRefs);
        // Like `git clone --bare`: the remote has no fetch refspec.
        $this->assertStringNotContainsString('fetch', file_get_contents($newDir.'/config'));
    }

    public function testCloneFrom(): void
    {
        $repository = self::createFoobarRepository(false);

        $newDir = self::createTempDir();
        $new = Repository::cloneFrom($repository->getGitDir(), $newDir, driver: self::getDriver());

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);
        $this->assertEquals($newDir.'/.git', $new->getGitDir());
        $this->assertEquals($newDir, $new->getWorkingDir());
    }

    public function testCloneBareFromABranch(): void
    {
        // we can't use AbstractTestCase::createFoobarRepository()
        // because it does not clone other branches than "master"
        // so we test it directly against the remote repository

        $newDir = self::createTempDir();
        $new = Repository::cloneBareFrom(self::repositoryUrl(), $newDir, 'new-feature', driver: self::getDriver());

        $head = $new->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertEquals('new-feature', $head->getName(), 'HEAD is branch new-feature');
    }

    public function testCloneFromABranch(): void
    {
        // we can't use AbstractTestCase::createFoobarRepository()
        // because it does not clone other branches than "master"
        // so we test it directly against remote repository

        $newDir = self::createTempDir();
        $new = Repository::cloneFrom(self::repositoryUrl(), $newDir, 'new-feature', driver: self::getDriver());

        $head = $new->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertEquals('new-feature', $head->getName(), 'HEAD is branch new-feature');
    }

    public function testCloneFromAnUnknownBranchFails(): void
    {
        $this->expectException(DriverException::class);

        Repository::cloneBareFrom(self::repositoryUrl(), self::createTempDir().'/clone', 'unknown', driver: self::getDriver());
    }

    #[DataProvider('provideBare')]
    public function testMirrorFrom(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $newDir = self::createTempDir();
        $new = Repository::mirrorFrom($repository->getGitDir(), $newDir, driver: self::getDriver());

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);
        $this->assertEquals($newDir, $new->getGitDir());

        if ($repository->isBare()) {
            $this->assertContains('refs/heads/new-feature', $newRefs);
        } else {
            $this->assertContains('refs/remotes/origin/new-feature', $newRefs);
        }

        $this->assertSame('true', trim(self::git($new, 'config', ['remote.origin.mirror'])));
        $this->assertSame('+refs/*:refs/*', trim(self::git($new, 'config', ['remote.origin.fetch'])));
        $this->assertSame('refs/heads/master', trim(self::git($new, 'symbolic-ref', ['HEAD'])));
    }

    #[DataProvider('provideBare')]
    public function testCheckValidRepository(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $url = $repository->getGitDir();
        $this->assertTrue(Repository::isValidRepository($url, self::getDriver()));
    }

    public function testCheckInvalidRepository(): void
    {
        $url = self::createTempDir().'/invalid.git';
        mkdir($url);

        $this->assertFalse(Repository::isValidRepository($url, self::getDriver()));
    }

    #[DataProvider('provideBare')]
    public function testCheckValidRepositoryAndBranch(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $url = $repository->getGitDir();
        $this->assertTrue(Repository::isValidRepositoryAndBranch($url, 'master', self::getDriver()));
    }

    #[DataProvider('provideBare')]
    public function testCheckInvalidRepositoryAndBranch(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $url = $repository->getGitDir();
        $this->assertFalse(Repository::isValidRepositoryAndBranch($url, 'invalid-branch-name', self::getDriver()));
    }

    #[DataProvider('provideBare')]
    public function testCheckRepositoryAndBranchOnlyMatchesBranches(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        foreach (['0.1', 'HEAD', 'feature'] as $name) {
            $this->assertFalse(Repository::isValidRepositoryAndBranch($repository->getGitDir(), $name, self::getDriver()), $name);
        }
    }

    public function testNullProcessTimeoutDisablesTheTimeout(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(true);
        $repository = new Repository($repository->getPath(), driver: self::getCliDriver(processTimeout: null));

        $process = $repository->runProcess('rev-parse', ['HEAD']);
        $this->assertNull($process->getTimeout());
        $this->assertNull($process->getIdleTimeout());
    }

    public function testRunThrowsWhenTheCommandFails(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(true);

        $this->expectException(ProcessException::class);

        $repository->run('not-a-command');
    }

    public function testRunProcessGivesAccessToStderrOnSuccess(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(false);

        // `git checkout` reports the switched branch on stderr, even on success.
        $process = $repository->runProcess('checkout', ['master']);

        $this->assertTrue($process->isSuccessful());
        $this->assertStringContainsString('master', $process->getErrorOutput());
    }

    public function testGetShortHashOfAnUnknownCommitThrowsReferenceNotFound(): void
    {
        $repository = self::createFoobarRepository(true);

        $commit = new Commit($repository, str_repeat('a', 40));

        $this->expectException(ReferenceNotFoundException::class);

        $commit->getShortHash();
    }

    #[DataProvider('provideBare')]
    public function testGetBlobWithExistingWorks(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $blob = $repository->getCommit(self::LONGFILE_COMMIT)->getTree()->resolvePath('README.md');

        $this->assertInstanceOf(Blob::class, $blob, 'getBlob() returns a Blob object');
        $this->assertStringContainsString('Foo Bar project', $blob->getContent(), 'file is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetSize(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $size = $repository->getSize();
        // The exact figure depends on git's packing/compression, which varies across versions.
        $this->assertGreaterThanOrEqual(40, $size, 'Repository is at least 40KB');
        $this->assertLessThan(84, $size, 'Repository is less than 84KB');
    }

    public function testIsBare(): void
    {
        $bare = self::createFoobarRepository(true);
        $this->assertTrue($bare->isBare(), 'Lib repository is bare');

        $notBare = self::createFoobarRepository(false);
        $this->assertFalse($notBare->isBare(), 'Working copy is not bare');
    }

    #[DataProvider('provideBare')]
    public function testGetDescription(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->assertSame("Unnamed repository; edit this file 'description' to name the repository.\n", $repository->getDescription());
    }

    #[DataProvider('provideBare')]
    public function testLoggerOk(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->skipUnlessCliDriver();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('run command: remote "" ')
        ;
        $logger->expects($this->exactly(3)) // duration, return code and output
            ->method('debug')
            ->with($this->isString())
        ;

        $repository->setLogger($logger);

        $repository->run('remote');
    }

    #[DataProvider('provideBare')]
    public function testLoggerNOk(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->skipUnlessCliDriver();

        $this->expectException(RuntimeException::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->isString())
        ;
        $logger->expects($this->exactly(4)) // duration, return code, output and error output
            ->method('debug')
            ->with($this->isString())
        ;
        // The exception reports the failure: the caller may well expect it.
        $logger->expects($this->never())
            ->method('error')
        ;

        $repository->setLogger($logger);

        $repository->run('not-work');
    }

    /**
     * @see https://github.com/gitonomy/gitlib/issues/67
     */
    public function testRunResolvesRelativePathsAgainstTheRepositoryRegardlessOfCwd(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(false);

        $file = $repository->getWorkingDir().'/README.md';
        $original = file_get_contents($file);
        file_put_contents($file, $original."Applied line.\n");

        $patch = $repository->run('diff', ['--', 'README.md']);
        file_put_contents($file, $original);

        $patchFile = tempnam(sys_get_temp_dir(), 'gitlib_patch_');
        file_put_contents($patchFile, $patch);

        $previousCwd = getcwd();
        $this->assertIsString($previousCwd);
        chdir(sys_get_temp_dir());

        try {
            // "README.md" is relative to the repository work-tree, not to the
            // process cwd (which is an unrelated directory here). This only
            // works if the git process is run with its cwd set to the repository.
            $repository->run('apply', [$patchFile]);
        } finally {
            chdir($previousCwd);
            unlink($patchFile);
        }

        $this->assertSame($original."Applied line.\n", file_get_contents($file));
    }

    public function testRunIsOnlySupportedByCliDriver(): void
    {
        $repository = new Repository(self::createTempDir(), driver: $this->createStub(DriverInterface::class));

        $this->expectException(UnsupportedOperationException::class);

        $repository->run('status');
    }

    public function testDriverOpensTheRepository(): void
    {
        $dir = realpath(self::createTempDir());
        $logger = $this->createStub(LoggerInterface::class);
        $repositoryDriver = $this->createStub(RepositoryDriverInterface::class);
        $repositoryDriver->method('getReferences')->willReturn([new ReferenceData('refs/heads/main', self::INITIAL_COMMIT)]);

        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())->method('open')->with($dir, null, $logger)->willReturn($repositoryDriver);

        $repository = new Repository($dir, ['logger' => $logger], $driver);

        $this->assertSame(self::INITIAL_COMMIT, $repository->getReferences()->getBranch('main')->getCommitHash());
    }

    public function testSetLoggerReopensTheRepositoryWithIt(): void
    {
        $loggers = [];
        $driver = $this->createStub(DriverInterface::class);
        $driver->method('open')->willReturnCallback(function (string $gitDir, ?string $workingDir, ?LoggerInterface $logger) use (&$loggers): RepositoryDriverInterface {
            $loggers[] = $logger;

            return $this->createStub(RepositoryDriverInterface::class);
        });
        $logger = $this->createStub(LoggerInterface::class);

        new Repository(self::createTempDir(), driver: $driver)->setLogger($logger);

        $this->assertSame([null, $logger], $loggers);
    }

    public function testUnknownOptionsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown options "foo", "bar": expected "logger" or "working_dir".');

        new Repository(self::createTempDir(), ['foo' => 1, 'bar' => 2]);
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGitOptionsConfigureACliDriver(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Passing "command", "process_timeout" in the options of "Gitonomy\Git\Repository::__construct()" is deprecated, pass a "Gitonomy\Git\Driver\Cli\CliDriver" as driver argument instead.');

        $logger = $this->createStub(LoggerInterface::class);
        $repository = new Repository(self::createFoobarRepository()->getGitDir(), [
            'command' => $_SERVER['GIT_COMMAND'] ?? 'git',
            'logger' => $logger,
            'process_timeout' => 42,
        ]);

        $this->assertSame($logger, $repository->getLogger());
        $this->assertSame(42.0, $repository->runProcess('rev-parse', ['HEAD'])->getTimeout());
    }

    public function testLegacyGitOptionsCantBeCombinedWithADriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The driver argument can\'t be combined with "process_timeout" in the options: configure the driver instead.');

        new Repository(self::createTempDir(), ['process_timeout' => 60], self::getDriver());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyDebugOption(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Passing "debug" in the options of "Gitonomy\Git\Repository::__construct()" is deprecated, failing git operations always throw.');

        $repository = new Repository(self::createFoobarRepository()->getGitDir(), ['debug' => true], self::getDriver());

        $this->assertSame(self::LONGFILE_COMMIT, $repository->getCommit(self::LONGFILE_COMMIT)->getHash());
    }

    public function testDebugOptionSetToFalseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "debug" option set to false is not supported anymore: a failing git operation always throws, catch its exception instead.');

        new Repository(self::createTempDir(), ['debug' => false]);
    }
}
