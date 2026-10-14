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

use Gitonomy\Git\Admin;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Reference\Branch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Psr\Log\LoggerInterface;

/**
 * Admin is deprecated: its methods must keep behaving like in 1.x, always
 * with CliDriver, whatever the driver under test.
 */
#[Group('legacy')]
#[IgnoreDeprecations]
class AdminTest extends AbstractTestCase
{
    public function testInit(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::init()" method is deprecated, use "Gitonomy\Git\Repository::initBare()" instead.');

        $repository = Admin::init(self::createTempDir(), options: self::getOptions());

        $this->assertTrue($repository->isBare());
    }

    public function testInitNotBare(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::init()" method is deprecated, use "Gitonomy\Git\Repository::init()" instead.');

        $repository = Admin::init(self::createTempDir(), false, self::getOptions());

        $this->assertFalse($repository->isBare());
    }

    public function testIsValidRepository(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::isValidRepository()" method is deprecated, use "Gitonomy\Git\Repository::isValidRepository()" instead.');

        $this->assertTrue(Admin::isValidRepository(self::repositoryUrl(), self::getOptions()));
    }

    public function testIsValidRepositoryAndBranch(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::isValidRepositoryAndBranch()" method is deprecated, use "Gitonomy\Git\Repository::isValidRepositoryAndBranch()" instead.');

        $this->assertTrue(Admin::isValidRepositoryAndBranch(self::repositoryUrl(), 'new-feature', self::getOptions()));
        $this->assertFalse(Admin::isValidRepositoryAndBranch(self::repositoryUrl(), 'unknown', self::getOptions()));
    }

    public function testCloneTo(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::cloneTo()" method is deprecated, use "Gitonomy\Git\Repository::cloneBareFrom()" instead, which takes the URL before the path.');

        $repository = Admin::cloneTo(self::createTempDir(), self::repositoryUrl(), options: self::getOptions());

        $this->assertTrue($repository->isBare());
        $this->assertContains('refs/heads/new-feature', array_keys($repository->getReferences()->getAll()));
    }

    public function testCloneToNotBare(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::cloneTo()" method is deprecated, use "Gitonomy\Git\Repository::cloneFrom()" instead, which takes the URL before the path.');

        $repository = Admin::cloneTo(self::createTempDir(), self::repositoryUrl(), false, self::getOptions());

        $this->assertFalse($repository->isBare());
    }

    public function testCloneBranchTo(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::cloneBranchTo()" method is deprecated, use "Gitonomy\Git\Repository::cloneBareFrom()" with its "branch" argument instead, which takes the URL before the path.');

        $repository = Admin::cloneBranchTo(self::createTempDir(), self::repositoryUrl(), 'new-feature', options: self::getOptions());

        $this->assertTrue($repository->isBare());
        $head = $repository->getHead();
        $this->assertInstanceOf(Branch::class, $head);
        $this->assertSame('new-feature', $head->getName());
    }

    public function testCloneBranchToNotBare(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::cloneBranchTo()" method is deprecated, use "Gitonomy\Git\Repository::cloneFrom()" with its "branch" argument instead, which takes the URL before the path.');

        $repository = Admin::cloneBranchTo(self::createTempDir(), self::repositoryUrl(), 'new-feature', false, self::getOptions());

        $this->assertFalse($repository->isBare());
        $head = $repository->getHead();
        $this->assertInstanceOf(Branch::class, $head);
        $this->assertSame('new-feature', $head->getName());
    }

    public function testMirrorTo(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::mirrorTo()" method is deprecated, use "Gitonomy\Git\Repository::mirrorFrom()" instead, which takes the URL before the path.');

        $repository = Admin::mirrorTo(self::createTempDir(), self::repositoryUrl(), self::getOptions());

        $this->assertSame('true', trim($repository->run('config', ['remote.origin.mirror'])));
    }

    public function testCloneRepositoryPassesItsArgumentsToGit(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Admin::cloneRepository()" method is deprecated, use "Gitonomy\Git\Repository::cloneFrom()", "Gitonomy\Git\Repository::cloneBareFrom()" or "Gitonomy\Git\Repository::mirrorFrom()" instead, which take the URL before the path; they support no other argument of "git clone".');

        // --config is the only argument --bare accepts on every git version.
        $repository = Admin::cloneRepository(self::createTempDir(), self::repositoryUrl(), ['--bare', '--config', 'gitlib.test=yes'], self::getOptions());

        $this->assertTrue($repository->isBare());
        $this->assertSame('yes', trim($repository->run('config', ['gitlib.test'])));
    }

    public function testOptionsConfigureGitAndTheRepository(): void
    {
        $logger = $this->createStub(LoggerInterface::class);

        $repository = Admin::init(self::createTempDir(), options: [...self::getOptions(), 'logger' => $logger, 'process_timeout' => 42]);

        $this->assertSame($logger, $repository->getLogger());
        $this->assertSame(42.0, $repository->runProcess('config', ['core.bare'])->getTimeout());
    }

    public function testCommandOptionRunsAnotherGitBinary(): void
    {
        $this->expectException(ProcessException::class);

        Admin::init(self::createTempDir(), options: ['command' => 'not-git']);
    }

    public function testInheritEnvironmentVariablesOnlyAppliesToTheRepository(): void
    {
        if (\defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $this->markTestSkipped('The fake git binary is a shell script.');
        }

        // A git binary logging its first argument, and whether it gets SECRET.
        $dir = self::createTempDir();
        file_put_contents($dir.'/git', \sprintf("#!/bin/sh\necho \"\$1 \${GITLIB_TEST_SECRET:-none}\" >> %s\nexec %s \"\$@\"\n", escapeshellarg($dir.'/log'), escapeshellarg(self::gitCommand())));
        chmod($dir.'/git', 0o755);
        // Like the credentials of an HTTP request, in $_SERVER only.
        $_SERVER['GITLIB_TEST_SECRET'] = 'secret';

        try {
            $repository = Admin::init($dir.'/repository', options: [...self::getOptions(), 'command' => $dir.'/git', 'inherit_environment_variables' => true]);
            $repository->run('config', ['core.bare']);
        } finally {
            unset($_SERVER['GITLIB_TEST_SECRET']);
        }

        // Like in 1.x, git init doesn't get $_SERVER, the git processes of the repository do.
        $this->assertSame("init none\n--git-dir secret\n", file_get_contents($dir.'/log'));
    }

    public function testRemovedOptionsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "debug" option set to false is not supported anymore: a failing git operation always throws, catch its exception instead.');

        Admin::init(self::createTempDir(), options: ['debug' => false]);
    }

    public function testRepositoryCloneTo(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Repository::cloneTo()" method is deprecated, use "Gitonomy\Git\Repository::cloneBareFrom()" with "getGitDir()" as URL instead.');

        $repository = self::createFoobarRepository();
        $new = $repository->cloneTo(self::createTempDir());

        $this->assertTrue($new->isBare());
        $this->assertSame($repository->getHeadCommit()->getHash(), $new->getHeadCommit()->getHash());
    }

    public function testRepositoryCloneToNotBare(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Repository::cloneTo()" method is deprecated, use "Gitonomy\Git\Repository::cloneFrom()" with "getGitDir()" as URL instead.');

        $repository = self::createFoobarRepository();
        $new = $repository->cloneTo(self::createTempDir(), false);

        $this->assertFalse($new->isBare());
        $this->assertSame($repository->getHeadCommit()->getHash(), $new->getHeadCommit()->getHash());
    }

    public function testRepositoryCloneToWithGitOptions(): void
    {
        $repository = self::createFoobarRepository();
        $new = $repository->cloneTo(self::createTempDir(), options: [...self::getOptions(), 'process_timeout' => 42]);

        $this->assertSame(42.0, $new->runProcess('config', ['core.bare'])->getTimeout());
    }

    /**
     * The options of 1.x running the git binary of the tests.
     *
     * @return array{command: string, environment_variables: array<string, string>}
     */
    private static function getOptions(): array
    {
        return [
            'command' => self::gitCommand(),
            'environment_variables' => self::gitEnvs(),
        ];
    }
}
