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

use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Tag;
use PHPUnit\Framework\Attributes\DataProvider;

class ReferenceTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testEmptyRepository(bool $bare): void
    {
        $repository = self::createEmptyRepository($bare);
        $this->assertCount(0, $repository->getReferences());
        $this->assertEquals([], $repository->getReferences()->getAll());
    }

    #[DataProvider('provideBare')]
    public function testGetBranch(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $branch = $repository->getReferences()->getBranch('master');

        $this->assertInstanceOf(Branch::class, $branch, 'Branch object is correct type');
        $this->assertEquals($branch->getCommitHash(), $branch->getCommit()->getHash(), 'Hash is correctly resolved');
    }

    #[DataProvider('provideBare')]
    public function testHasBranch(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->assertTrue($repository->getReferences()->hasBranch('master'), 'Branch master exists');
        $this->assertFalse($repository->getReferences()->hasBranch('foobar'), 'Branch foobar does not exists');
    }

    #[DataProvider('provideBare')]
    public function testHasTag(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->assertTrue($repository->getReferences()->hasTag('0.1'), 'Tag 0.1 exists');
        $this->assertFalse($repository->getReferences()->hasTag('foobar'), 'Tag foobar does not exists');
    }

    #[DataProvider('provideBare')]
    public function testGetBranchNotExistingError(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(ReferenceNotFoundException::class);

        $repository->getReferences()->getBranch('notexisting');
    }

    #[DataProvider('provideBare')]
    public function testGetTag(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tag = $repository->getReferences()->getTag('0.1');

        $this->assertInstanceOf(Tag::class, $tag, 'Tag object is correct type');
        $this->assertFalse($tag->isAnnotated(), 'Tag is not annotated');

        $this->assertEquals(self::LONGFILE_COMMIT, $tag->getCommitHash(), 'Commit hash is correct');
        $this->assertEquals(self::LONGFILE_COMMIT, $tag->getCommit()->getHash(), 'Commit hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testAnnotatedTag(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
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

    #[DataProvider('provideBare')]
    public function testAnnotatedTagBodyMessageWithBlankLineSeparator(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        self::git($repository, 'config', ['user.email', 'test@example.com']);
        self::git($repository, 'config', ['user.name', 'Test']);

        $hash = $repository->getLog()->getSingleCommit()->getHash();
        self::git($repository, 'tag', ['-a', 'blank-sep', '-m', 'Subject line', '-m', "Body line 1\nBody line 2", $hash]);

        $tag = $repository->getReferences()->getTag('blank-sep');

        $this->assertEquals('Subject line', $tag->getSubjectMessage(), 'Message subject is correct');
        $this->assertEquals("Body line 1\nBody line 2", $tag->getBodyMessage(), 'Message body has no leading blank line');
    }

    #[DataProvider('provideBare')]
    public function testGetTagNotExistingError(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(ReferenceNotFoundException::class);

        $repository->getReferences()->getTag('notexisting');
    }

    #[DataProvider('provideBare')]
    public function testResolve(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getReferences()->getTag('0.1')->getCommit();
        $resolved = $repository->getReferences()->resolve($commit->getHash());

        $this->assertCount(1, $resolved, '1 revision resolved');
        $this->assertInstanceOf(Tag::class, reset($resolved), 'Resolved object is a tag');
    }

    #[DataProvider('provideBare')]
    public function testResolveTags(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getReferences()->getTag('0.1')->getCommit();
        $resolved = $repository->getReferences()->resolveTags($commit->getHash());

        $this->assertCount(1, $resolved, '1 revision resolved');
        $this->assertInstanceOf(Tag::class, reset($resolved), 'Resolved object is a tag');
    }

    #[DataProvider('provideBare')]
    public function testResolveBranches(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $master = $repository->getReferences()->getBranch('master');

        $resolved = $repository->getReferences()->resolveBranches($master->getCommitHash());

        if ($repository->isBare()) {
            $this->assertCount(1, $resolved, '1 revision resolved');
        } else {
            $this->assertCount(2, $resolved, '2 revision resolved');
        }

        $this->assertInstanceOf(Branch::class, reset($resolved), 'Resolved object is a branch');
    }

    #[DataProvider('provideBare')]
    public function testCountable(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->assertGreaterThanOrEqual(2, \count($repository->getReferences()), 'At least two references in repository');
    }

    #[DataProvider('provideBare')]
    public function testIterable(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $i = 0;
        foreach ($repository->getReferences() as $ref) {
            ++$i;
        }
        $this->assertGreaterThanOrEqual(2, $i, 'At least two references in repository');
    }

    #[DataProvider('provideBare')]
    public function testCreateAndDeleteTag(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $references = $repository->getReferences();
        $tag = $references->createTag('0.0', self::INITIAL_COMMIT);

        $this->assertTrue($references->hasTag('0.0'), 'Tag 0.0 created');
        $this->assertEquals(self::INITIAL_COMMIT, $tag->getCommit()->getHash());
        $this->assertSame($tag, $references->getTag('0.0'));

        $tag->delete();
        $this->assertFalse($references->hasTag('0.0'), 'Tag 0.0 removed');
    }

    #[DataProvider('provideBare')]
    public function testCreateAndDeleteBranch(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $references = $repository->getReferences();
        $branch = $references->createBranch('foobar', self::INITIAL_COMMIT);

        $this->assertTrue($references->hasBranch('foobar'), 'Branch foobar created');
        $this->assertEquals(self::INITIAL_COMMIT, $branch->getCommit()->getHash());
        $this->assertSame($branch, $references->getBranch('foobar'));

        $branch->delete();
        $this->assertFalse($references->hasBranch('foobar'), 'Branch foobar removed');
    }

    public function testIsBranchMergedToMaster(): void
    {
        $repository = self::createFoobarRepository(false);

        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);

        $master = $repository->getReferences()->getBranch('master');
        $references = $repository->getReferences();

        $branch = $references->createBranch('foobar-new', $master->getCommit()->getHash());

        $this->assertTrue($branch->isMergedTo('master'));

        $wc = $repository->getWorkingCopy();
        $wc->checkout('foobar-new');

        $file = $repository->getWorkingDir().'/foobar-test.txt';
        file_put_contents($file, 'test');
        self::git($repository, 'add', [$file]);
        self::git($repository, 'commit', ['-m', 'foobar-test.txt updated']);

        $this->assertFalse($branch->isMergedTo('master'));
    }

    /**
     * Old tags (e.g. in git.git or linux) have no "tagger" line.
     */
    public function testAnnotatedTagWithoutTagger(): void
    {
        $repository = self::createFoobarRepository(false);
        $hash = self::writeObject($repository, 'tag', \sprintf("object %s\ntype commit\ntag notagger\n\nNo tagger.\n", self::LONGFILE_COMMIT));
        self::git($repository, 'update-ref', ['refs/tags/notagger', $hash]);

        $tag = $repository->getReferences()->getTag('notagger');

        $this->assertTrue($tag->isAnnotated());
        $this->assertSame(self::LONGFILE_COMMIT, $tag->getCommit()->getHash());
    }

    public function testSignedTag(): void
    {
        $repository = self::createFoobarRepository(false);
        $hash = self::writeObject($repository, 'tag', \sprintf("object %s\ntype commit\ntag signed\ntagger Alice <alice@example.com> 1471428000 +0000\n\nSigned.\n-----BEGIN PGP SIGNATURE-----\n\nsignature\n-----END PGP SIGNATURE-----\n", self::LONGFILE_COMMIT));
        self::git($repository, 'update-ref', ['refs/tags/signed', $hash]);

        $tag = $repository->getReferences()->getTag('signed');

        $this->assertTrue($tag->isSigned());
        $this->assertSame("\n\nsignature\n", $tag->getGPGSignature());
        $this->assertSame("Signed.\n", $tag->getMessage());
    }
}
