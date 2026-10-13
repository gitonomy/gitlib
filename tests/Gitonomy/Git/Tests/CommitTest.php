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
    #[DataProvider('provideBare')]
    public function testGetDiff(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $diff = $commit->getDiff();

        $this->assertInstanceOf(Diff::class, $diff, 'getDiff() returns a Diff object');
    }

    #[DataProvider('provideBare')]
    public function testGetHash(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals(self::LONGFILE_COMMIT, $commit->getHash());
    }

    #[DataProvider('provideBare')]
    public function testInvalideHashThrowException(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('Reference not found: "that-hash-doest-not-exists"');

        new Commit($repository, 'that-hash-doest-not-exists');
    }

    #[DataProvider('provideBare')]
    public function testGetShortHash(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('4f17752', $commit->getShortHash(), 'Short hash');
    }

    #[DataProvider('provideBare')]
    public function testGetParentHashesWithNoParent(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertCount(0, $commit->getParentHashes(), 'No parent on initial commit');
    }

    #[DataProvider('provideBare')]
    public function testGetParentHashesWithOneParent(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);
        $parents = $commit->getParentHashes();

        $this->assertCount(1, $parents, 'One parent found');
        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $parents[0], 'Parent hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetParentsWithOneParent(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);
        $parents = $commit->getParents();

        $this->assertCount(1, $parents, 'One parent found');
        $this->assertInstanceOf(Commit::class, $parents[0], 'First parent is a Commit object');
        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $parents[0]->getHash(), "First parents's hash is correct");
    }

    #[DataProvider('provideBare')]
    public function testGetTreeHash(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('b06890c7b10904979d2f69613c2ccda30aafe262', $commit->getTreeHash(), 'Tree hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetTree(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertInstanceOf(Tree::class, $commit->getTree(), 'Tree is a tree');
        $this->assertEquals('b06890c7b10904979d2f69613c2ccda30aafe262', $commit->getTree()->getHash(), 'Tree hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetAuthorName(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice', $commit->getAuthorName(), 'Author name');
    }

    #[DataProvider('provideBare')]
    public function testGetAuthorEmail(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice@example.org', $commit->getAuthorEmail(), 'Author email');
    }

    #[DataProvider('provideBare')]
    public function testGetAuthorDate(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('2012-12-31 14:21:03', $commit->getAuthorDate()->format('Y-m-d H:i:s'), 'Author date');
    }

    #[DataProvider('provideBare')]
    public function testGetCommitterName(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice', $commit->getCommitterName(), 'Committer name');
    }

    #[DataProvider('provideBare')]
    public function testGetCommitterEmail(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('alice@example.org', $commit->getCommitterEmail(), 'Committer email');
    }

    #[DataProvider('provideBare')]
    public function testGetCommitterDate(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('2012-12-31 14:21:03', $commit->getCommitterDate()->format('Y-m-d H:i:s'), 'Committer date');
    }

    #[DataProvider('provideBare')]
    public function testGetMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertEquals('add a long file'."\n", $commit->getMessage());
    }

    #[DataProvider('provideBare')]
    public function testGetEmptyMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::NO_MESSAGE_COMMIT);

        $this->assertEquals('', $commit->getMessage());
    }

    #[DataProvider('provideBare')]
    public function testGetEmptyMessageFromLog(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::NO_MESSAGE_COMMIT);
        $commitMessageFromLog = $commit->getLog()->getCommits()[0]->getMessage();

        $this->assertEquals('', $commitMessageFromLog);
    }

    /**
     * This test ensures that GPG signed commits does not break the reading of a commit
     * message.
     */
    #[DataProvider('provideBare')]
    public function testGetSignedMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::SIGNED_COMMIT);
        $this->assertEquals('signed commit'."\n", $commit->getMessage());
    }

    #[DataProvider('provideBare')]
    public function testGetShortMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
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

    #[DataProvider('provideBare')]
    public function testGetBodyMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGMESSAGE_COMMIT);
        $nl = \chr(10);
        $message = "If you want to know everything,{$nl}I ran something like `chmox +x test.sh`{$nl}{$nl}Hello and good bye.{$nl}";
        $this->assertEquals($message, $commit->getBodyMessage());

        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertEquals('', $commit->getBodyMessage());
    }

    #[DataProvider('provideBare')]
    public function testGetIncludingBranchesException(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(InvalidArgumentException::class);

        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $commit->getIncludingBranches(false, false);
    }

    #[DataProvider('provideBare')]
    public function testGetIncludingBranches(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $branches = $commit->getIncludingBranches(true, false);
        $this->assertCount(\count($repository->getReferences()->getLocalBranches()), $branches);

        $branches = $commit->getIncludingBranches(true, true);
        $this->assertCount(\count($repository->getReferences()->getBranches()), $branches);

        $branches = $commit->getIncludingBranches(false, true);
        $this->assertCount(\count($repository->getReferences()->getRemoteBranches()), $branches);
    }

    #[DataProvider('provideBare')]
    public function testGetLastModification(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $lastModification = $commit->getLastModification('image.jpg');

        $this->assertTrue($lastModification instanceof Commit, 'Last modification is a Commit object');
        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $lastModification->getHash(), 'Last modification is current commit');
    }

    #[DataProvider('provideBare')]
    public function testMergeCommit(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::MERGE_COMMIT);

        $this->assertEquals("Merge branch 'authors'", $commit->getSubjectMessage());
    }

    #[DataProvider('provideBare')]
    public function testEncoding(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::ENCODING_COMMIT);

        $this->assertEquals('contribute to AUTHORS file', $commit->getSubjectMessage());
    }

    public function testReencodesCommitsToUtf8(): void
    {
        $repository = self::createFoobarRepository(false);
        self::git($repository, 'config', ['i18n.commitEncoding', 'ISO-8859-1']);
        self::git($repository, 'config', ['user.name', "Andr\xe9"]);
        self::git($repository, 'config', ['user.email', 'andre@example.com']);
        file_put_contents($repository->getWorkingDir().'/latin1.txt', 'latin1');
        self::git($repository, 'add', ['latin1.txt']);
        self::git($repository, 'commit', ['-m', "Caf\xe9"]);
        $hash = trim(self::git($repository, 'rev-parse', ['HEAD']));

        $commit = new Repository($repository->getPath(), driver: self::getDriver())->getCommit($hash);
        $this->assertSame("Caf\u{e9}\n", $commit->getMessage());
        $this->assertSame("Andr\u{e9}", $commit->getAuthorName());

        $commit = new Repository($repository->getPath(), driver: self::getDriver())->getLog($hash, null, null, 1)->getSingleCommit();
        $this->assertSame("Caf\u{e9}\n", $commit->getMessage());
        $this->assertSame("Andr\u{e9}", $commit->getAuthorName());
    }

    public function testMessageIsReadLikeGitLogPrintsIt(): void
    {
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', '');
        // Not cleaned up, unlike what `git commit` writes.
        $hash = self::writeObject($repository, 'commit', "tree {$tree}\nauthor A <a@example.com> 1000000000 +0000\ncommitter A <a@example.com> 1000000000 +0000\n\n\n \nSubject \t\n\nBody\r\n    indented\n\n\n");

        $this->assertSame("Subject\n\nBody\n    indented\n", $repository->getCommit($hash)->getMessage());
        $this->assertSame("Subject\n\nBody\n    indented\n", new Repository($repository->getPath(), driver: self::getDriver())->getLog([$hash])->getSingleCommit()->getMessage());
    }
}
