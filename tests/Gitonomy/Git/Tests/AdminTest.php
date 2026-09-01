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

use Gitonomy\Git\Admin;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminTest extends AbstractTestCase
{
    private string $tmpDir;

    #[Before]
    public function setUpTmpDir(): void
    {
        $this->tmpDir = self::createTempDir();
    }

    #[After]
    public function tearDownTmpDir(): void
    {
        self::deleteDir(self::createTempDir());
    }

    public function testBare(): void
    {
        $repository = Admin::init($this->tmpDir, true, self::getOptions());

        $objectDir = $this->tmpDir.'/objects';

        $this->assertTrue($repository->isBare(), 'Repository is bare');
        $this->assertDirectoryExists($objectDir, 'objects/ folder is present');
        $this->assertInstanceOf(Repository::class, $repository, 'Admin::init returns a repository');
        $this->assertEquals($this->tmpDir, $repository->getGitDir(), 'The folder passed as argument is git dir');
        $this->assertNull($repository->getWorkingDir(), 'No working dir in bare repository');
    }

    public function testNotBare(): void
    {
        $repository = Admin::init($this->tmpDir, false, self::getOptions());

        $objectDir = $this->tmpDir.'/.git/objects';

        $this->assertFalse($repository->isBare(), 'Repository is not bare');
        $this->assertDirectoryExists($objectDir, 'objects/ folder is present');
        $this->assertInstanceOf(Repository::class, $repository, 'Admin::init returns a repository');
        $this->assertEquals($this->tmpDir.'/.git', $repository->getGitDir(), 'git dir as subfolder of argument');
        $this->assertEquals($this->tmpDir, $repository->getWorkingDir(), 'working dir present in bare repository');
    }

    #[DataProvider('provideFoobar')]
    public function testClone(Repository $repository): void
    {
        $newDir = self::createTempDir();
        $new = $repository->cloneTo($newDir, $repository->isBare(), self::getOptions());
        self::registerDeletion($new);

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);

        if ($repository->isBare()) {
            $this->assertEquals($newDir, $new->getGitDir());
            $this->assertContains('refs/heads/new-feature', $newRefs);
        } else {
            $this->assertEquals($newDir.'/.git', $new->getGitDir());
            $this->assertEquals($newDir, $new->getWorkingDir());
        }
    }

    public function testCloneBranchBare(): void
    {
        // we can't use AbstractTestCase::createFoobarRepository()
        // because it does not clone other branches than "master"
        // so we test it directly against the remote repository

        $newDir = self::createTempDir();
        $new = Admin::cloneBranchTo($newDir, self::REPOSITORY_URL, 'new-feature');
        self::registerDeletion($new);

        $head = $new->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertEquals('new-feature', $head->getName(), 'HEAD is branch new-feature');
    }

    public function testCloneBranchNotBare(): void
    {
        // we can't use AbstractTestCase::createFoobarRepository()
        // because it does not clone other branches than "master"
        // so we test it directly against remote repository

        $newDir = self::createTempDir();
        $new = Admin::cloneBranchTo($newDir, self::REPOSITORY_URL, 'new-feature', false);
        self::registerDeletion($new);

        $head = $new->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertEquals('new-feature', $head->getName(), 'HEAD is branch new-feature');
    }

    #[DataProvider('provideFoobar')]
    public function testMirror(Repository $repository): void
    {
        $newDir = self::createTempDir();
        $new = Admin::mirrorTo($newDir, $repository->getGitDir(), self::getOptions());
        self::registerDeletion($new);

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);
        $this->assertEquals($newDir, $new->getGitDir());

        if ($repository->isBare()) {
            $this->assertContains('refs/heads/new-feature', $newRefs);
        } else {
            $this->assertContains('refs/remotes/origin/new-feature', $newRefs);
        }
    }

    #[DataProvider('provideFoobar')]
    public function testCheckValidRepository(Repository $repository): void
    {
        $url = $repository->getGitDir();
        $this->assertTrue(Admin::isValidRepository($url));
    }

    public function testCheckInvalidRepository(): void
    {
        $url = $this->tmpDir.'/invalid.git';
        mkdir($url);

        $this->assertFalse(Admin::isValidRepository($url));
    }

    #[DataProvider('provideFoobar')]
    public function testCheckValidRepositoryAndBranch(Repository $repository): void
    {
        $url = $repository->getGitDir();
        $this->assertTrue(Admin::isValidRepositoryAndBranch($url, 'master'));
    }

    #[DataProvider('provideFoobar')]
    public function testCheckInvalidRepositoryAndBranch(Repository $repository): void
    {
        $url = $repository->getGitDir();
        $this->assertFalse(Admin::isValidRepositoryAndBranch($url, 'invalid-branch-name'));
    }

    public function testExistingFile(): void
    {
        $this->expectException(RuntimeException::class);

        $file = $this->tmpDir.'/test';
        touch($file);

        Admin::init($file, true, self::getOptions());
    }

    public function testCloneRepository(): void
    {
        $newDir = self::createTempDir();
        $args = [];

        $new = Admin::cloneRepository($newDir, self::REPOSITORY_URL, $args, self::getOptions());
        self::registerDeletion($new);

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertContains('refs/heads/master', $newRefs);
        $this->assertContains('refs/tags/0.1', $newRefs);

        $this->assertEquals($newDir.'/.git', $new->getGitDir());
        $this->assertEquals($newDir, $new->getWorkingDir());
    }
}
