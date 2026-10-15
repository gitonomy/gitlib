<?php

/**
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
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class RepositoryTest extends AbstractTest
{
    use ExpectDeprecationTrait;
    use ProphecyTrait;

    public function testInit()
    {
        $dir = self::createTempDir();
        $repository = Repository::init($dir, self::getDriver());
        self::registerDeletion($repository);

        $this->assertFalse($repository->isBare());
        $this->assertSame($dir.'/.git', $repository->getGitDir());
        $this->assertSame($dir, $repository->getWorkingDir());
    }

    public function testInitBare()
    {
        $dir = self::createTempDir();
        $repository = Repository::initBare($dir, self::getDriver());
        self::registerDeletion($repository);

        $this->assertTrue($repository->isBare());
        $this->assertSame($dir, $repository->getGitDir());
        $this->assertDirectoryExists($dir.'/objects');
    }

    public function testInitThrowsForAFile()
    {
        $file = self::createTempDir().'/file';
        touch($file);

        $this->expectException(RuntimeException::class);

        Repository::initBare($file, self::getDriver());
    }

    public function testCloneFrom()
    {
        $dir = self::createTempDir();
        $repository = Repository::cloneFrom(self::createFoobarRepository()->getGitDir(), $dir, null, self::getDriver());
        self::registerDeletion($repository);

        $this->assertFalse($repository->isBare());
        $this->assertSame($dir, $repository->getWorkingDir());
        $this->assertSame('master', $repository->getHead()->getName());
        $this->assertTrue($repository->getReferences()->hasRemoteBranch('origin/new-feature'));
    }

    public function testCloneFromABranch()
    {
        $repository = Repository::cloneFrom(self::createFoobarRepository()->getGitDir(), self::createTempDir(), 'new-feature', self::getDriver());
        self::registerDeletion($repository);

        $this->assertSame('new-feature', $repository->getHead()->getName());
    }

    public function testCloneBareFrom()
    {
        $dir = self::createTempDir();
        $repository = Repository::cloneBareFrom(self::createFoobarRepository()->getGitDir(), $dir, null, self::getDriver());
        self::registerDeletion($repository);

        $this->assertTrue($repository->isBare());
        $this->assertSame($dir, $repository->getGitDir());
        $this->assertSame('master', $repository->getHead()->getName());
        $this->assertTrue($repository->getReferences()->hasBranch('new-feature'));
    }

    public function testCloneBareFromABranch()
    {
        $repository = Repository::cloneBareFrom(self::createFoobarRepository()->getGitDir(), self::createTempDir(), 'new-feature', self::getDriver());
        self::registerDeletion($repository);

        $head = $repository->getHead();
        $this->assertInstanceOf(Branch::class, $head);
        $this->assertSame('new-feature', $head->getName());
    }

    public function testCloneFromThrowsForAMissingRepository()
    {
        $this->expectException(RuntimeException::class);

        Repository::cloneFrom(self::createTempDir().'/missing', self::createTempDir(), null, self::getDriver());
    }

    public function testMirrorFrom()
    {
        $source = self::createFoobarRepository(false);
        $dir = self::createTempDir();
        $repository = Repository::mirrorFrom($source->getGitDir(), $dir, self::getDriver());
        self::registerDeletion($repository);

        $this->assertTrue($repository->isBare());
        $this->assertSame($dir, $repository->getGitDir());
        // A mirror keeps the remote branches of its source, which a bare clone leaves out.
        $this->assertTrue($repository->getReferences()->has('refs/remotes/origin/new-feature'));
    }

    public function testIsValidRepository()
    {
        $this->assertTrue(Repository::isValidRepository(self::createFoobarRepository()->getGitDir(), self::getDriver()));
        $this->assertFalse(Repository::isValidRepository(self::createTempDir(), self::getDriver()));
    }

    public function testIsValidRepositoryAndBranch()
    {
        $url = self::createFoobarRepository()->getGitDir();

        $this->assertTrue(Repository::isValidRepositoryAndBranch($url, 'new-feature', self::getDriver()));
        // The exact name, where Admin::isValidRepositoryAndBranch() matches "new-feature" too.
        $this->assertFalse(Repository::isValidRepositoryAndBranch($url, 'feature', self::getDriver()));
        $this->assertFalse(Repository::isValidRepositoryAndBranch($url, 'unknown', self::getDriver()));
        $this->assertFalse(Repository::isValidRepositoryAndBranch(self::createTempDir(), 'master', self::getDriver()));
    }

    public function testWorkingDirArgument()
    {
        $source = self::createFoobarRepository(false);

        $repository = new Repository($source->getGitDir(), $source->getWorkingDir(), self::getDriver());

        $this->assertFalse($repository->isBare());
        $this->assertSame($source->getGitDir(), $repository->getGitDir());
        $this->assertSame($source->getWorkingDir(), $repository->getWorkingDir());
    }

    public function testDriverConfiguresGit()
    {
        $driver = new CliDriver('git', ['GIT_CONFIG_PARAMETERS' => "'gitlib.test=yes'"]);
        $repository = new Repository(self::createFoobarRepository()->getGitDir(), null, $driver);

        $this->assertSame("yes\n", $repository->run('config', ['gitlib.test']));
    }

    public function testDriverCommand()
    {
        $repository = new Repository(self::createFoobarRepository()->getGitDir(), null, new CliDriver('not-git'));

        $this->expectException(ProcessException::class);

        $repository->run('status');
    }

    /**
     * @group legacy
     */
    public function testOptionsAreDeprecated()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing an array of options to "Gitonomy\Git\Repository::__construct()" is deprecated, pass the working directory as second argument, and configure the logger and git through the "driver" argument.');

        $source = self::createFoobarRepository(false);
        $repository = new Repository($source->getGitDir(), ['working_dir' => $source->getWorkingDir()] + self::getOptions());

        $this->assertSame($source->getWorkingDir(), $repository->getWorkingDir());
    }

    /**
     * @group legacy
     */
    public function testDebugOptionSetToFalseIsDeprecated()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Setting the "debug" option of "Gitonomy\Git\Repository::__construct()" to false is deprecated, a failing git operation always throws in 2.0: catch its exception instead.');

        $repository = new Repository(self::createFoobarRepository()->getGitDir(), ['debug' => false] + self::getOptions());

        $this->assertNull($repository->run('not-a-command'));
    }

    /**
     * @group legacy
     */
    public function testGitOptionsCannotBeCombinedWithTheDriver()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The driver argument can\'t be combined with the "logger" or git options: configure the driver instead.');

        new Repository(self::createFoobarRepository()->getGitDir(), ['process_timeout' => 60], self::getDriver());
    }

    /**
     * @group legacy
     */
    public function testOtherOptionsCombineWithTheDriver()
    {
        $source = self::createFoobarRepository(false);
        $driver = new CliDriver('git', ['GIT_CONFIG_PARAMETERS' => "'gitlib.test=yes'"]);

        $repository = new Repository($source->getGitDir(), ['working_dir' => $source->getWorkingDir()], $driver);

        $this->assertSame($source->getWorkingDir(), $repository->getWorkingDir());
        $this->assertSame("yes\n", $repository->run('config', ['gitlib.test']));
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetMergeBase($repository)
    {
        $mergeBase = $repository->getMergeBase('master', $repository->getRevision(self::INITIAL_COMMIT));

        $this->assertSame(self::INITIAL_COMMIT, $mergeBase->getHash());
    }

    public function testGetMergeBaseOfUnrelatedHistories()
    {
        $repository = self::createFoobarRepository();
        $driver = new CliDriver('git', ['GIT_AUTHOR_NAME' => 'Unit Test', 'GIT_AUTHOR_EMAIL' => 'unit@example.com', 'GIT_COMMITTER_NAME' => 'Unit Test', 'GIT_COMMITTER_EMAIL' => 'unit@example.com']);
        // A root commit of the empty tree, which every repository has.
        $unrelated = trim((new Repository($repository->getGitDir(), null, $driver))->run('commit-tree', ['4b825dc642cb6eb9a060e54bf8d69288fbee4904', '-m', 'Unrelated']));

        $this->assertNull($repository->getMergeBase('master', $unrelated));
    }

    public function testGetMergeBaseThrowsForAnUnknownRevision()
    {
        $this->expectException(ReferenceNotFoundException::class);

        self::createFoobarRepository()->getMergeBase('master', 'unknown');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetBlobWithExistingWorks($repository)
    {
        $blob = $repository->getCommit(self::LONGFILE_COMMIT)->getTree()->resolvePath('README.md');

        $this->assertInstanceOf(Blob::class, $blob, 'getBlob() returns a Blob object');

        if (method_exists($this, 'assertStringContainsString')) {
            $this->assertStringContainsString('Foo Bar project', $blob->getContent(), 'file is correct');
        } else {
            $this->assertContains('Foo Bar project', $blob->getContent(), 'file is correct');
        }
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testGetSize($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Repository::getSize()" method is deprecated, use "getSizeInBytes()" instead.');

        $size = $repository->getSize();
        $this->assertGreaterThanOrEqual(57, $size, 'Repository is at least 57KB');
        $this->assertLessThan(84, $size, 'Repository is less than 84KB');
        $this->assertSame((int) round($repository->getSizeInBytes() / 1000), $size);
    }

    public function testGetSizeInBytesDoesNotFollowLinks()
    {
        $repository = self::createEmptyRepository();
        $size = $repository->getSizeInBytes();

        $this->assertGreaterThan(0, $size);

        symlink($repository->getGitDir().'/missing', $repository->getGitDir().'/hooks/pre-commit');
        symlink($repository->getGitDir().'/config', $repository->getGitDir().'/hooks/post-commit');

        $this->assertSame($size, $repository->getSizeInBytes());
    }

    public function testIsBare()
    {
        $bare = self::createFoobarRepository(true);
        $this->assertTrue($bare->isBare(), 'Lib repository is bare');

        $notBare = self::createFoobarRepository(false);
        $this->assertFalse($notBare->isBare(), 'Working copy is not bare');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetDescription($repository)
    {
        $this->assertSame("Unnamed repository; edit this file 'description' to name the repository.\n", $repository->getDescription());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testLoggerOk($repository)
    {
        if (!interface_exists('Psr\Log\LoggerInterface')) {
            $this->markTestSkipped();
        }

        $loggerProphecy = $this->prophesize('Psr\Log\LoggerInterface');
        $loggerProphecy
            ->info('run command: remote "" ')
            ->shouldBeCalledTimes(1);
        $loggerProphecy
            ->debug(Argument::type('string')) // creation of the repository, duration, return code and output
            ->shouldBeCalledTimes(4);

        $repository = new Repository($repository->getGitDir(), $repository->getWorkingDir(), new CliDriver('git', [], 60, $loggerProphecy->reveal()));

        $repository->run('remote');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testLoggerNOk($repository)
    {
        if (!interface_exists('Psr\Log\LoggerInterface')) {
            $this->markTestSkipped();
        }

        $this->expectException(RuntimeException::class);

        $loggerProphecy = $this->prophesize('Psr\Log\LoggerInterface');
        $loggerProphecy
            ->info(Argument::type('string'))
            ->shouldBeCalledTimes(1);
        $loggerProphecy
            ->debug(Argument::type('string')) // creation of the repository, duration, return code and output
            ->shouldBeCalledTimes(4);
        $loggerProphecy
            ->error(Argument::type('string'))
            ->shouldBeCalledTimes(1);

        $repository = new Repository($repository->getGitDir(), $repository->getWorkingDir(), new CliDriver('git', [], 60, $loggerProphecy->reveal()));

        $repository->run('not-work');
    }

    /**
     * @group legacy
     */
    public function testSetLoggerIsDeprecated()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Repository::setLogger()" method is deprecated, pass a "Gitonomy\Git\Driver\Cli\CliDriver" configured with the logger as driver argument instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Repository::getLogger()" method is deprecated, the logger is an argument of "Gitonomy\Git\Driver\Cli\CliDriver".');

        $repository = self::createFoobarRepository();
        $logger = $this->prophesize(LoggerInterface::class)->reveal();
        $repository->setLogger($logger);

        $this->assertSame($logger, $repository->getLogger());
    }

    public function testGetMergeBaseOfUnrelatedHistoriesLogsNoError()
    {
        $repository = self::createFoobarRepository();
        $driver = new CliDriver('git', ['GIT_AUTHOR_NAME' => 'Unit Test', 'GIT_AUTHOR_EMAIL' => 'unit@example.com', 'GIT_COMMITTER_NAME' => 'Unit Test', 'GIT_COMMITTER_EMAIL' => 'unit@example.com']);
        $unrelated = trim((new Repository($repository->getGitDir(), null, $driver))->run('commit-tree', ['4b825dc642cb6eb9a060e54bf8d69288fbee4904', '-m', 'Unrelated']));

        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error(Argument::any())->shouldNotBeCalled();
        $loggerProphecy->info(Argument::any())->shouldBeCalled();
        $loggerProphecy->debug(Argument::any())->shouldBeCalled();

        $repository = new Repository($repository->getGitDir(), null, new CliDriver('git', [], 60, $loggerProphecy->reveal()));

        $this->assertNull($repository->getMergeBase('master', $unrelated));
    }

    public function testIsValidRepositoryReadsARelativePathAsAPath()
    {
        $repository = self::createFoobarRepository(false);
        $cwd = getcwd();

        try {
            // A repository, whose remote "origin" git would read in its working directory.
            chdir($repository->getWorkingDir());
            $this->assertFalse(Repository::isValidRepository('origin', self::getDriver()));
            $this->assertFalse(Repository::isValidRepositoryAndBranch('origin', 'master', self::getDriver()));

            chdir(dirname($repository->getWorkingDir()));
            $this->assertTrue(Repository::isValidRepository(basename($repository->getWorkingDir()), self::getDriver()));
        } finally {
            chdir($cwd);
        }
    }

    /**
     * @group legacy
     */
    public function testGetObjectsByName()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing "master^{tree}", which is not a hash, to "Gitonomy\\Git\\Repository::getTree()" is deprecated, it throws in 2.0: use "getRevision($name)->getCommit()->getTree()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing "master:README.md", which is not a hash, to "Gitonomy\\Git\\Repository::getBlob()" is deprecated, it throws in 2.0: use "Tree::resolvePath()" instead.');

        $repository = self::createFoobarRepository();

        $this->assertArrayHasKey('README.md', iterator_to_array($repository->getTree('master^{tree}')));
        $this->assertStringContainsString('Foo Bar project', $repository->getBlob('master:README.md')->getContent());
    }

    /**
     * @group legacy
     */
    public function testGetReferencesReadsThemAgain()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing an argument to "Gitonomy\\Git\\Repository::getReferences()" is deprecated, open a new Repository to read the references again.');

        $repository = self::createFoobarRepository();
        $this->assertFalse($repository->getReferences()->hasBranch('foobar'));

        $repository->run('branch', ['foobar', self::INITIAL_COMMIT]);

        $this->assertFalse($repository->getReferences()->hasBranch('foobar'));
        $this->assertTrue($repository->getReferences(true)->hasBranch('foobar'));
    }
}
