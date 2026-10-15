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

use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Tag;
use Gitonomy\Git\Repository;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class ReferenceTest extends AbstractTest
{
    use ExpectDeprecationTrait;

    /**
     * @dataProvider provideEmpty
     */
    public function testEmptyRepository($repository)
    {
        $this->assertCount(0, $repository->getReferences());
        $this->assertEquals([], $repository->getReferences()->getAll());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetBranch($repository)
    {
        $branch = $repository->getReferences()->getBranch('master');

        $this->assertInstanceOf(Branch::class, $branch, 'Branch object is correct type');
        $this->assertEquals($branch->getCommitHash(), $branch->getCommit()->getHash(), 'Hash is correctly resolved');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testHasBranch($repository)
    {
        $this->assertTrue($repository->getReferences()->hasBranch('master'), 'Branch master exists');
        $this->assertFalse($repository->getReferences()->hasBranch('foobar'), 'Branch foobar does not exists');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testHasTag($repository)
    {
        $this->assertTrue($repository->getReferences()->hasTag('0.1'), 'Tag 0.1 exists');
        $this->assertFalse($repository->getReferences()->hasTag('foobar'), 'Tag foobar does not exists');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetBranch_NotExisting_Error($repository)
    {
        $this->expectException(ReferenceNotFoundException::class);

        $repository->getReferences()->getBranch('notexisting');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetTag($repository)
    {
        $tag = $repository->getReferences()->getTag('0.1');

        $this->assertInstanceOf(Tag::class, $tag, 'Tag object is correct type');
        $this->assertFalse($tag->isAnnotated(), 'Tag is not annotated');

        $this->assertEquals(self::LONGFILE_COMMIT, $tag->getCommitHash(), 'Commit hash is correct');
        $this->assertEquals(self::LONGFILE_COMMIT, $tag->getCommit()->getHash(), 'Commit hash is correct');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testAnnotatedTag($repository)
    {
        $tag = $repository->getReferences()->getTag('annotated');

        $this->assertInstanceOf(Tag::class, $tag, 'Tag object is correct type');
        $this->assertTrue($tag->isAnnotated(), 'Tag is annotated');
        $this->assertFalse($tag->isSigned(), 'Tag is not signed');

        $this->assertEquals('Graham Campbell', $tag->getTaggerName(), 'Tagger name is correct');
        $this->assertEquals('graham@alt-three.com', $tag->getTaggerEmail(), 'Tagger email is correct');
        $this->assertEquals(1471428000, $tag->getTaggerDate()->getTimestamp(), 'Tag date is correct');

        $this->assertEquals('heading', $tag->getSubjectMessage(), 'Message heading is correct');
        $this->assertEquals("body\nbody", $tag->getBodyMessage(), 'Message body is correct');

        $closure = function () {
            return parent::getCommit();
        };
        $parentCommit = $closure->bindTo($tag, Tag::class);
        $this->assertNotEquals($parentCommit()->getHash(), $tag->getCommit()->getHash(), 'Tag commit is not the same as main commit');
        $this->assertEquals('fbde681b329a39e08b63dc54b341a3274c0380c0', $tag->getCommit()->getHash(), 'Tag commit is correct');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetTag_NotExisting_Error($repository)
    {
        $this->expectException(ReferenceNotFoundException::class);

        $repository->getReferences()->getTag('notexisting');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testResolve($repository)
    {
        $commit = $repository->getReferences()->getTag('0.1')->getCommit();
        $resolved = $repository->getReferences()->resolve($commit->getHash());

        $this->assertCount(1, $resolved, '1 revision resolved');
        $this->assertInstanceOf(Tag::class, reset($resolved), 'Resolved object is a tag');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testResolveTags($repository)
    {
        $commit = $repository->getReferences()->getTag('0.1')->getCommit();
        $resolved = $repository->getReferences()->resolveTags($commit->getHash());

        $this->assertCount(1, $resolved, '1 revision resolved');
        $this->assertInstanceOf(Tag::class, reset($resolved), 'Resolved object is a tag');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testResolveBranches($repository)
    {
        $master = $repository->getReferences()->getBranch('master');

        $resolved = $repository->getReferences()->resolveBranches($master->getCommitHash());

        if ($repository->isBare()) {
            $this->assertCount(1, $resolved, '1 revision resolved');
        } else {
            $this->assertCount(2, $resolved, '2 revision resolved');
        }

        $this->assertInstanceOf(Branch::class, reset($resolved), 'Resolved object is a branch');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testCountable($repository)
    {
        $this->assertGreaterThanOrEqual(2, count($repository->getReferences()), 'At least two references in repository');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testIterable($repository)
    {
        $i = 0;
        foreach ($repository->getReferences() as $ref) {
            $i++;
        }
        $this->assertGreaterThanOrEqual(2, $i, 'At least two references in repository');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testCreateAndDeleteTag($repository)
    {
        $references = $repository->getReferences();
        $tag = $references->createTag('0.0', self::INITIAL_COMMIT);

        $this->assertTrue($references->hasTag('0.0'), 'Tag 0.0 created');
        $this->assertEquals(self::INITIAL_COMMIT, $tag->getCommit()->getHash());
        $this->assertSame($tag, $references->getTag('0.0'));

        $tag->delete();
        $this->assertFalse($references->hasTag('0.0'), 'Tag 0.0 removed');
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testCreateAndDeleteBranch($repository)
    {
        $references = $repository->getReferences();
        $branch = $references->createBranch('foobar', self::INITIAL_COMMIT);

        $this->assertTrue($references->hasBranch('foobar'), 'Branch foobar created');
        $this->assertEquals(self::INITIAL_COMMIT, $branch->getCommit()->getHash());
        $this->assertSame($branch, $references->getBranch('foobar'));

        $branch->delete();
        $this->assertFalse($references->hasBranch('foobar'), 'Branch foobar removed');
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testIsBranchMergedToMaster()
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\Reference\Branch::isMergedTo()" method is deprecated, use "Gitonomy\Git\Reference\Branch::isMergedInto()" instead.');

        $repository = self::createFoobarRepository(false);

        $repository->run('config', ['--local', 'user.name', '"Unit Test"']);
        $repository->run('config', ['--local', 'user.email', '"unit_test@unit-test.com"']);

        $master = $repository->getReferences()->getBranch('master');
        $references = $repository->getReferences();

        $branch = $references->createBranch('foobar-new', $master->getCommit()->getHash());

        $this->assertTrue($branch->isMergedTo('master'));

        $wc = $repository->getWorkingCopy();
        $wc->checkout('foobar-new');

        $file = $repository->getWorkingDir().'/foobar-test.txt';
        file_put_contents($file, 'test');
        $repository->run('add', [$file]);
        $repository->run('commit', ['-m', 'foobar-test.txt updated']);

        $this->assertFalse($branch->isMergedTo('master'));
    }

    public function testIsMergedInto()
    {
        $repository = self::createFoobarRepository();
        $references = $repository->getReferences();

        $this->assertTrue($references->getBranch('master')->isMergedInto('master'));
        $this->assertFalse($references->getBranch('new-feature')->isMergedInto('master'));
        $this->assertTrue($references->createBranch('initial', self::INITIAL_COMMIT)->isMergedInto($references->getBranch('new-feature')));
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testMoveTo($repository)
    {
        $references = $repository->getReferences();
        $branch = $references->createBranch('foobar', self::INITIAL_COMMIT);

        $moved = $branch->moveTo(self::LONGFILE_COMMIT);

        $this->assertSame(self::LONGFILE_COMMIT, $moved->getCommitHash());
        // The bag read before sees the move, like in 2.0.
        $this->assertSame(self::LONGFILE_COMMIT, $references->getBranch('foobar')->getCommitHash());
        $this->assertContains($moved, $references->getLocalBranches());
        $this->assertSame(self::LONGFILE_COMMIT."\n", $repository->run('rev-parse', ['refs/heads/foobar']));
        // The branch read before keeps its commit.
        $this->assertSame(self::INITIAL_COMMIT, $branch->getCommitHash());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testMoveToAnAnnotatedTag($repository)
    {
        $branch = $repository->getReferences()->createBranch('foobar', self::INITIAL_COMMIT);

        $moved = $branch->moveTo($repository->getReferences()->getTag('annotated'));

        // The commit of the tag, not the tag object.
        $this->assertSame($repository->getRevision('annotated^{commit}')->getCommit()->getHash(), $moved->getCommitHash());
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testCreateBranchFromARevision($repository)
    {
        $branch = $repository->getReferences()->createBranch('foobar', $repository->getRevision('master'));

        $this->assertSame($repository->getReferences()->getBranch('master')->getCommitHash(), $branch->getCommitHash());
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testCreateExistingReferences($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Creating the branch "master", which exists already, is deprecated, it throws in 2.0: use "Gitonomy\Git\Reference\Branch::moveTo()" instead.');
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Creating the tag "0.1", which exists already, is deprecated, it throws in 2.0: delete it first.');

        $references = $repository->getReferences();
        $references->createBranch('master', self::INITIAL_COMMIT);
        $references->createTag('0.1', self::INITIAL_COMMIT);

        $this->assertSame(self::INITIAL_COMMIT."\n", $repository->run('rev-parse', ['refs/heads/master']));
        $this->assertSame(self::INITIAL_COMMIT."\n", $repository->run('rev-parse', ['refs/tags/0.1']));
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testDeleteOutsideRefs($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: Deleting "HEAD", which is not the full name of a reference under "refs/", is deprecated, it throws in 2.0.');

        $repository->getReferences()->delete('HEAD');

        // git deletes the branch HEAD points to.
        $this->assertFalse((new Repository($repository->getGitDir(), null, self::getDriver()))->getReferences()->hasBranch('master'));
    }

    /**
     * @dataProvider provideFoobar
     *
     * @group legacy
     */
    public function testUpdate($repository)
    {
        $this->expectDeprecation('Since gitonomy/gitlib 1.7: The "Gitonomy\Git\ReferenceBag::update()" method is deprecated, use "Gitonomy\Git\Reference\Branch::moveTo()" instead.');

        $repository->getReferences()->update(new Branch($repository, 'refs/heads/foobar', self::INITIAL_COMMIT));

        $this->assertSame(self::INITIAL_COMMIT."\n", $repository->run('rev-parse', ['refs/heads/foobar']));
    }

    public function testCreateBranchFromAName()
    {
        $repository = self::createFoobarRepository();
        $references = $repository->getReferences();
        $master = $references->getBranch('master')->getCommitHash();

        $branch = $references->createBranch('foobar', 'master');

        // The name, like in 1.6, where 2.0 resolves it.
        $this->assertSame('master', $branch->getCommitHash());
        $this->assertSame($master."\n", $repository->run('rev-parse', ['refs/heads/foobar']));
        $this->assertTrue($branch->isMergedInto('master'));
    }

    public function testCreateBranchFromAnUnknownName()
    {
        $this->expectException(ProcessException::class);

        self::createFoobarRepository()->getReferences()->createBranch('foobar', 'unknown');
    }
}
