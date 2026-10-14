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
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Component\Process\Process;

class CommitTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testGetDiff(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $diff = $commit->getDiff();

        $this->assertSame(['long.php'], array_map(static fn (File $file): string => $file->getName(), $diff->getFiles()));
    }

    public function testADateOutOfRangeThrows(): void
    {
        $repository = self::createFoobarRepository();
        $hash = self::writeObject($repository, 'commit', "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\nauthor A <a@example.com> 99999999999999999999 +0000\ncommitter A <a@example.com> 99999999999999999999 +0000\n\nCorrupt\n");

        // A RuntimeException of gitlib, not of PHP.
        $this->expectException(RuntimeException::class);

        $repository->getCommit($hash)->getAuthorDate();
    }

    /**
     * Like git log, at the UTC offsets of the author and of the committer.
     */
    public function testTheDatesAreAtTheOffsetsOfTheAuthorAndOfTheCommitter(): void
    {
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', '');
        $git = new Repository($repository->getPath(), driver: self::getCliDriver([
            'GIT_AUTHOR_DATE' => '1700000000 +0530',
            'GIT_AUTHOR_EMAIL' => 'author@example.com',
            'GIT_AUTHOR_NAME' => 'Author',
            'GIT_COMMITTER_DATE' => '1700000001 -0330',
            'GIT_COMMITTER_EMAIL' => 'committer@example.com',
            'GIT_COMMITTER_NAME' => 'Committer',
        ]));
        $hash = trim($git->run('commit-tree', [$tree, '-m', 'Dates']));

        $commit = $repository->getCommit($hash);

        $this->assertSame('2023-11-15T03:43:20+05:30', $commit->getAuthorDate()->format(\DATE_ATOM));
        $this->assertSame(1700000000, $commit->getAuthorDate()->getTimestamp());
        $this->assertSame('2023-11-14T18:43:21-03:30', $commit->getCommitterDate()->format(\DATE_ATOM));
        $this->assertSame(1700000001, $commit->getCommitterDate()->getTimestamp());
    }

    /**
     * Like git log, which shows "-0000" as "+0000". An offset of more than 14
     * hours or 59 minutes, which no timezone has, gives UTC, like libgit2.
     */
    #[DataProvider('provideOffsets')]
    public function testADateIsAtTheOffsetOfItsSignature(string $offset, string $date): void
    {
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', '');
        $hash = self::writeObject($repository, 'commit', "tree {$tree}\nauthor A <a@example.com> 1700000000 {$offset}\ncommitter A <a@example.com> 1700000000 {$offset}\n\nOffset\n");

        $commit = $repository->getCommit($hash);

        $this->assertSame($date, $commit->getAuthorDate()->format(\DATE_ATOM));
        $this->assertSame($date, $commit->getCommitterDate()->format(\DATE_ATOM));
        $this->assertSame(1700000000, $commit->getAuthorDate()->getTimestamp());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOffsets(): iterable
    {
        yield 'half an hour' => ['+0530', '2023-11-15T03:43:20+05:30'];
        yield 'three quarters of an hour' => ['+0545', '2023-11-15T03:58:20+05:45'];
        yield 'negative' => ['-0700', '2023-11-14T15:13:20-07:00'];
        yield 'negative, with minutes' => ['-0330', '2023-11-14T18:43:20-03:30'];
        yield 'negative, under an hour' => ['-0030', '2023-11-14T21:43:20-00:30'];
        yield 'unknown' => ['-0000', '2023-11-14T22:13:20+00:00'];
        yield 'easternmost' => ['+1400', '2023-11-15T12:13:20+14:00'];
        yield 'westernmost' => ['-1200', '2023-11-14T10:13:20-12:00'];
        yield 'beyond 14 hours' => ['+1500', '2023-11-14T22:13:20+00:00'];
        yield 'beyond 59 minutes' => ['+0560', '2023-11-14T22:13:20+00:00'];
    }

    #[DataProvider('provideBare')]
    public function testGetHash(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame(self::LONGFILE_COMMIT, $commit->getHash());
    }

    #[DataProvider('provideBare')]
    public function testInvalideHashThrowException(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('"that-hash-doest-not-exists" is not the hash of a commit: get a commit by its name with getRevision(...)->getCommit().');

        new Commit($repository, 'that-hash-doest-not-exists');
    }

    #[DataProvider('provideBare')]
    public function testGetShortHash(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('4f17752', $commit->getShortHash(), 'Short hash');
    }

    /**
     * With log.showSignature, which the configuration of the tests sets, git
     * prints the verification of the signature before the hash.
     */
    public function testGetShortHashOfASignedCommit(): void
    {
        $repository = self::createFoobarRepository();

        $this->assertSame('e1a83f1', $repository->getCommit(self::SIGNED_COMMIT)->getShortHash());
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
        $this->assertSame(self::BEFORE_LONGFILE_COMMIT, $parents[0], 'Parent hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetParentsWithOneParent(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);
        $parents = $commit->getParents();

        $this->assertCount(1, $parents, 'One parent found');
        $this->assertSame(self::BEFORE_LONGFILE_COMMIT, $parents[0]->getHash(), "First parents's hash is correct");
    }

    #[DataProvider('provideBare')]
    public function testGetTreeHash(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('b06890c7b10904979d2f69613c2ccda30aafe262', $commit->getTreeHash(), 'Tree hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetTree(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('b06890c7b10904979d2f69613c2ccda30aafe262', $commit->getTree()->getHash(), 'Tree hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetAuthorName(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('alice', $commit->getAuthorName(), 'Author name');
    }

    #[DataProvider('provideBare')]
    public function testGetAuthorEmail(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('alice@example.org', $commit->getAuthorEmail(), 'Author email');
    }

    #[DataProvider('provideBare')]
    public function testGetAuthorDate(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('2012-12-31T15:21:03+01:00', $commit->getAuthorDate()->format(\DATE_ATOM), 'Author date');
    }

    #[DataProvider('provideBare')]
    public function testGetCommitterName(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('alice', $commit->getCommitterName(), 'Committer name');
    }

    #[DataProvider('provideBare')]
    public function testGetCommitterEmail(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('alice@example.org', $commit->getCommitterEmail(), 'Committer email');
    }

    #[DataProvider('provideBare')]
    public function testGetCommitterDate(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('2012-12-31T15:21:03+01:00', $commit->getCommitterDate()->format(\DATE_ATOM), 'Committer date');
    }

    #[DataProvider('provideBare')]
    public function testGetMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('add a long file'."\n", $commit->getMessage());
    }

    #[DataProvider('provideBare')]
    public function testGetEmptyMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::NO_MESSAGE_COMMIT);

        $this->assertSame('', $commit->getMessage());
    }

    #[DataProvider('provideBare')]
    public function testGetEmptyMessageFromLog(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::NO_MESSAGE_COMMIT);
        $commitMessageFromLog = $commit->getLog()->getCommits()[0]->getMessage();

        $this->assertSame('', $commitMessageFromLog);
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
        $this->assertSame('signed commit'."\n", $commit->getMessage());
    }

    #[DataProvider('provideBare')]
    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testGetShortMessage(bool $bare): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Commit::getShortMessage()" method is deprecated, use symfony/string instead: "u($commit->getSubjectMessage())->truncate($length, $separator, !$preserve)".');

        $repository = self::createFoobarRepository($bare);
        // tests with a multi-line message
        $commit = $repository->getCommit(self::LONGMESSAGE_COMMIT);

        $this->assertSame('Fixed perm...', $commit->getShortMessage(10));
        $this->assertSame('Fixed perm!!!', $commit->getShortMessage(10, false, '!!!'));
        $this->assertSame('Fixed permissions!!!', $commit->getShortMessage(10, true, '!!!'));

        // tests with a single-line message
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertSame('Add README', $commit->getShortMessage(20));
        $this->assertSame('A', $commit->getShortMessage(1, false, ''));
        $this->assertSame('Add!!!', $commit->getShortMessage(1, true, '!!!'));
    }

    public function testSubjectIsTheFirstParagraph(): void
    {
        $repository = self::createFoobarRepository();
        $tree = $repository->getCommit(self::INITIAL_COMMIT)->getTreeHash();
        $hash = self::writeObject($repository, 'commit', "tree {$tree}\nauthor A <a@example.com> 1000000000 +0000\ncommitter A <a@example.com> 1000000000 +0000\n\nSubject\nsecond line\n\nBody\n");
        $commit = $repository->getCommit($hash);

        $this->assertSame('Subject second line', $commit->getSubjectMessage());
        $this->assertSame("Body\n", $commit->getBodyMessage());
    }

    #[DataProvider('provideBare')]
    public function testGetBodyMessage(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGMESSAGE_COMMIT);
        $nl = \chr(10);
        $message = "If you want to know everything,{$nl}I ran something like `chmox +x test.sh`{$nl}{$nl}Hello and good bye.{$nl}";
        $this->assertSame($message, $commit->getBodyMessage());

        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertSame('', $commit->getBodyMessage());
    }

    #[DataProvider('provideBare')]
    #[Group('legacy')]
    #[IgnoreDeprecations]
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

        $this->assertSame($repository->getReferences()->getBranches(), $commit->getIncludingBranches());
    }

    #[DataProvider('provideBare')]
    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetIncludingLocalOrRemoteBranches(bool $bare): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Passing arguments to "Gitonomy\Git\Commit::getIncludingBranches()" is deprecated, filter the branches it returns with "Gitonomy\Git\Reference\Branch::isLocal()" or "isRemote()" instead.');

        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertSame($repository->getReferences()->getLocalBranches(), $commit->getIncludingBranches(true, false));
        $this->assertSame($repository->getReferences()->getRemoteBranches(), $commit->getIncludingBranches(false, true));
    }

    public function testGetIncludingBranchesNamedLikeRemoteOnes(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'branch', ['remotes/origin/fake', self::INITIAL_COMMIT]);

        $branches = $repository->getCommit(self::INITIAL_COMMIT)->getIncludingBranches();

        $this->assertContains($repository->getReferences()->getBranch('remotes/origin/fake'), $branches);
    }

    /**
     * gitlib doesn't see the writes of another process, but doesn't fail on them.
     */
    public function testGetIncludingBranchesCreatedByAnotherProcess(): void
    {
        $repository = self::createFoobarRepository();
        $repository->getReferences()->getAll();
        new Process([self::gitCommand(), 'branch', 'elsewhere', self::INITIAL_COMMIT], $repository->getPath(), self::gitEnvs())->mustRun();

        $fullnames = array_map(static fn (Branch $branch): string => $branch->getFullname(), $repository->getCommit(self::INITIAL_COMMIT)->getIncludingBranches());

        $this->assertContains('refs/heads/elsewhere', $fullnames);
    }

    /**
     * A mirror leaves the default branch of a remote as a regular reference,
     * which the branches leave out, like a symbolic one.
     */
    public function testGetIncludingBranchesLeavesOutTheDefaultBranchOfARemote(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'update-ref', ['--no-deref', 'refs/remotes/origin/HEAD', self::INITIAL_COMMIT]);

        $fullnames = array_map(static fn (Branch $branch): string => $branch->getFullname(), $repository->getCommit(self::INITIAL_COMMIT)->getIncludingBranches());

        $this->assertNotContains('refs/remotes/origin/HEAD', $fullnames);
        $this->assertContains('refs/heads/master', $fullnames);
    }

    #[DataProvider('provideBare')]
    public function testGetLastModification(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $lastModification = $commit->getLastModification('image.jpg');

        $this->assertSame(self::BEFORE_LONGFILE_COMMIT, $lastModification->getHash(), 'Last modification is current commit');
    }

    #[DataProvider('provideBare')]
    public function testMergeCommit(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::MERGE_COMMIT);

        $this->assertSame("Merge branch 'authors'", $commit->getSubjectMessage());
    }

    #[DataProvider('provideBare')]
    public function testEncoding(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::ENCODING_COMMIT);

        $this->assertSame('contribute to AUTHORS file', $commit->getSubjectMessage());
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

    /**
     * Whoever can push refs/replace/* to a repository would change what an
     * application shows for a hash: like libgit2, gitlib ignores them.
     */
    public function testReplacedObjectsAreReadAsTheyAre(): void
    {
        $repository = self::createFoobarRepository();
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);
        $message = $commit->getMessage();
        $blob = $commit->getTree()->resolvePath('README.md');
        $this->assertInstanceOf(Blob::class, $blob);
        $content = $blob->getContent();
        self::git($repository, 'replace', [self::LONGFILE_COMMIT, self::writeObject($repository, 'commit', "tree {$commit->getTreeHash()}\nauthor A <a@example.com> 1000000000 +0000\ncommitter A <a@example.com> 1000000000 +0000\n\nFake\n")]);
        self::git($repository, 'replace', [$blob->getHash(), self::writeObject($repository, 'blob', "Fake\n")]);

        $repository = new Repository($repository->getPath(), driver: self::getDriver());

        $this->assertSame($message, $repository->getCommit(self::LONGFILE_COMMIT)->getMessage());
        $this->assertSame($message, $repository->getLog(self::LONGFILE_COMMIT, null, null, 1)->getSingleCommit()->getMessage());
        $this->assertSame($content, $repository->getBlob($blob->getHash())->getContent());
    }

    /**
     * Without "--", git reads a revision named like a file of the working
     * tree as ambiguous.
     */
    public function testAFileNamedLikeACommitChangesNothing(): void
    {
        $repository = self::createFoobarRepository(false);
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);
        $parent = $commit->getParentHashes()[0];
        touch($repository->getWorkingDir().'/'.self::LONGFILE_COMMIT);
        touch($repository->getWorkingDir().'/'.$parent);

        $repository = new Repository($repository->getPath(), driver: self::getDriver());
        $commit = $repository->getCommit(self::LONGFILE_COMMIT);

        $this->assertSame('add a long file', $commit->getSubjectMessage());
        $this->assertSame(substr(self::LONGFILE_COMMIT, 0, 7), $commit->getShortHash());
        $this->assertSame(['long.php'], array_map(static fn (File $file): string => $file->getName(), $commit->getDiff()->getFiles()));
        $this->assertNotSame([], $repository->getWorkingCopy()->getDiff($parent)->getFiles());
    }
}
