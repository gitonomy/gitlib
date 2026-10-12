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
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;

class WorkingCopyTest extends AbstractTestCase
{
    public function testNoWorkingCopyInBare(): void
    {
        $this->expectException(LogicException::class);

        $path = self::createTempDir();
        $repo = Admin::init($path, true, driver: self::getDriver());

        $repo->getWorkingCopy();
    }

    public function testCheckout(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('origin/new-feature', 'new-feature');

        $head = $repository->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertEquals('new-feature', $head->getName(), 'HEAD is branch new-feature');
    }

    public function testDiffStaged(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();

        $diffStaged = $wc->getDiffStaged();
        $this->assertCount(0, $diffStaged->getFiles());

        $file = $repository->getWorkingDir().'/foobar-test';
        file_put_contents($file, 'test');
        self::git($repository, 'add', [$file]);

        $diffStaged = $wc->getDiffStaged();
        $this->assertCount(1, $diffStaged->getFiles());
    }

    public function testDiffPending(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();

        $diffPending = $wc->getDiffPending();
        $this->assertCount(0, $diffPending->getFiles());

        $file = $repository->getWorkingDir().'/test.sh';
        file_put_contents($file, 'test');

        $diffPending = $wc->getDiffPending();
        $this->assertCount(1, $diffPending->getFiles());
    }

    public function testDiffPendingWithAbbreviatedHashesLikeOldGit(): void
    {
        $this->skipUnlessCliDriver();

        $repository = self::createFoobarRepository(false);
        file_put_contents($repository->getWorkingDir().'/test.sh', 'test');
        // Before 2.16, git always wrote "..." after the abbreviated hashes of --raw.
        $repository = new Repository($repository->getPath(), driver: self::getCliDriver(['GIT_PRINT_SHA1_ELLIPSIS' => 'yes']));

        $files = $repository->getWorkingCopy()->getDiffPending()->getFiles();

        $this->assertCount(1, $files);
        $this->assertSame(40, \strlen($files[0]->getOldIndex()));
    }

    public function testCheckoutUnexisting(): void
    {
        $this->expectException(RuntimeException::class);

        self::createFoobarRepository(false)->getWorkingCopy()->checkout('foobar');
    }

    public function testAttachedHead(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('master');

        $head = $repository->getHead();
        $this->assertTrue($repository->isHeadAttached(), 'HEAD is attached');
        $this->assertFalse($repository->isHeadDetached(), 'HEAD is not detached');
    }

    public function testDetachedHead(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('0.1');

        $head = $repository->getHead();
        $this->assertFalse($repository->isHeadAttached(), 'HEAD is not attached');
        $this->assertTrue($repository->isHeadDetached(), 'HEAD is detached');
    }

    public function testGetUntracked(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();

        $file = $repository->getWorkingDir().'/untracked.txt';
        file_put_contents($file, 'foo');

        $this->assertContains('untracked.txt', $wc->getUntrackedFiles());
    }

    public function testMerge(): void
    {
        $repository = self::createFoobarRepository(false);
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('master');

        $wc->merge('origin/diff-features');

        $head = $repository->getHeadCommit();
        $this->assertCount(2, $head->getParents(), 'Merge produced a merge commit');
        $this->assertSame("Merge remote-tracking branch 'origin/diff-features'", $head->getSubjectMessage());
        $this->assertFileExists($repository->getWorkingDir().'/script_A.php');
    }

    public function testMergeConflict(): void
    {
        $this->expectException(RuntimeException::class);

        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('master');

        $wc->merge('origin/new-feature');
    }

    public function testMergeFastForwardsTheCurrentBranch(): void
    {
        $repository = self::createFoobarRepository(false);
        $master = $repository->getHeadCommit();
        $repository->getReferences()->createBranch('behind', $master->getParentHashes()[0]);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('behind');

        $wc->merge('master');

        $repository = new Repository($repository->getPath(), driver: self::getDriver());
        $this->assertSame('refs/heads/behind', $repository->getHead()->getFullname(), 'HEAD stays on the branch');
        $this->assertSame($master->getHash(), $repository->getHeadCommit()->getHash());
        $this->assertCount(0, $repository->getWorkingCopy()->getDiffPending()->getFiles());
        $this->assertCount(0, $repository->getWorkingCopy()->getDiffStaged()->getFiles());
    }

    public function testMergeOfAnAncestorDoesNothing(): void
    {
        $repository = self::createFoobarRepository(false);
        $head = $repository->getHeadCommit();
        $wc = $repository->getWorkingCopy();

        $wc->merge($head->getParentHashes()[0]);
        $wc->merge($head->getHash(), ['--no-ff']);

        $repository = new Repository($repository->getPath(), driver: self::getDriver());
        $this->assertSame($head->getHash(), $repository->getHeadCommit()->getHash());
    }

    public function testCheckoutKeepsLocalChangesItDoesNotConflictWith(): void
    {
        $repository = self::createFoobarRepository(false);
        $repository->getReferences()->createBranch('twin', $repository->getHeadCommit()->getHash());
        $file = $repository->getWorkingDir().'/README.md';
        file_put_contents($file, 'local change');

        $repository->getWorkingCopy()->checkout('twin');

        $this->assertSame('local change', file_get_contents($file));
    }

    public function testCheckoutRefusesToOverwriteLocalChanges(): void
    {
        $repository = self::createFoobarRepository(false);
        $file = $repository->getWorkingDir().'/AUTHORS';
        file_put_contents($file, 'local change');

        try {
            $repository->getWorkingCopy()->checkout('origin/pagination', 'pagination');
            $this->fail('The checkout should have been refused.');
        } catch (DriverException) {
        }

        $this->assertSame('local change', file_get_contents($file));
        $repository = new Repository($repository->getPath(), driver: self::getDriver());
        $this->assertFalse($repository->getReferences()->hasBranch('pagination'), 'No branch is created when the checkout fails');
    }

    public function testUntrackedFilesInNewDirectories(): void
    {
        $repository = self::createFoobarRepository(false);
        mkdir($repository->getWorkingDir().'/newdir/sub', 0o777, true);
        file_put_contents($repository->getWorkingDir().'/newdir/a.txt', 'a');
        file_put_contents($repository->getWorkingDir().'/newdir/sub/b.txt', 'b');

        $files = $repository->getWorkingCopy()->getUntrackedFiles();
        sort($files);

        $this->assertSame(['newdir/a.txt', 'newdir/sub/b.txt'], $files);
    }
}
