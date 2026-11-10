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
use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
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

        try {
            $repository->getCommit($hash)->getAuthorDate();
            $this->fail('The date is out of range.');
        } catch (RuntimeException $e) {
            // A RuntimeException of gitlib, not of PHP: git prints the date, which libgit2 refuses to parse.
            if (self::getDriver() instanceof CliDriver) {
                $this->assertSame(RuntimeException::class, $e::class);
                $this->assertSame('The date "99999999999999999999" is out of range.', $e->getMessage());
            } else {
                $this->assertSame(DriverException::class, $e::class);
                $this->assertSame(\sprintf('Unable to read the commit "%s": failed to parse signature - invalid Unix timestamp.', $hash), $e->getMessage());
            }
        }
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
     * Old commits, imported from other tools, have signatures git wouldn't
     * write anymore, which git and libgit2 read anyway: one of them made the
     * whole log throw.
     */
    #[DataProvider('provideMalformedSignatures')]
    public function testAMalformedSignatureIsReadLikeGit(string $signature, string $name, string $email, string $date): void
    {
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', '');
        $hash = self::writeObject($repository, 'commit', "tree {$tree}\nauthor {$signature}\ncommitter {$signature}\n\nMalformed\n");

        $commits = $repository->getLog($hash)->getCommits();

        $this->assertCount(1, $commits);
        $this->assertSame([$name, $email, $date], [$commits[0]->getAuthorName(), $commits[0]->getAuthorEmail(), $commits[0]->getAuthorDate()->format(\DATE_ATOM)]);
        $this->assertSame([$name, $email, $date], [$commits[0]->getCommitterName(), $commits[0]->getCommitterEmail(), $commits[0]->getCommitterDate()->format(\DATE_ATOM)]);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideMalformedSignatures(): iterable
    {
        yield 'no space before the email' => ['A<a@example.com> 1700000000 +0200', 'A', 'a@example.com', '2023-11-15T00:13:20+02:00'];
        yield 'no date' => ['A <a@example.com>', 'A', 'a@example.com', '1970-01-01T00:00:00+00:00'];
        yield 'an offset of 5 digits' => ['A <a@example.com> 1700000000 +05030', 'A', 'a@example.com', '2023-11-14T22:13:20+00:00'];
        yield 'no name nor email' => ['<> 0 +0000', '', '', '1970-01-01T00:00:00+00:00'];
        yield 'a date before 1970' => ['A <a@example.com> -1 +0100', 'A', 'a@example.com', '1970-01-01T00:00:00+00:00'];
    }

    /**
     * Like git log, which shows the last "author" and "committer" headers of
     * a commit with several, as some tools wrote: libgit2 reads the first.
     */
    public function testTheLastOfSeveralSignaturesIsRead(): void
    {
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', '');
        $hash = self::writeObject($repository, 'commit', "tree {$tree}\nauthor First <first@example.com> 1700000000 +0100\nauthor Last <last@example.com> 1700000100 +0200\ncommitter First <first@example.com> 1700000200 +0300\ncommitter Last <last@example.com> 1700000300 +0400\n\nSeveral\n");

        $commit = $repository->getCommit($hash);

        $this->assertSame(['Last', 'last@example.com', '2023-11-15T00:15:00+02:00'], [$commit->getAuthorName(), $commit->getAuthorEmail(), $commit->getAuthorDate()->format(\DATE_ATOM)]);
        $this->assertSame(['Last', 'last@example.com', '2023-11-15T02:18:20+04:00'], [$commit->getCommitterName(), $commit->getCommitterEmail(), $commit->getCommitterDate()->format(\DATE_ATOM)]);
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

    /**
     * "git log" abbreviates the hash of a commit only, and gives the one of
     * the commit of a tag.
     */
    public function testTheDriverAbbreviatesTheHashOfAnyObject(): void
    {
        $repository = self::createFoobarRepository();
        $driver = $repository->getRepositoryDriver();
        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTreeHash();
        $tag = self::writeObject($repository, 'tag', \sprintf("object %s\ntype commit\ntag short\ntagger A <a@example.com> 1700000000 +0000\n\nShort\n", self::LONGFILE_COMMIT));

        $this->assertSame(substr($tree, 0, 7), $driver->getShortHash($tree));
        $this->assertSame(substr($tag, 0, 7), $driver->getShortHash($tag));

        $this->expectException(ReferenceNotFoundException::class);

        $driver->getShortHash(str_repeat('1', 40));
    }

    /**
     * Like git, which reads the tree, then the parents, on the first lines of
     * a commit: a forged commit showed another tree and other parents than
     * the ones git logs and checks out.
     */
    public function testTheTreeAndTheParentsAreTheFirstHeadersOfACommit(): void
    {
        $repository = self::createFoobarRepository();
        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTreeHash();
        $hash = self::writeObject($repository, 'commit', \sprintf("tree %s\nparent %s\nauthor A <a@example.com> 1700000000 +0000\ncommitter A <a@example.com> 1700000000 +0000\nparent %s\ntree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n\nForged\n", $tree, self::LONGFILE_COMMIT, self::INITIAL_COMMIT));

        $commit = $repository->getCommit($hash);

        $this->assertSame($tree, $commit->getTreeHash());
        $this->assertSame([self::LONGFILE_COMMIT], $commit->getParentHashes());
        $this->assertSame("Forged\n", $commit->getMessage());
    }

    /**
     * A shallow clone has the commits it cuts the history at, without their
     * parents: git logs and diffs them as root commits.
     */
    public function testACommitAShallowCloneCutsTheHistoryAtHasNoParent(): void
    {
        $dir = self::createTempDir();
        new Process([self::gitCommand(), 'clone', '-q', '--depth', '1', 'file://'.self::createFoobarRepository()->getGitDir(), $dir])->mustRun();
        $repository = new Repository($dir, driver: self::getDriver());

        $commit = $repository->getHeadCommit();

        $this->assertSame([], $commit->getParentHashes());
        $this->assertSame([], $commit->getParents());
        $this->assertContains('README.md', array_map(static fn (File $file): string => $file->getName(), $commit->getDiff()->getFiles()), 'Every file is new');
        $this->assertSame([$commit], $repository->getLog()->getCommits());
    }

    /**
     * The repository keeps its "shallow" file, not the worktree.
     */
    public function testACommitOfALinkedWorktreeOfAShallowCloneHasNoParent(): void
    {
        if (version_compare(self::gitVersion(), '2.5.0', '<')) {
            $this->markTestSkipped('git worktree requires git 2.5.');
        }
        if (!self::getDriver() instanceof CliDriver) {
            $this->markTestSkipped('libgit2 1.9 looks for the "shallow" file in the git directory of the worktree.');
        }

        $dir = self::createTempDir();
        new Process([self::gitCommand(), 'clone', '-q', '--depth', '1', 'file://'.self::createFoobarRepository()->getGitDir(), $dir])->mustRun();
        $worktree = self::createTempDir().'/worktree';
        self::git(new Repository($dir, driver: self::getDriver()), 'worktree', ['add', '-b', 'in-worktree', $worktree]);

        $this->assertSame([], new Repository($worktree, driver: self::getDriver())->getHeadCommit()->getParentHashes());
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

    public function testTheLastModificationOfAPathNoCommitChanges(): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('No commit up to "%s" changes "nope".', self::LONGFILE_COMMIT));

        self::createFoobarRepository()->getCommit(self::LONGFILE_COMMIT)->getLastModification('nope');
    }

    #[DataProvider('provideBare')]
    public function testGetIncludingBranches(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getCommit(self::INITIAL_COMMIT);

        $this->assertSame($repository->getReferences()->getBranches(), $commit->getIncludingBranches());
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
        // Written as an object: Windows passes no ISO-8859-1 argument to "git commit".
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', '');
        $hash = self::writeObject($repository, 'commit', "tree {$tree}\nauthor Andr\xe9 <andre@example.com> 1700000000 +0000\ncommitter Andr\xe9 <andre@example.com> 1700000000 +0000\nencoding ISO-8859-1\n\nCaf\xe9\n");

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
