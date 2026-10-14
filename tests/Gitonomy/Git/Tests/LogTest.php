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
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Driver\Cli\Parser\LogParser;
use Gitonomy\Git\Driver\Ffi\LibGit2Driver;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Exception\UnsupportedOperationException;
use Gitonomy\Git\Log;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\TestWith;

class LogTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testRevisionAndPath(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $logReadme = $repository->getLog(self::LONGFILE_COMMIT, 'README');
        $logImage = $repository->getLog(self::LONGFILE_COMMIT, 'image.jpg');

        $this->assertCount(3, $logReadme);
        $this->assertCount(2, $logImage);
    }

    #[DataProvider('provideBare')]
    public function testGetCommits(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $log = $repository->getLog(self::LONGFILE_COMMIT, null, null, 3);

        $commits = $log->getCommits();

        $this->assertCount(3, $commits, '3 commits in log');
        $this->assertSame(self::LONGFILE_COMMIT, $commits[0]->getHash(), 'First is requested one');
        $this->assertSame(self::BEFORE_LONGFILE_COMMIT, $commits[1]->getHash(), "Second is longfile parent\\'s");
    }

    #[DataProvider('provideBare')]
    public function testCountCommits(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $log = $repository->getLog(self::LONGFILE_COMMIT, null, 2, 3);

        $this->assertSame(8, $log->countCommits(), '8 commits found in history');
    }

    #[DataProvider('provideBare')]
    public function testCountAllCommits(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $log = $repository->getLog();

        $this->assertGreaterThan(100, $log->countCommits(), 'Returns all commits from all branches');
    }

    #[DataProvider('provideBare')]
    public function testIterable(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $log = $repository->getLog(self::LONGFILE_COMMIT);

        $expectedHashes = [self::LONGFILE_COMMIT, self::BEFORE_LONGFILE_COMMIT];
        foreach ($log as $entry) {
            $hash = array_shift($expectedHashes);
            $this->assertSame($hash, $entry->getHash());
            if (0 == \count($expectedHashes)) {
                break;
            }
        }
    }

    public function testFirstMessageEmpty(): void
    {
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', '');
        // Edge case: first commit lacks a message.
        $commit = self::writeObject($repository, 'commit', "tree {$tree}\nauthor Unit Test <unit_test@unit-test.com> 1000000000 +0000\ncommitter Unit Test <unit_test@unit-test.com> 1000000000 +0000\n\n");
        self::git($repository, 'update-ref', ['refs/heads/master', $commit]);

        $commits = $repository->getLog()->getCommits();
        $this->assertCount(1, $commits);
        $this->assertSame('', $commits[0]->getMessage());
    }

    public function testParsesCommitsWithAndWithoutGitButlerHeaders(): void
    {
        $logContent = <<<'EOT'
            commit 1111111111111111111111111111111111111111
            tree abcdefabcdefabcdefabcdefabcdefabcdefabcd
            author John Doe <john@example.com> 1620000000 +0000
            committer John Doe <john@example.com> 1620000000 +0000

                First commit message

            commit 2222222222222222222222222222222222222222
            tree abcdefabcdefabcdefabcdefabcdefabcdefabcd
            parent 1111111111111111111111111111111111111111
            author Jane Smith <jane@example.com> 1620003600 +0000
            committer Jane Smith <jane@example.com> 1620003600 +0000
            gitbutler-headers-version: 2
            gitbutler-change-id: a7bd485c-bae6-45b2-910f-163c78aace81

                Commit with GitButler headers

            commit 3333333333333333333333333333333333333333
            tree abcdefabcdefabcdefabcdefabcdefabcdefabcd
            author John Doe <john@example.com> 1620007200 +0000
            committer Jane Smith <jane@example.com> 1620007200 +0000

                Another commit without GitButler headers

            EOT;

        $parser = new LogParser();
        $parser->parse($logContent);

        $log = $parser->commits;
        $this->assertCount(3, $log);

        $this->assertSame("First commit message\n", $log[0]->message);
        $this->assertSame("Commit with GitButler headers\n", $log[1]->message);
        $this->assertSame("Another commit without GitButler headers\n", $log[2]->message);
    }

    #[DataProvider('provideBare')]
    public function testLogOnPathSkipsMergesThatDoNotChangeIt(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $log = $repository->getLog(null, 'README.md');

        $expected = ['1917a09c23eb0129d38d4e6d6a492c7830f8d423', 'd8930cd3f7e343195b85d56408a701feb98b95a8'];
        $this->assertSame($expected, array_values(array_map(static fn (Commit $commit) => $commit->getHash(), $log->getCommits())));
        $this->assertCount(2, $log);
    }

    public function testLogOfRanges(): void
    {
        $repository = self::createFoobarRepository(false);

        $this->assertCount(21, $repository->getLog('master...origin/new-feature'));
        $this->assertCount(21, $repository->getLog('master...origin/new-feature')->getCommits());
        $this->assertCount(19, $repository->getLog('origin/new-feature..master')->getCommits());
    }

    public function testLogOnPathSkipsTheHistoryAMergeDiscards(): void
    {
        $repository = self::createRepositoryToCommitTo();
        $initial = self::commit($repository, 'file', 'initial');
        self::git($repository, 'checkout', ['-b', 'side']);
        self::commit($repository, 'file', 'side');
        self::git($repository, 'checkout', ['main']);
        self::commit($repository, 'other', 'main');
        // Keeps the file of main: git doesn't walk the side branch for it.
        self::git($repository, 'merge', ['-s', 'ours', '-m', 'Merge side', 'side']);

        $this->assertSame([$initial], self::hashes($repository->getLog('main', 'file')));
        $this->assertSame($initial, $repository->getHeadCommit()->getLastModification('file')->getHash());
    }

    public function testLogOfRangeOnPathOnlySimplifiesMergesToTheBottom(): void
    {
        $repository = self::createRepositoryToCommitTo();
        self::commit($repository, 'file', 'initial');
        self::git($repository, 'checkout', ['-b', 'topic']);
        self::commit($repository, 'other', 'topic');
        $topic = self::commit($repository, 'file', 'topic');
        self::git($repository, 'checkout', ['main']);
        self::commit($repository, 'file', 'main');
        self::git($repository, 'checkout', ['topic']);
        self::git($repository, 'merge', ['-X', 'theirs', '-m', 'Merge main', 'main']);
        $merge = $repository->getHeadCommit()->getHash();

        // The merge has the file of main, the bottom of the range: git follows main only.
        $this->assertSame([], self::hashes($repository->getLog('main..topic', 'file')));

        self::git($repository, 'checkout', ['main']);
        self::commit($repository, 'another', 'main');

        // The merged commit is hidden, but no longer the bottom: git follows every parent. The
        // first commit of topic is like its hidden parent on the file: git skips it.
        $this->assertSame([$merge, $topic], self::hashes($repository->getLog('main..topic', 'file')));
    }

    /**
     * @param list<string> $paths
     */
    #[TestWith([[]])]
    #[TestWith([['file']])]
    public function testLogOfSeveralRevisionsStartsFromTheNewest(array $paths): void
    {
        $repository = self::createEmptyRepository();
        $tree = self::writeObject($repository, 'tree', "100644 file\0".hex2bin(self::writeObject($repository, 'blob', 'content')));
        $commit = static fn (string $message, int $time): string => self::writeObject($repository, 'commit', "tree {$tree}\nauthor Unit Test <unit_test@unit-test.com> {$time} +0000\ncommitter Unit Test <unit_test@unit-test.com> {$time} +0000\n\n{$message}\n");
        $old = $commit('old', 1000000000);
        $new = $commit('new', 1000000001);
        $sameAsOld = $commit('same as old', 1000000000);

        $this->assertSame([$new, $old], self::hashes($repository->getLog([$old, $new], $paths)));
        $this->assertSame([$new, $old], self::hashes($repository->getLog([$new, $old], $paths)));
        // At equal dates, from the first given one.
        $this->assertSame([$sameAsOld, $old], self::hashes($repository->getLog([$sameAsOld, $old], $paths)));
        $this->assertSame([$old, $sameAsOld], self::hashes($repository->getLog([$old, $sameAsOld], $paths)));
    }

    public function testLogOfAPathOutsideTheRepositoryThrows(): void
    {
        $repository = self::createFoobarRepository();

        // Like git.
        $this->expectException(DriverException::class);

        $repository->getLog('master', '/README.md')->getCommits();
    }

    public function testLogOfAnAbsolutePathInTheWorkingTree(): void
    {
        $repository = self::createFoobarRepository(false);

        $this->assertSame(
            array_map(static fn (Commit $commit): string => $commit->getHash(), $repository->getLog('master', 'README.md')->getCommits()),
            array_map(static fn (Commit $commit): string => $commit->getHash(), $repository->getLog('master', $repository->getWorkingDir().'/README.md')->getCommits()),
        );
    }

    public function testLogOnWildcardPath(): void
    {
        $repository = self::createRepositoryToCommitTo();
        mkdir($repository->getWorkingDir().'/dir');
        self::commit($repository, 'dir/file.md', 'initial');
        $text = self::commit($repository, 'dir/file.txt', 'text');
        self::commit($repository, 'dir/file.md', 'markdown');

        $this->assertSame([$text], self::hashes($repository->getLog('main', 'dir/*.txt')));
    }

    public function testLogOfAPathNormalizesIt(): void
    {
        $repository = self::createRepositoryToCommitTo();
        mkdir($repository->getWorkingDir().'/dir');
        $file = self::commit($repository, 'dir/file.txt', 'file');
        self::commit($repository, 'other.txt', 'other');

        // Like git.
        foreach (['dir/.', 'dir//file.txt', 'other/../dir/file.txt', './dir/', $repository->getWorkingDir().'/dir/../dir'] as $path) {
            $this->assertSame([$file], self::hashes($repository->getLog('main', $path)), $path);
        }
    }

    public function testLogOfAPathAboveTheRootThrows(): void
    {
        $repository = self::createRepositoryToCommitTo();
        self::commit($repository, 'file.txt', 'file');

        // Like git.
        $this->expectException(DriverException::class);
        if (self::getDriver() instanceof LibGit2Driver) {
            $this->expectExceptionMessage('The path "dir/../../file.txt" is outside the repository.');
        } else {
            // git 1.8.4 doesn't repeat the path first, and recent versions name the repository after it.
            $this->expectExceptionMessageMatches('/^The git command "log" failed \(exit 128\): fatal: (?:dir\/\.\.\/\.\.\/file\.txt: )?\'dir\/\.\.\/\.\.\/file\.txt\' is outside repository/');
        }

        $repository->getLog('main', 'dir/../../file.txt')->getCommits();
    }

    public function testLogOfAPathWithMagic(): void
    {
        $repository = self::createRepositoryToCommitTo();
        mkdir($repository->getWorkingDir().'/dir');
        self::commit($repository, 'dir/file.txt', 'file');
        $other = self::commit($repository, 'other.txt', 'other');

        if (self::getDriver() instanceof LibGit2Driver) {
            $this->expectException(UnsupportedOperationException::class);
            $this->expectExceptionMessage('The path ":/other.txt" uses the magic of git\'s pathspecs, which LibGit2Driver doesn\'t support; use CliDriver.');
        }

        // From the root of the repository, whatever the current directory.
        $this->assertSame([$other], self::hashes($repository->getLog('main', ':/other.txt')));
    }

    public function testLogOfAllReferencesIgnoresTheOnesToOtherObjects(): void
    {
        $repository = self::createRepositoryToCommitTo();
        $commit = self::commit($repository, 'file', 'initial');
        self::git($repository, 'update-ref', ['refs/tags/tree', $repository->getCommit($commit)->getTreeHash()]);
        self::git($repository, 'symbolic-ref', ['refs/heads/dangling', 'refs/heads/missing']);

        $this->assertSame([$commit], self::hashes($repository->getLog()));
        $this->assertSame(1, $repository->getLog()->countCommits());
    }

    #[DataProvider('provideBare')]
    public function testCountIsTheNumberOfCommitsIterated(bool $bare): void
    {
        $log = self::createFoobarRepository($bare)->getLog(self::LONGFILE_COMMIT);
        $total = $log->countCommits();

        $this->assertCount($total, $log);
        $this->assertCount(3, $log->withLimit(3));
        $this->assertCount(3, $log->withLimit(3));
        $this->assertCount($total - 2, $log->withOffset(2));
        $this->assertCount(0, $log->withOffset($total + 1));
        $this->assertSame($total, $log->withLimit(3)->countCommits(), 'countCommits() ignores the offset and the limit');
    }

    #[DataProvider('provideBare')]
    public function testWithersReturnAnotherLog(bool $bare): void
    {
        $log = self::createFoobarRepository($bare)->getLog(self::LONGFILE_COMMIT, offset: 1);

        $limited = $log->withLimit(2);

        $this->assertNull($log->getLimit());
        $this->assertSame(2, $limited->getLimit());
        $this->assertSame(1, $limited->getOffset());
        $this->assertSame(\array_slice(self::hashes($log), 0, 2), self::hashes($limited));
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacySetLimit(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Log::setLimit()" method is deprecated, use "withLimit()" instead.');

        $log = self::createFoobarRepository()->getLog(self::LONGFILE_COMMIT);

        $this->assertSame($log, $log->setLimit(1));
        $this->assertCount(1, $log->getCommits());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyGetDiffIgnoresThePathsOfTheLog(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Log::getDiff()" method is deprecated, use "Gitonomy\Git\Repository::getDiff()" instead.');

        $repository = self::createFoobarRepository();
        $diff = $repository->getDiff(self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT)->toArray();
        $legacyDiff = $repository->getLog(self::BEFORE_LONGFILE_COMMIT.'..'.self::LONGFILE_COMMIT, 'README.md')->getDiff();

        // A log of README.md, but the diff of the range, which changes long.php only.
        $this->assertSame(['long.php'], array_map(static fn (File $file): string => $file->getName(), $legacyDiff->getFiles()));
        $this->assertSame($diff, $legacyDiff->toArray());
        $this->assertSame($diff, $repository->getLog([self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT])->getDiff()->toArray());
    }

    public function testLogOfExcludedAndSymmetricRevisions(): void
    {
        $repository = self::createRepositoryToCommitTo();
        $base = self::commit($repository, 'file', 'base');
        self::git($repository, 'checkout', ['-q', '-b', 'side']);
        $side = self::commit($repository, 'file', 'side');
        self::git($repository, 'checkout', ['-q', 'main']);
        $main = self::commit($repository, 'other', 'main');

        $this->assertSame([$main], self::hashes($repository->getLog(['main', '^side'])));
        $this->assertEqualsCanonicalizing([$main, $side], self::hashes($repository->getLog('main...side')));
        $this->assertSame([$side], self::hashes($repository->getLog('main..side')));
        $this->assertCount(3, $repository->getLog('--all'));
        $this->assertSame([], self::hashes($repository->getLog('^main')));
        $this->assertSame([$main, $base], self::hashes($repository->getLog('main')));
    }

    /**
     * The command line of a process has a limit, 2 MB by default on Linux: a
     * log of thousands of branches would exceed it.
     */
    public function testLogOfMoreRevisionsThanACommandLineTakes(): void
    {
        $repository = self::createFoobarRepository();
        // 4 MB of hashes.
        $log = $repository->getLog(array_fill(0, 100_000, $repository->getCommit(self::LONGFILE_COMMIT)));

        $hashes = self::hashes($repository->getLog(self::LONGFILE_COMMIT));
        $this->assertSame($hashes, self::hashes($log));
        $this->assertCount(\count($hashes), $log);
    }

    public function testLogOfAnUnknownRevisionNamesIt(): void
    {
        $repository = self::createFoobarRepository();

        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('"unknown"');

        $repository->getLog('master..unknown', 'README.md')->getCommits();
    }

    public function testLogRefusesOptions(): void
    {
        $repository = self::createFoobarRepository();

        $this->expectException(InvalidArgumentException::class);

        $repository->getLog('--output=/dev/null')->getCommits();
    }

    public function testParsesCommitsMergingTagsAndSignedOnes(): void
    {
        $repository = self::createRepositoryToCommitTo();
        $tree = trim(self::git($repository, 'write-tree'));
        $signed = self::writeObject($repository, 'commit', "tree {$tree}\nauthor A <a@example.com> 1000000000 +0000\ncommitter A <a@example.com> 1000000000 +0000\ngpgsig -----BEGIN PGP SIGNATURE-----\n \n signature\n -----END PGP SIGNATURE-----\n\nSigned\n");
        $other = self::writeObject($repository, 'commit', "tree {$tree}\nparent {$signed}\nauthor A <a@example.com> 1000000001 +0000\ncommitter A <a@example.com> 1000000001 +0000\n\nOther\n");
        // An unsigned tag: no "END PGP SIGNATURE" line in its "mergetag" header.
        $merge = self::writeObject($repository, 'commit', "tree {$tree}\nparent {$other}\nparent {$signed}\nauthor A <a@example.com> 1000000002 +0000\ncommitter A <a@example.com> 1000000002 +0000\nmergetag object {$signed}\n type commit\n tag v1\n tagger A <a@example.com> 1000000000 +0000\n \n Tag v1\n\nMerge tag v1\n");
        self::git($repository, 'update-ref', ['refs/heads/main', $merge]);

        $commits = $repository->getLog('main')->getCommits();

        $this->assertSame([$merge, $other, $signed], array_map(static fn (Commit $commit): string => $commit->getHash(), $commits));
        $this->assertSame("Merge tag v1\n", $commits[0]->getMessage());
        $this->assertSame("Signed\n", $commits[2]->getMessage());
    }

    #[DataProvider('provideBare')]
    public function testLogOfTheRoot(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);

        $this->assertSame(self::hashes($repository->getLog(self::LONGFILE_COMMIT, ['README.md', 'long.php'])), self::hashes($repository->getLog(self::LONGFILE_COMMIT, ['./README.md', './long.php'])));
        $this->assertCount(\count($repository->getLog(self::LONGFILE_COMMIT)), $repository->getLog(self::LONGFILE_COMMIT, '.'));
    }

    private static function createRepositoryToCommitTo(): Repository
    {
        $repository = self::createEmptyRepository(false);
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        self::git($repository, 'symbolic-ref', ['HEAD', 'refs/heads/main']);

        return $repository;
    }

    /**
     * Commits $file, with $content as content and message.
     *
     * @return string The hash of the commit
     */
    private static function commit(Repository $repository, string $file, string $content): string
    {
        // Ends with a line break: git 1.8 adds one when it merges a file without.
        file_put_contents($repository->getWorkingDir().'/'.$file, $content."\n");
        self::git($repository, 'add', [$file]);
        self::git($repository, 'commit', ['-m', $content]);

        return trim(self::git($repository, 'rev-parse', ['HEAD']));
    }

    /**
     * @return list<string>
     */
    private static function hashes(Log $log): array
    {
        return array_values(array_map(static fn (Commit $commit): string => $commit->getHash(), $log->getCommits()));
    }
}
