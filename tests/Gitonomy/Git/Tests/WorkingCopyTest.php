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

use Gitonomy\Git\Repository;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class WorkingCopyTest extends AbstractTest
{
    use ExpectDeprecationTrait;

    public function testNoWorkingCopyInBare()
    {
        $this->expectException(LogicException::class);

        $path = self::createTempDir();
        $repo = Repository::initBare($path, self::getDriver());

        $repo->getWorkingCopy();
    }

    public function testCheckoutNewBranch()
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkoutNewBranch('new-feature', 'origin/new-feature');

        $head = $repository->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertEquals('new-feature', $head->getName(), 'HEAD is branch new-feature');
        $this->assertSame($repository->getReferences()->getRemoteBranch('origin/new-feature')->getCommitHash(), $head->getCommitHash());
    }

    public function testCheckoutNewBranchFromHead()
    {
        $repository = self::createFoobarRepository(false);
        $repository->getWorkingCopy()->checkoutNewBranch('foobar');

        $head = $repository->getHead();
        $this->assertSame('foobar', $head->getName());
        $this->assertSame($repository->getReferences()->getBranch('master')->getCommitHash(), $head->getCommitHash());
    }

    public function testCheckoutARevision()
    {
        $repository = self::createFoobarRepository(false);
        $repository->getWorkingCopy()->checkout($repository->getRevision('0.1'));

        $this->assertTrue($repository->isHeadDetached());
        $this->assertSame($repository->getRevision('0.1')->getCommit()->getHash(), $repository->getHeadCommit()->getHash());
    }

    /**
     * @group legacy
     */
    public function testCheckout()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing a branch to create to "Gitonomy\Git\WorkingCopy::checkout()" is deprecated, use "checkoutNewBranch()" instead.');

        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('origin/new-feature', 'new-feature');

        $head = $repository->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertEquals('new-feature', $head->getName(), 'HEAD is branch new-feature');
    }

    public function testDiff()
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();

        $this->assertCount(0, $wc->getDiff()->getFiles());

        file_put_contents($repository->getWorkingDir().'/staged.txt', 'staged');
        $repository->run('add', ['staged.txt']);
        file_put_contents($repository->getWorkingDir().'/README.md', 'pending');

        $diff = $wc->getDiff();
        $this->assertSame(['README.md', 'staged.txt'], array_map(function ($file) {
            return $file->getName();
        }, $diff->getFiles()));
        $this->assertSame($repository, $diff->getFiles()[0]->getRepository());

        // Against another revision, the changes of the commits since.
        $this->assertGreaterThan(2, count($wc->getDiff($repository->getRevision(self::LONGFILE_COMMIT))->getFiles()));
    }

    public function testDiffStaged()
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();

        $diffStaged = $wc->getDiffStaged();
        $this->assertCount(0, $diffStaged->getFiles());

        $file = $repository->getWorkingDir().'/foobar-test';
        file_put_contents($file, 'test');
        $repository->run('add', [$file]);

        $diffStaged = $wc->getDiffStaged();
        $this->assertCount(1, $diffStaged->getFiles());
    }

    public function testDiffPending()
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

    public function testCheckoutUnexisting()
    {
        $this->expectException(RuntimeException::class);

        self::createFoobarRepository(false)->getWorkingCopy()->checkout('foobar');
    }

    public function testAttachedHead()
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('master');

        $head = $repository->getHead();
        $this->assertTrue($repository->isHeadAttached(), 'HEAD is attached');
        $this->assertFalse($repository->isHeadDetached(), 'HEAD is not detached');
    }

    public function testDetachedHead()
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('0.1');

        $head = $repository->getHead();
        $this->assertFalse($repository->isHeadAttached(), 'HEAD is not attached');
        $this->assertTrue($repository->isHeadDetached(), 'HEAD is detached');
    }

    public function testGetUntracked()
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();

        $file = $repository->getWorkingDir().'/untracked.txt';
        file_put_contents($file, 'foo');

        $this->assertContains('untracked.txt', $wc->getUntrackedFiles());
    }

    public function testDiffThrowsForAnUnknownRevision()
    {
        $this->expectException(ReferenceNotFoundException::class);

        self::createFoobarRepository(false)->getWorkingCopy()->getDiff('unknown');
    }

    public function testCheckoutNewBranchThrowsForAnUnknownStartPoint()
    {
        $repository = self::createFoobarRepository(false);

        try {
            $repository->getWorkingCopy()->checkoutNewBranch('foobar', 'unknown');
            $this->fail('An unknown start point throws.');
        } catch (ReferenceNotFoundException $e) {
            $this->assertFalse((new Repository($repository->getGitDir(), null, self::getDriver()))->getReferences()->hasBranch('foobar'));
        }
    }

    /**
     * @group legacy
     */
    public function testCheckoutALocalBranchObject()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Passing a local "Gitonomy\\Git\\Reference\\Branch" to "Gitonomy\\Git\\WorkingCopy::checkout()", which detaches HEAD, is deprecated, 2.0 attaches HEAD to the branch: pass its name, or its commit to detach HEAD.');

        $repository = self::createFoobarRepository(false);
        $repository->getWorkingCopy()->checkout($repository->getReferences()->getBranch('master'));

        $this->assertTrue($repository->isHeadDetached());
    }
}
