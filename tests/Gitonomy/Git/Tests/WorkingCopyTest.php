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

use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\LogicException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\FastForward;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

class WorkingCopyTest extends AbstractTestCase
{
    public function testNoWorkingCopyInBare(): void
    {
        $this->expectException(LogicException::class);

        $path = self::createTempDir();
        $repo = Repository::initBare($path, driver: self::getDriver());

        $repo->getWorkingCopy();
    }

    public function testCheckout(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkoutNewBranch('new-feature', 'origin/new-feature');

        $head = $repository->getHead();
        $this->assertInstanceOf(Branch::class, $head, 'HEAD is a branch');
        $this->assertSame('new-feature', $head->getName(), 'HEAD is branch new-feature');
    }

    public function testCheckoutOfABranchObjectAttachesHead(): void
    {
        $repository = self::createFoobarRepository(false);
        $branch = $repository->getReferences()->createBranch('twin', 'master');
        $wc = $repository->getWorkingCopy();

        $wc->checkout($repository->getRevision(self::INITIAL_COMMIT));
        $this->assertTrue($repository->isHeadDetached());

        $wc->checkout($branch);
        $this->assertSame('refs/heads/twin', self::headBranch($repository)->getFullname());
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyCheckoutOfANewBranch(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Passing a branch to create to "Gitonomy\Git\WorkingCopy::checkout()" is deprecated, use "checkoutNewBranch()" instead.');

        $repository = self::createFoobarRepository(false);
        $repository->getWorkingCopy()->checkout('origin/new-feature', 'new-feature');

        $this->assertSame('new-feature', self::headBranch($repository)->getName());
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
        $this->assertSame(40, \strlen((string) $files[0]->getOldIndex()));
    }

    public function testCheckoutUnexisting(): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The revision "foobar" does not exist, or names no commit.');

        self::createFoobarRepository(false)->getWorkingCopy()->checkout('foobar');
    }

    /**
     * "rev-parse --verify" refuses a range, which names two commits, after
     * printing them.
     */
    public function testCheckoutOfARangeThrows(): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The revision "master...origin/new-feature" does not exist, or names no commit.');

        self::createFoobarRepository(false)->getWorkingCopy()->checkout('master...origin/new-feature');
    }

    /**
     * Like "git checkout :/text": the youngest commit whose message matches.
     */
    public function testCheckoutOfACommitByItsMessage(): void
    {
        $repository = self::createFoobarRepository(false);

        $repository->getWorkingCopy()->checkout(':/add a long file');

        $this->assertTrue($repository->isHeadDetached());
        $this->assertSame(self::LONGFILE_COMMIT, $repository->getHeadCommit()->getHash());
    }

    /**
     * "git checkout README.md" restores the file, discarding its changes.
     */
    #[DataProvider('providePaths')]
    public function testCheckoutOfAPathIsRefused(string $path): void
    {
        $repository = self::createFoobarRepository(false);
        $file = $repository->getWorkingDir().'/README.md';
        file_put_contents($file, 'local change');

        try {
            $repository->getWorkingCopy()->checkout($path);
            $this->fail('A path is no revision.');
        } catch (ReferenceNotFoundException $e) {
            $this->assertSame(\sprintf('The revision "%s" does not exist, or names no commit.', $path), $e->getMessage());
        }

        $this->assertSame('local change', file_get_contents($file));
    }

    /**
     * The driver refuses a path too, whatever its caller resolved.
     */
    #[DataProvider('providePaths')]
    public function testTheDriverChecksOutNoPath(string $path): void
    {
        $repository = self::createFoobarRepository(false);
        $file = $repository->getWorkingDir().'/README.md';
        file_put_contents($file, 'local change');

        try {
            $repository->getRepositoryDriver()->checkout($path);
            $this->fail('A path is no revision.');
        } catch (DriverException) {
        }

        $this->assertSame('local change', file_get_contents($file));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePaths(): iterable
    {
        yield 'file' => ['README.md'];
        yield 'working tree' => ['.'];
    }

    /**
     * Unlike "git checkout feature", which creates the local branch of the
     * only remote one of this name.
     */
    public function testCheckoutOfTheNameOfARemoteBranchIsRefused(): void
    {
        $repository = self::createFoobarRepository(false);

        try {
            $repository->getWorkingCopy()->checkout('new-feature');
            $this->fail('No revision has this name.');
        } catch (ReferenceNotFoundException $e) {
            $this->assertSame('The revision "new-feature" does not exist, or names no commit.', $e->getMessage());
        }

        $this->assertFalse($repository->getReferences()->hasBranch('new-feature'));
        $this->assertSame('refs/heads/master', self::headBranch($repository)->getFullname());
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

    public function testMergeOfAnUnknownRevisionThrows(): void
    {
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The revision "foobar" does not exist, or names no commit.');

        self::createFoobarRepository(false)->getWorkingCopy()->merge('foobar');
    }

    public function testMergeConflict(): void
    {
        $repository = self::createFoobarRepository(false);
        $master = $repository->getHeadCommit()->getHash();
        $wc = $repository->getWorkingCopy();

        try {
            $wc->merge('origin/new-feature');
            $this->fail('The merge should have been refused.');
        } catch (DriverException) {
        }

        $this->assertSame($master, $repository->getHeadCommit()->getHash(), 'No merge commit is created');
    }

    public function testMergeOnlyFastForwards(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();

        $this->expectException(DriverException::class);

        $wc->merge('origin/diff-features', FastForward::Only);
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyMergeArguments(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: Passing arguments of "git merge" to "Gitonomy\Git\WorkingCopy::merge()" is deprecated, pass a "Gitonomy\Git\FastForward" instead.');

        $repository = self::createFoobarRepository(false);
        $head = $repository->getHeadCommit();

        $repository->getWorkingCopy()->merge($head->getHash(), ['--no-ff']);

        $this->assertSame($head->getHash(), $repository->getHeadCommit()->getHash());
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
        $this->assertSame('refs/heads/behind', self::headBranch($repository)->getFullname(), 'HEAD stays on the branch');
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
        $wc->merge($head->getHash(), FastForward::Never);

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
            $repository->getWorkingCopy()->checkoutNewBranch('pagination', 'origin/pagination');
            $this->fail('The checkout should have been refused.');
        } catch (DriverException) {
        }

        $this->assertSame('local change', file_get_contents($file));
        $repository = new Repository($repository->getPath(), driver: self::getDriver());
        $this->assertFalse($repository->getReferences()->hasBranch('pagination'), 'No branch is created when the checkout fails');
        $this->assertSame([], self::branchConfiguration($repository, 'pagination'), 'Nor its upstream');
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

    public function testCheckoutPrefersTheBranchToATagOfTheSameName(): void
    {
        $repository = self::createFoobarRepository(false);
        $references = $repository->getReferences();
        $references->createTag('twin', self::INITIAL_COMMIT);
        $references->createBranch('twin', 'master');

        $repository->getWorkingCopy()->checkout('twin');

        $this->assertSame('refs/heads/twin', self::headBranch($repository)->getFullname());
        $this->assertSame([], $repository->getWorkingCopy()->getDiffStaged()->getFiles());
    }

    /**
     * Like "git checkout -b", whatever the configuration: the one of the
     * tests sets branch.autoSetupMerge and branch.autoSetupRebase to "always".
     *
     * @param array<string, string> $upstream
     */
    #[DataProvider('provideStartPoints')]
    public function testCheckoutNewBranchTracksARemoteBranch(string $startPoint, array $upstream): void
    {
        $repository = self::createFoobarRepository(false);

        $repository->getWorkingCopy()->checkoutNewBranch('feature', $startPoint);

        $this->assertSame('refs/heads/feature', self::headBranch($repository)->getFullname());
        $this->assertSame($upstream, self::branchConfiguration($repository, 'feature'));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function provideStartPoints(): iterable
    {
        $upstream = ['branch.feature.merge' => 'refs/heads/new-feature', 'branch.feature.remote' => 'origin'];

        yield 'remote branch' => ['origin/new-feature', $upstream];
        yield 'remote branch by its full name' => ['refs/remotes/origin/new-feature', $upstream];
        yield 'local branch' => ['master', []];
        yield 'tag' => ['0.1', []];
        yield 'hash' => [self::INITIAL_COMMIT, []];
    }

    public function testCheckoutNewBranchFromARemoteBranchObjectTracksIt(): void
    {
        $repository = self::createFoobarRepository(false);

        $repository->getWorkingCopy()->checkoutNewBranch('feature', $repository->getReferences()->getRemoteBranch('origin/new-feature'));

        $this->assertSame(['branch.feature.merge' => 'refs/heads/new-feature', 'branch.feature.remote' => 'origin'], self::branchConfiguration($repository, 'feature'));
    }

    /**
     * git_branch_set_upstream() prefers a local branch of the name of the
     * remote one, unlike git.
     */
    public function testCheckoutNewBranchTracksARemoteBranchByItsFullName(): void
    {
        $repository = self::createFoobarRepository(false);
        $repository->getReferences()->createBranch('origin/new-feature', 'master');

        $repository->getWorkingCopy()->checkoutNewBranch('feature', 'refs/remotes/origin/new-feature');

        $this->assertSame('refs/heads/feature', self::headBranch($repository)->getFullname());
        $this->assertSame(['branch.feature.merge' => 'refs/heads/new-feature', 'branch.feature.remote' => 'origin'], self::branchConfiguration($repository, 'feature'));
    }

    /**
     * Like git, which refuses a start point several references have the
     * name of. "git checkout -b" checks it out before, and leaves its files.
     */
    public function testCheckoutNewBranchRefusesAnAmbiguousStartPoint(): void
    {
        $repository = self::createFoobarRepository(false);
        $head = $repository->getHeadCommit()->getHash();
        $repository->getReferences()->createBranch('origin/new-feature', self::INITIAL_COMMIT);

        try {
            $repository->getWorkingCopy()->checkoutNewBranch('feature', 'origin/new-feature');
            $this->fail('The start point is ambiguous.');
        } catch (DriverException $e) {
            if (self::getDriver() instanceof CliDriver) {
                $this->assertMatchesRegularExpression('/fatal: [Aa]mbiguous object name: \'origin\/new-feature\'/', $e->getMessage());
            } else {
                $this->assertSame('The name "origin/new-feature" is ambiguous: "refs/heads/origin/new-feature", "refs/remotes/origin/new-feature".', $e->getMessage());
            }
        }

        $repository = new Repository($repository->getPath(), driver: self::getDriver());
        $this->assertSame('refs/heads/master', self::headBranch($repository)->getFullname());
        $this->assertSame($head, $repository->getHeadCommit()->getHash());
        $this->assertFalse($repository->getReferences()->hasBranch('feature'));
        $this->assertSame([], self::branchConfiguration($repository, 'feature'));
        $this->assertSame([], $repository->getWorkingCopy()->getDiffStaged()->getFiles());
        $this->assertSame([], $repository->getWorkingCopy()->getDiffPending()->getFiles());
    }

    public function testCheckoutNewBranchWithAnInvalidNameChangesNothing(): void
    {
        $repository = self::createFoobarRepository(false);
        $head = $repository->getHeadCommit()->getHash();

        try {
            $repository->getWorkingCopy()->checkoutNewBranch('bad..name', self::INITIAL_COMMIT);
            $this->fail('The checkout should have been refused.');
        } catch (DriverException) {
        }

        $this->assertSame('refs/heads/master', self::headBranch($repository)->getFullname());
        $this->assertSame($head, $repository->getHeadCommit()->getHash());
        $this->assertSame([], $repository->getWorkingCopy()->getDiffStaged()->getFiles());
    }

    public function testMergeRefusesUnrelatedHistories(): void
    {
        $repository = self::createFoobarRepository(false);
        $tree = $repository->getHeadCommit()->getTreeHash();
        $orphan = self::writeObject($repository, 'commit', "tree {$tree}\nauthor A <a@example.com> 1000000000 +0000\ncommitter A <a@example.com> 1000000000 +0000\n\nOrphan\n");

        $this->expectException(DriverException::class);

        $repository->getWorkingCopy()->merge($orphan);
    }

    public function testMergeRefusesStagedChanges(): void
    {
        $repository = self::createFoobarRepository(false);
        self::git($repository, 'config', ['user.name', 'Unit Test']);
        self::git($repository, 'config', ['user.email', 'unit_test@unit-test.com']);
        file_put_contents($repository->getWorkingDir().'/staged', 'staged');
        self::git($repository, 'add', ['staged']);
        $head = $repository->getHeadCommit()->getHash();

        try {
            $repository->getWorkingCopy()->merge('origin/diff-features');
            $this->fail('The merge should have been refused.');
        } catch (DriverException) {
        }

        $this->assertSame($head, $repository->getHeadCommit()->getHash());
    }

    /**
     * @return array<string, string> The configuration of the branch $branch, sorted by key
     */
    private static function branchConfiguration(Repository $repository, string $branch): array
    {
        $configuration = [];
        foreach (explode("\n", self::git($repository, 'config', ['--list'])) as $line) {
            if (str_starts_with($line, 'branch.'.$branch.'.')) {
                [$key, $value] = explode('=', $line, 2);
                $configuration[$key] = $value;
            }
        }
        ksort($configuration);

        return $configuration;
    }
}
