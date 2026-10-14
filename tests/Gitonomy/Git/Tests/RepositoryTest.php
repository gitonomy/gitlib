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
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Driver\Data\ReferenceData;
use Gitonomy\Git\Driver\DriverInterface;
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Driver\RepositoryDriverInterface;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ProcessTimedOutException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class RepositoryTest extends AbstractTestCase
{
    public function testInitBare(): void
    {
        $dir = self::createTempDir();
        $repository = Repository::initBare($dir, driver: self::getDriver());

        $this->assertTrue($repository->isBare(), 'Repository is bare');
        $this->assertDirectoryExists($dir.'/objects', 'objects/ folder is present');
        $this->assertSame($dir, $repository->getGitDir(), 'The folder passed as argument is git dir');
        $this->assertNull($repository->getWorkingDir(), 'No working dir in bare repository');
    }

    public function testInit(): void
    {
        $dir = self::createTempDir();
        $repository = Repository::init($dir, driver: self::getDriver());

        $this->assertFalse($repository->isBare(), 'Repository is not bare');
        $this->assertDirectoryExists($dir.'/.git/objects', 'objects/ folder is present');
        $this->assertSame($dir.'/.git', $repository->getGitDir(), 'git dir as subfolder of argument');
        $this->assertSame($dir, $repository->getWorkingDir(), 'The folder passed as argument is the working dir');
    }

    public function testOpensALinkedWorktree(): void
    {
        if (version_compare(self::gitVersion(), '2.5.0', '<')) {
            $this->markTestSkipped('git worktree requires git 2.5.');
        }

        $repository = self::createFoobarRepository(false);
        $dir = self::createTempDir().'/worktree';
        self::git($repository, 'worktree', ['add', '-b', 'in-worktree', $dir]);

        // Its ".git" is a file pointing to a directory of the main repository.
        $worktree = new Repository($dir, driver: self::getDriver());

        $this->assertSame(realpath($dir), $worktree->getWorkingDir());
        $this->assertSame(realpath($repository->getGitDir().'/worktrees/worktree'), $worktree->getGitDir());
        $this->assertFalse($worktree->isBare());
        $head = $worktree->getHead();
        $this->assertInstanceOf(Branch::class, $head);
        $this->assertSame('in-worktree', $head->getName());
        $this->assertSame($repository->getHeadCommit()->getHash(), $worktree->getHeadCommit()->getHash());
    }

    /**
     * The ".git" file of a submodule points to its git directory, in the one
     * of the superproject, with a relative path.
     */
    public function testOpensASubmodule(): void
    {
        $superproject = self::createFoobarRepository(false);
        $source = self::createFoobarRepository();
        // git refuses to clone a local submodule since 2.38.1, unless protocol.file.allow allows it.
        new Process([self::gitCommand(), '-c', 'protocol.file.allow=always', 'submodule', 'add', '-q', $source->getGitDir(), 'sub'], $superproject->getWorkingDir(), self::gitEnvs())->mustRun();
        $dir = $superproject->getWorkingDir().'/sub';
        $this->assertFileMatchesFormat('gitdir: ../.git/modules/sub', $dir.'/.git');

        $submodule = new Repository($dir, driver: self::getDriver());

        $this->assertSame(realpath($dir), $submodule->getWorkingDir());
        $this->assertSame(realpath($superproject->getGitDir().'/modules/sub'), $submodule->getGitDir());
        $this->assertFalse($submodule->isBare());
        $this->assertSame($source->getHeadCommit()->getHash(), $submodule->getHeadCommit()->getHash());
    }

    /**
     * "git clone --separate-git-dir" writes a ".git" file with the absolute
     * path of the git directory.
     */
    public function testOpensACloneWithASeparateGitDir(): void
    {
        $source = self::createFoobarRepository();
        [$dir, $gitDir] = self::cloneWithASeparateGitDir($source);

        $repository = new Repository($dir, driver: self::getDriver());

        $this->assertSame(realpath($dir), $repository->getWorkingDir());
        $this->assertSame(realpath($gitDir), $repository->getGitDir());
        $this->assertFalse($repository->isBare());
        $this->assertSame($source->getHeadCommit()->getHash(), $repository->getHeadCommit()->getHash());
    }

    /**
     * Like git, which leaves out the line ending of a ".git" file only: a
     * path may end with a space.
     */
    #[DataProvider('provideLineEndings')]
    public function testOpensAGitFileWhateverItsLineEnding(string $lineEnding): void
    {
        [$dir, $gitDir] = self::cloneWithASeparateGitDir(self::createFoobarRepository());
        file_put_contents($dir.'/.git', 'gitdir: '.$gitDir.$lineEnding);

        $this->assertSame(realpath($gitDir), new Repository($dir, driver: self::getDriver())->getGitDir());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLineEndings(): iterable
    {
        yield 'none' => [''];
        yield 'LF' => ["\n"];
        yield 'CRLF, written on Windows' => ["\r\n"];
    }

    #[DataProvider('provideGitFilesPointingToNoGitDirectory')]
    public function testAGitFilePointingToNoGitDirectoryThrows(string $content, string $message): void
    {
        $dir = self::createTempDir();
        file_put_contents($dir.'/.git', $content);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage(\sprintf($message, $dir));

        new Repository($dir, driver: self::getDriver());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideGitFilesPointingToNoGitDirectory(): iterable
    {
        $invalid = 'The "%s/.git" file doesn\'t point to a git directory.';

        yield 'another format' => ["ref: refs/heads/master\n", $invalid];
        // Like git, which reads "gitdir: " at the start of the file only.
        yield 'gitdir on the second line' => ["\ngitdir: missing\n", $invalid];
        yield 'no path' => ["gitdir: \n", $invalid];
        yield 'NUL byte' => ["gitdir: a\0b\n", $invalid];
        yield 'missing directory' => ["gitdir: missing\n", 'The git directory "missing" of "%s" does not exist.'];
    }

    public function testAnUnreadableGitFileThrows(): void
    {
        $dir = self::createTempDir();
        file_put_contents($dir.'/.git', "gitdir: missing\n");
        chmod($dir.'/.git', 0);

        try {
            if (is_readable($dir.'/.git')) {
                $this->markTestSkipped('The user of the tests reads any file, like root.');
            }

            $this->expectException(DriverException::class);
            $this->expectExceptionMessage(\sprintf('Unable to read the "%s/.git" file.', $dir));

            new Repository($dir, driver: self::getDriver());
        } finally {
            // Or the file can't be deleted on Windows.
            chmod($dir.'/.git', 0o600);
        }
    }

    public function testOpeningADirectoryThatIsNotARepositoryThrows(): void
    {
        $dir = self::createTempDir();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Directory "%s" is not a git repository.', $dir));

        new Repository($dir, driver: self::getDriver());
    }

    public function testReadsTheHeadOfAReftableRepository(): void
    {
        // libgit2 1.9 doesn't read reftable repositories.
        $this->skipUnlessCliDriver();
        if (version_compare(self::gitVersion(), '2.45.0', '<')) {
            $this->markTestSkipped('Reftable repositories require git 2.45.');
        }

        $dir = self::createTempDir();
        new Process([self::gitCommand(), 'init', '-q', '--ref-format=reftable', '--initial-branch=trunk', $dir])->mustRun();

        $repository = new Repository($dir, driver: self::getDriver());
        $this->assertTrue($repository->isHeadAttached());

        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        self::git($repository, 'commit', ['--allow-empty', '-m', 'First']);

        // The HEAD file of a reftable repository holds "ref: refs/heads/.invalid".
        $this->assertSame('trunk', self::headBranch($repository)->getName());
        $this->assertSame('First', $repository->getHeadCommit()->getSubjectMessage());
    }

    public function testDetachedHead(): void
    {
        $repository = self::createFoobarRepository(false);
        self::git($repository, 'checkout', ['-q', self::LONGFILE_COMMIT]);

        $this->assertTrue($repository->isHeadDetached());
        $this->assertSame(self::LONGFILE_COMMIT, $repository->getHeadCommit()->getHash());
    }

    public function testTheHeadOfARepositoryWithoutCommitsThrows(): void
    {
        $repository = self::createEmptyRepository();

        // HEAD points to a branch without commits yet.
        $this->assertTrue($repository->isHeadAttached());

        try {
            $repository->getHeadCommit();
            $this->fail('A repository without commits has no HEAD commit.');
        } catch (ReferenceNotFoundException) {
        }

        $this->expectException(ReferenceNotFoundException::class);

        $repository->getHead();
    }

    /**
     * gitlib doesn't see the writes of another process, but doesn't fail on them.
     */
    public function testTheHeadAnotherProcessChecksOut(): void
    {
        $repository = self::createFoobarRepository(false);
        $repository->getReferences()->getAll();
        new Process([self::gitCommand(), 'checkout', '-q', '-b', 'elsewhere'], $repository->getWorkingDir(), self::gitEnvs())->mustRun();

        $this->assertSame('refs/heads/elsewhere', self::headBranch($repository)->getFullname());
    }

    public function testHeadPointingToATagIsNoBranch(): void
    {
        $repository = self::createFoobarRepository();
        // Like a branch for git, which commits to the tag.
        self::git($repository, 'symbolic-ref', ['HEAD', 'refs/tags/0.1']);

        $this->assertSame(self::LONGFILE_COMMIT, $repository->getHeadCommit()->getHash());

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('HEAD points to "refs/tags/0.1", which is not a branch.');

        $repository->getHead();
    }

    public function testObjectsAreTheSameWhileUsed(): void
    {
        $repository = self::createFoobarRepository();
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertSame($commit, $repository->getCommit(self::INITIAL_COMMIT));
        $this->assertSame($repository->getBlob('412a0dad051163dcf9f9ce2fc04a141ffcb69479'), $repository->getBlob('412a0dad'), 'An abbreviated hash names the same blob');
    }

    public function testRefusesSha256Repositories(): void
    {
        if (version_compare(self::gitVersion(), '2.29.0', '<')) {
            $this->markTestSkipped('SHA-256 repositories require git 2.29.');
        }

        $dir = self::createTempDir();
        new Process([self::gitCommand(), 'init', '-q', '--object-format=sha256', $dir])->mustRun();

        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('uses SHA-256 hashes');

        new Repository($dir, driver: self::getDriver());
    }

    public function testTimeout(): void
    {
        $repository = self::createFoobarRepository(false);
        $repository->getHooks()->set('pre-commit', "#!/bin/sh\nsleep 10\n");
        $repository = new Repository($repository->getPath(), driver: self::getCliDriver(['GIT_AUTHOR_NAME' => 'A', 'GIT_AUTHOR_EMAIL' => 'a@example.com', 'GIT_COMMITTER_NAME' => 'A', 'GIT_COMMITTER_EMAIL' => 'a@example.com'], processTimeout: 0.5));

        $this->expectException(ProcessTimedOutException::class);
        $this->expectExceptionMessage('The git command "commit" ran longer than 0.5 seconds.');

        $repository->run('commit', ['--allow-empty', '-m', 'Waits for its hook']);
    }

    public function testIsValidRepositoryAndBranchMatchesTheNameExactly(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'update-ref', ['refs/heads/feature/release', self::LONGFILE_COMMIT]);
        $url = $repository->getGitDir();

        $this->assertTrue(Repository::isValidRepositoryAndBranch($url, 'feature/release', self::getDriver()));
        $this->assertFalse(Repository::isValidRepositoryAndBranch($url, 'release', self::getDriver()));
        $this->assertFalse(Repository::isValidRepositoryAndBranch($url, 'rel*', self::getDriver()));
    }

    public function testIsValidRepositoryReadsANameAsAPath(): void
    {
        // A clone, with an "origin" remote.
        $repository = self::createFoobarRepository(false);
        $cwd = getcwd();
        self::assertIsString($cwd);

        chdir($repository->getPath());

        try {
            $this->assertFalse(Repository::isValidRepository('origin', self::getDriver()));
            $this->assertTrue(Repository::isValidRepository('.', self::getDriver()));
        } finally {
            chdir($cwd);
        }
    }

    public function testIsValidRepositoryThrowsWhenGitCantRun(): void
    {
        $this->skipUnlessCliDriver();

        try {
            Repository::isValidRepository(self::repositoryUrl(), new CliDriver('not-git'));
            $this->fail('git is missing.');
        } catch (DriverException $e) {
            // One line, the error of the shell, like "sh: 1: exec: not-git: not found".
            $this->assertMatchesRegularExpression('/^The git command "ls-remote" could not start: [^\n]+\.$/', $e->getMessage());
        }
    }

    public function testCloneATagAsBranchDetachesHead(): void
    {
        $repository = Repository::cloneFrom(self::repositoryUrl(), self::createTempDir().'/clone', 'annotated', self::getDriver());

        // Like git, at the commit of the tag, without any local branch.
        $this->assertTrue($repository->isHeadDetached());
        $this->assertSame($repository->getReferences()->getTag('annotated')->getCommitHash(), $repository->getHeadCommit()->getHash());
        $this->assertSame([], $repository->getReferences()->getLocalBranches());
        $this->assertFileExists($repository->getWorkingDir().'/README.md');
        $this->assertSame([], $repository->getWorkingCopy()->getDiff()->getFiles(), 'The files of the tag are checked out');
        $this->assertFileDoesNotExist($repository->getGitDir().'/FETCH_HEAD');
    }

    public function testCloneBareATagAsBranchDetachesHead(): void
    {
        $repository = Repository::cloneBareFrom(self::repositoryUrl(), self::createTempDir(), '0.1', self::getDriver());

        $this->assertTrue($repository->isHeadDetached());
        $this->assertSame(self::LONGFILE_COMMIT, $repository->getHeadCommit()->getHash());
        $this->assertFileDoesNotExist($repository->getGitDir().'/FETCH_HEAD');
    }

    public function testCloneOfAMissingBranchThrows(): void
    {
        $this->expectException(DriverException::class);

        Repository::cloneFrom(self::repositoryUrl(), self::createTempDir().'/clone', 'missing', self::getDriver());
    }

    public function testClonesWriteNoFetchHead(): void
    {
        $dir = self::createTempDir();

        $this->assertFileDoesNotExist(Repository::cloneFrom(self::repositoryUrl(), $dir.'/clone', driver: self::getDriver())->getGitDir().'/FETCH_HEAD');
        $this->assertFileDoesNotExist(Repository::cloneBareFrom(self::repositoryUrl(), $dir.'/bare', driver: self::getDriver())->getGitDir().'/FETCH_HEAD');
        $this->assertFileDoesNotExist(Repository::mirrorFrom(self::repositoryUrl(), $dir.'/mirror', self::getDriver())->getGitDir().'/FETCH_HEAD');
    }

    public function testReadingAMovedRepositoryThrows(): void
    {
        $dir = self::createTempDir();
        $repository = Repository::cloneBareFrom(self::repositoryUrl(), $dir.'/repository', driver: self::getDriver());
        rename($dir.'/repository', $dir.'/moved');

        $this->expectException(DriverException::class);

        $repository->getReferences()->getBranches();
    }

    public function testAnObjectOfAnotherTypeThrows(): void
    {
        $repository = self::createFoobarRepository();
        $treeHash = $repository->getCommit(self::LONGFILE_COMMIT)->getTree()->getHash();

        foreach ([
            'blob' => static fn () => $repository->getBlob(self::LONGFILE_COMMIT)->getContent(),
            'tree' => static fn () => $repository->getTree(self::LONGFILE_COMMIT)->getEntries(),
            'commit' => static fn () => $repository->getCommit($treeHash)->getMessage(),
        ] as $type => $read) {
            try {
                $read();
                $this->fail(\sprintf('A %s of another type is refused.', $type));
            } catch (ReferenceNotFoundException $e) {
                $this->assertMatchesRegularExpression(\sprintf('/^The object "[0-9a-f]{40}" is not a %s\.$/', $type), $e->getMessage());
            }
        }
    }

    public function testCloneRefusesADirectoryWithFiles(): void
    {
        $dir = self::createTempDir();
        touch($dir.'/file');

        $this->expectException(DriverException::class);

        Repository::cloneBareFrom(self::repositoryUrl(), $dir, driver: self::getDriver());
    }

    public function testFailedCloneLeavesNothing(): void
    {
        $dir = self::createTempDir().'/clone';

        try {
            Repository::cloneBareFrom(self::repositoryUrl(), $dir, 'unknown', self::getDriver());
            $this->fail('The clone should have failed.');
        } catch (DriverException) {
        }

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testMirrorDoesNotFetchTagsTwice(): void
    {
        if (!self::getDriver() instanceof LibGit2Driver) {
            $this->markTestSkipped('The configuration CliDriver writes depends on the version of git.');
        }

        $mirror = Repository::mirrorFrom(self::repositoryUrl(), self::createTempDir(), self::getDriver());

        $this->assertSame('--no-tags', trim(self::git($mirror, 'config', ['remote.origin.tagopt'])));
    }

    public function testGetMergeBase(): void
    {
        $repository = self::createFoobarRepository();

        $this->assertSame(self::LONGFILE_COMMIT, $repository->getMergeBase(self::LONGFILE_COMMIT, 'master')?->getHash());
    }

    public function testAnAbbreviatedHashNamesAnObjectOfTheRightType(): void
    {
        $repository = self::createFoobarRepository();
        $hash = substr($repository->getCommit(self::LONGFILE_COMMIT)->getTreeHash(), 0, 8);

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('The object "%s" is not a blob.', $hash));

        $repository->getBlob($hash);
    }

    public function testAnAbbreviatedHashOfNoObject(): void
    {
        $repository = self::createFoobarRepository();

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The object "12345678" does not exist.');

        $repository->getTree('12345678');
    }

    public function testAnAbbreviatedCommitHashNamesNoTree(): void
    {
        $repository = self::createFoobarRepository();
        $hash = substr(self::LONGFILE_COMMIT, 0, 8);

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('The object "%s" is not a tree.', $hash));

        $repository->getTree($hash);
    }

    public function testInitOnAnExistingFileFails(): void
    {
        $file = self::createTempDir().'/test';
        touch($file);

        $this->expectException(DriverException::class);
        // git and libgit2 name the file they can't make a directory of.
        $this->expectExceptionMessage($file);

        Repository::initBare($file, driver: self::getDriver());
    }

    public function testCliDriverLogsTheCommandsCreatingRepositories(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringStartsWith('run command: init "-q --bare '))
        ;

        Repository::initBare(self::createTempDir(), self::getCliDriver(logger: $logger));
    }

    public function testCloneBareFrom(): void
    {
        $repository = self::createFoobarRepository(true);

        $newDir = self::createTempDir();
        $new = Repository::cloneBareFrom($repository->getGitDir(), $newDir, driver: self::getDriver());

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);
        $this->assertSame($newDir, $new->getGitDir());
        $this->assertContains('refs/heads/new-feature', $newRefs);
        // Like `git clone --bare`: the remote has no fetch refspec.
        $this->assertStringNotContainsString('fetch', (string) file_get_contents($newDir.'/config'));
    }

    public function testCloneFrom(): void
    {
        $repository = self::createFoobarRepository(false);

        $newDir = self::createTempDir();
        $new = Repository::cloneFrom($repository->getGitDir(), $newDir, driver: self::getDriver());

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);
        $this->assertSame($newDir.'/.git', $new->getGitDir());
        $this->assertSame($newDir, $new->getWorkingDir());
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
        $this->assertSame('new-feature', $head->getName(), 'HEAD is branch new-feature');
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
        $this->assertSame('new-feature', $head->getName(), 'HEAD is branch new-feature');
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
        $this->assertSame($newDir, $new->getGitDir());

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
    public function testGetSizeInBytes(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $size = $repository->getSizeInBytes();
        // The exact figure depends on git's packing/compression, which varies across versions.
        $this->assertGreaterThanOrEqual(40_000, $size, 'Repository is at least 40KB');
        $this->assertLessThan(84_000, $size, 'Repository is less than 84KB');
    }

    public function testGetSizeInBytesFollowsNoLink(): void
    {
        $repository = self::createEmptyRepository();
        $size = $repository->getSizeInBytes();

        file_put_contents($repository->getGitDir().'/file', str_repeat('a', 1000));
        // Like a hook linking to a file not deployed yet.
        symlink($repository->getGitDir().'/missing', $repository->getGitDir().'/link');

        $this->assertSame($size + 1000, $repository->getSizeInBytes());
    }

    public function testGetSizeInBytesOfAnUnreadableDirectoryThrows(): void
    {
        $repository = self::createEmptyRepository();
        $dir = $repository->getGitDir().'/unreadable';
        mkdir($dir);
        chmod($dir, 0);

        try {
            if (is_readable($dir)) {
                $this->markTestSkipped('The user of the tests reads any directory, like root.');
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(\sprintf('Unable to read the directory "%s".', $dir));

            $repository->getSizeInBytes();
        } finally {
            // Or the directory can't be deleted.
            chmod($dir, 0o700);
        }
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetSizeInKilobytes(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Repository::getSize()" method is deprecated, use "getSizeInBytes()" instead.');

        $repository = self::createFoobarRepository();

        $this->assertSame((int) round($repository->getSizeInBytes() / 1000), $repository->getSize());
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('run command: remote "" ')
        ;
        $logger->expects($this->exactly(3)) // duration, return code and output
            ->method('debug')
            ->with($this->isString())
        ;

        $repository = new Repository(self::createFoobarRepository($bare)->getPath(), driver: self::getCliDriver(logger: $logger));

        $repository->run('remote');
    }

    #[DataProvider('provideBare')]
    public function testLoggerNOk(bool $bare): void
    {
        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('The git command "not-work" failed (exit 1): git: \'not-work\' is not a git command. See \'git --help\'.');

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

        $repository = new Repository(self::createFoobarRepository($bare)->getPath(), driver: self::getCliDriver(logger: $logger));

        $repository->run('not-work');
    }

    /**
     * @see https://github.com/gitonomy/gitlib/issues/67
     */
    public function testRunResolvesRelativePathsAgainstTheRepositoryRegardlessOfCwd(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(false);

        $patchFile = tempnam(sys_get_temp_dir(), 'gitlib_patch_');
        file_put_contents($patchFile, <<<'PATCH'
            diff --git a/applied.txt b/applied.txt
            new file mode 100644
            --- /dev/null
            +++ b/applied.txt
            @@ -0,0 +1 @@
            +Applied line.

            PATCH);

        $previousCwd = getcwd();
        $this->assertIsString($previousCwd);
        chdir(sys_get_temp_dir());

        try {
            // "applied.txt" is relative to the repository work-tree, not to the
            // process cwd (which is an unrelated directory here). This only
            // works if the git process is run with its cwd set to the repository.
            $repository->run('apply', [$patchFile]);
        } finally {
            chdir($previousCwd);
            unlink($patchFile);
        }

        $this->assertSame("Applied line.\n", file_get_contents($repository->getWorkingDir().'/applied.txt'));
    }

    public function testRunIsOnlySupportedByCliDriver(): void
    {
        $repository = new Repository(self::createTempDir(), driver: $this->createStub(DriverInterface::class));

        $this->expectException(UnsupportedOperationException::class);

        $repository->run('status');
    }

    public function testDriverOpensTheRepository(): void
    {
        $dir = self::createTempDir();
        $repositoryDriver = $this->createStub(RepositoryDriverInterface::class);
        $repositoryDriver->method('getReferences')->willReturn([new ReferenceData('refs/heads/main', self::INITIAL_COMMIT)]);

        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())->method('open')->with($dir, '/elsewhere')->willReturn($repositoryDriver);

        $repository = new Repository($dir, '/elsewhere', $driver);

        $this->assertSame(self::INITIAL_COMMIT, $repository->getReferences()->getBranch('main')->getCommitHash());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacySetLogger(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Repository::setLogger()" method is deprecated, pass a "Gitonomy\Git\Driver\Cli\CliDriver" configured with the logger as driver argument instead.');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('run command: remote "" ')
        ;

        $repository = new Repository(self::createFoobarRepository()->getPath(), driver: self::getCliDriver());
        $repository->setLogger($logger);
        $repository->run('remote');

        $this->assertSame($logger, $repository->getLogger());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacySetLoggerRequiresCliDriver(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        new Repository(self::createTempDir(), driver: $this->createStub(DriverInterface::class))->setLogger($this->createStub(LoggerInterface::class));
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testUnknownOptionsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown options "foo", "bar".');

        new Repository(self::createTempDir(), ['foo' => 1, 'bar' => 2]);
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyOptionsConfigureACliDriver(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Passing an array of options to "Gitonomy\Git\Repository::__construct()" is deprecated, pass the working directory as "workingDir" argument, and configure the logger and git through the "driver" argument.');

        $logger = $this->createStub(LoggerInterface::class);
        $repository = new Repository(self::createFoobarRepository()->getGitDir(), [
            'command' => self::gitCommand(),
            'logger' => $logger,
            'process_timeout' => 42,
        ]);

        $this->assertSame($logger, $repository->getLogger());
        $this->assertSame(42.0, $repository->runProcess('rev-parse', ['HEAD'])->getTimeout());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyOptionsCantBeCombinedWithADriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The driver argument can\'t be combined with the "logger" or git options: configure the driver instead.');

        new Repository(self::createTempDir(), ['process_timeout' => 60], self::getDriver());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyDebugOption(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Passing an array of options to "Gitonomy\Git\Repository::__construct()" is deprecated, pass the working directory as "workingDir" argument, and configure the logger and git through the "driver" argument.');

        $repository = new Repository(self::createFoobarRepository()->getGitDir(), ['debug' => true], self::getDriver());

        $this->assertSame(self::LONGFILE_COMMIT, $repository->getCommit(self::LONGFILE_COMMIT)->getHash());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testDebugOptionSetToFalseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "debug" option set to false is not supported anymore: a failing git operation always throws, catch its exception instead.');

        new Repository(self::createTempDir(), ['debug' => false]);
    }

    /**
     * @return array{string, string} The working tree of the clone, and its git directory
     */
    private static function cloneWithASeparateGitDir(Repository $source): array
    {
        $dir = self::createTempDir().'/clone';
        $gitDir = self::createTempDir().'/git';
        new Process([self::gitCommand(), 'clone', '-q', '--separate-git-dir='.$gitDir, $source->getGitDir(), $dir], null, self::gitEnvs())->mustRun();

        return [$dir, $gitDir];
    }
}
