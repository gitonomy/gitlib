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

use Gitonomy\Git\Commit;
use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Tree;
use PHPUnit\Framework\Attributes\DataProvider;

class CommitTest extends AbstractTestCase
{
    #[DataProvider('provideFoobar')]
    public function testGetDiff(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $diff = $commit->getDiff();

        $this->assertInstanceOf(Diff::class, $diff, 'getDiff() returns a Diff object');
    }

    #[DataProvider('provideFoobar')]
    public function testGetHash(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals(self::LONGFILE_COMMIT, $commit->getHash());
    }

    #[DataProvider('provideFoobar')]
    public function testInvalideHashThrowException(Repository $repository): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('Reference not found: "that-hash-doest-not-exists"');

        new Commit($repository, 'that-hash-doest-not-exists');
    }

    #[DataProvider('provideFoobar')]
    public function testGetShortHash(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('4f17752', $commit->getShortHash(), 'Short hash');
    }

    #[DataProvider('provideFoobar')]
    public function testGetParentHashesWithNoParent(Repository $repository): void
    {
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertCount(0, $commit->getParentHashes(), 'No parent on initial commit');
    }

    #[DataProvider('provideFoobar')]
    public function testGetParentHashesWithOneParent(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);
        $parents = $commit->getParentHashes();

        $this->assertCount(1, $parents, 'One parent found');
        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $parents[0], 'Parent hash is correct');
    }

    #[DataProvider('provideFoobar')]
    public function testGetParentsWithOneParent(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);
        $parents = $commit->getParents();

        $this->assertCount(1, $parents, 'One parent found');
        $this->assertInstanceOf(Commit::class, $parents[0], 'First parent is a Commit object');
        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $parents[0]->getHash(), "First parents's hash is correct");
    }

    #[DataProvider('provideFoobar')]
    public function testGetTreeHash(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('b06890c7b10904979d2f69613c2ccda30aafe262', $commit->getTreeHash(), 'Tree hash is correct');
    }

    #[DataProvider('provideFoobar')]
    public function testGetTree(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertInstanceOf(Tree::class, $commit->getTree(), 'Tree is a tree');
        $this->assertEquals('b06890c7b10904979d2f69613c2ccda30aafe262', $commit->getTree()->getHash(), 'Tree hash is correct');
    }

    #[DataProvider('provideFoobar')]
    public function testGetAuthorName(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice', $commit->getAuthorName(), 'Author name');
    }

    #[DataProvider('provideFoobar')]
    public function testGetAuthorEmail(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice@example.org', $commit->getAuthorEmail(), 'Author email');
    }

    #[DataProvider('provideFoobar')]
    public function testGetAuthorDate(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('2012-12-31 14:21:03', $commit->getAuthorDate()->format('Y-m-d H:i:s'), 'Author date');
    }

    #[DataProvider('provideFoobar')]
    public function testGetCommitterName(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice', $commit->getCommitterName(), 'Committer name');
    }

    #[DataProvider('provideFoobar')]
    public function testGetCommitterEmail(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice@example.org', $commit->getCommitterEmail(), 'Committer email');
    }

    #[DataProvider('provideFoobar')]
    public function testGetCommitterDate(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('2012-12-31 14:21:03', $commit->getCommitterDate()->format('Y-m-d H:i:s'), 'Committer date');
    }

    #[DataProvider('provideFoobar')]
    public function testGetMessage(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('add a long file'."\n", $commit->getMessage());
    }

    #[DataProvider('provideFoobar')]
    public function testGetEmptyMessage(Repository $repository): void
    {
        $commit = $repository->getCommit(self::NO_MESSAGE_COMMIT);

        $this->assertEquals('', $commit->getMessage());
    }

    #[DataProvider('provideFoobar')]
    public function testGetEmptyMessageFromLog(Repository $repository): void
    {
        $commit = $repository->getCommit(self::NO_MESSAGE_COMMIT);
        $commitMessageFromLog = $commit->getLog()->getCommits()[0]->getMessage();

        $this->assertEquals('', $commitMessageFromLog);
    }

    /**
     * This test ensures that GPG signed commits does not break the reading of a commit
     * message.
     */
    #[DataProvider('provideFoobar')]
    public function testGetSignedMessage(Repository $repository): void
    {
        $commit = $repository->getCommit(self::SIGNED_COMMIT);
        $this->assertEquals('signed commit'."\n", $commit->getMessage());
    }

    #[DataProvider('provideFoobar')]
    public function testGetShortMessage(Repository $repository): void
    {
        // tests with a multi-line message
        $commit = $repository->getCommit(self::LONGMESSAGE_COMMIT);

        $this->assertEquals('Fixed perm...', $commit->getShortMessage(10));
        $this->assertEquals('Fixed perm!!!', $commit->getShortMessage(10, false, '!!!'));
        $this->assertEquals('Fixed permissions!!!', $commit->getShortMessage(10, true, '!!!'));

        // tests with a single-line message
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertEquals('Add README', $commit->getShortMessage(20));
        $this->assertEquals('A', $commit->getShortMessage(1, false, ''));
        $this->assertEquals('Add!!!', $commit->getShortMessage(1, true, '!!!'));
    }

    #[DataProvider('provideFoobar')]
    public function testGetBodyMessage(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGMESSAGE_COMMIT);
        $nl = \chr(10);
        $message = "If you want to know everything,{$nl}I ran something like `chmox +x test.sh`{$nl}{$nl}Hello and good bye.{$nl}";
        $this->assertEquals($message, $commit->getBodyMessage());

        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertEquals('', $commit->getBodyMessage());
    }

    #[DataProvider('provideFoobar')]
    public function testGetIncludingBranchesException(Repository $repository): void
    {
        $this->expectException(InvalidArgumentException::class);

        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $commit->getIncludingBranches(false, false);
    }

    #[DataProvider('provideFoobar')]
    public function testGetIncludingBranches(Repository $repository): void
    {
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $branches = $commit->getIncludingBranches(true, false);
        $this->assertCount(\count($repository->getReferences()->getLocalBranches()), $branches);

        $branches = $commit->getIncludingBranches(true, true);
        $this->assertCount(\count($repository->getReferences()->getBranches()), $branches);

        $branches = $commit->getIncludingBranches(false, true);
        $this->assertCount(\count($repository->getReferences()->getRemoteBranches()), $branches);
    }

    #[DataProvider('provideFoobar')]
    public function testGetLastModification(Repository $repository): void
    {
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $lastModification = $commit->getLastModification('image.jpg');

        $this->assertTrue($lastModification instanceof Commit, 'Last modification is a Commit object');
        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $lastModification->getHash(), 'Last modification is current commit');
    }

    #[DataProvider('provideFoobar')]
    public function testMergeCommit(Repository $repository): void
    {
        $commit = $repository->getCommit(self::MERGE_COMMIT);

        $this->assertEquals("Merge branch 'authors'", $commit->getSubjectMessage());
    }

    #[DataProvider('provideFoobar')]
    public function testEncoding(Repository $repository): void
    {
        $commit = $repository->getCommit(self::ENCODING_COMMIT);

        $this->assertEquals('contribute to AUTHORS file', $commit->getSubjectMessage());
    }
}
