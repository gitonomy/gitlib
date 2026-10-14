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

use Gitonomy\Git\Exception\DriverException;
use Gitonomy\Git\Exception\InvalidArgumentException;
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Tag;
use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

class ReferenceTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testEmptyRepository(bool $bare): void
    {
        $repository = self::createEmptyRepository($bare);
        $this->assertCount(0, $repository->getReferences());
        $this->assertSame([], $repository->getReferences()->getAll());
    }

    #[DataProvider('provideBare')]
    public function testGetBranch(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $branch = $repository->getReferences()->getBranch('master');
        $this->assertSame($branch->getCommitHash(), $branch->getCommit()->getHash(), 'Hash is correctly resolved');
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
        $this->assertFalse($tag->isAnnotated(), 'Tag is not annotated');

        $this->assertSame(self::LONGFILE_COMMIT, $tag->getCommitHash(), 'Commit hash is correct');
        $this->assertSame(self::LONGFILE_COMMIT, $tag->getCommit()->getHash(), 'Commit hash is correct');
    }

    #[DataProvider('provideBare')]
    public function testAnnotatedTag(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $tag = $repository->getReferences()->getTag('annotated');
        $this->assertTrue($tag->isAnnotated(), 'Tag is annotated');
        $this->assertFalse($tag->isSigned(), 'Tag is not signed');

        $this->assertSame('Graham Campbell', $tag->getTaggerName(), 'Tagger name is correct');
        $this->assertSame('graham@alt-three.com', $tag->getTaggerEmail(), 'Tagger email is correct');
        $this->assertSame(1471428000, $tag->getTaggerDate()?->getTimestamp(), 'Tag date is correct');

        // Without an empty line after its first one, the message is one paragraph: its subject, like "git log --format=%s".
        $this->assertSame('heading body body', $tag->getSubjectMessage(), 'Message heading is correct');
        $this->assertSame('', $tag->getBodyMessage(), 'Message body is correct');

        // The commit the tag object points to, not the tag object.
        $this->assertSame('fbde681b329a39e08b63dc54b341a3274c0380c0', $tag->getCommitHash());
        $this->assertSame('fbde681b329a39e08b63dc54b341a3274c0380c0', $tag->getCommit()->getHash());
        $this->assertSame([$tag], $repository->getReferences()->resolveTags($tag->getCommit()));
        $this->assertContains($tag, $tag->getCommit()->resolveReferences());
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

        $this->assertSame('Subject line', $tag->getSubjectMessage(), 'Message subject is correct');
        $this->assertSame("Body line 1\nBody line 2\n", $tag->getBodyMessage(), 'Message body has no leading blank line');
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
        $this->assertSame('refs/tags/0.1', $resolved[0]->getFullname());
    }

    #[DataProvider('provideBare')]
    public function testResolveTags(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $commit = $repository->getReferences()->getTag('0.1')->getCommit();
        $resolved = $repository->getReferences()->resolveTags($commit->getHash());

        $this->assertCount(1, $resolved, '1 revision resolved');
        $this->assertSame('refs/tags/0.1', $resolved[0]->getFullname());
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

        $this->assertNotEmpty($resolved, 'A branch is resolved');
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
        $this->assertSame(self::INITIAL_COMMIT, $tag->getCommit()->getHash());
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
        $this->assertSame(self::INITIAL_COMMIT, $branch->getCommit()->getHash());
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

        $this->assertTrue($branch->isMergedInto('master'));
        $this->assertTrue($branch->isMergedInto($master));

        $wc = $repository->getWorkingCopy();
        $wc->checkout('foobar-new');

        $file = $repository->getWorkingDir().'/foobar-test.txt';
        file_put_contents($file, 'test');
        self::git($repository, 'add', [$file]);
        self::git($repository, 'commit', ['-m', 'foobar-test.txt updated']);

        // A branch is read once: read it again to see the new commit.
        $this->assertFalse($references->getBranch('foobar-new')->isMergedInto('master'));
        $this->assertTrue($master->isMergedInto('foobar-new'));
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyIsMergedTo(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\Reference\Branch::isMergedTo()" method is deprecated, use "Gitonomy\Git\Reference\Branch::isMergedInto()" instead.');

        $repository = self::createFoobarRepository(false);
        $references = $repository->getReferences();
        $branch = $references->createBranch('foobar-new', 'master');

        $this->assertTrue($branch->isMergedTo());
        $this->assertFalse($branch->isMergedTo('master', true), 'A local branch is not remote');
    }

    #[DataProvider('provideBare')]
    public function testCreateRefusesAnExistingReference(bool $bare): void
    {
        $references = self::createFoobarRepository($bare)->getReferences();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The reference "refs/heads/master" exists already.');

        $references->createBranch('master', self::INITIAL_COMMIT);
    }

    #[DataProvider('provideBare')]
    public function testCreatedReferencesAreListed(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $references = $repository->getReferences();
        $names = static fn (array $tags): array => array_map(static fn (Tag $tag): string => $tag->getFullname(), $tags);
        $before = $names($references->getTags());

        $tag = $references->createTag('0.0', self::INITIAL_COMMIT);

        $this->assertSame(['refs/tags/0.0', ...$before], $names($references->getTags()));
        $this->assertContains($tag, $repository->getReferences()->resolveTags(self::INITIAL_COMMIT));

        $tag->delete();
        $this->assertSame($before, $names($references->getTags()));
    }

    public function testResolveTagsOfARevision(): void
    {
        $references = self::createFoobarRepository()->getReferences();

        $this->assertSame([$references->getTag('annotated')], $references->resolveTags('annotated'));
    }

    public function testBranchMoveTo(): void
    {
        $repository = self::createFoobarRepository();
        $branch = $repository->getReferences()->createBranch('moving', self::INITIAL_COMMIT);

        $moved = $branch->moveTo($repository->getCommit(self::LONGFILE_COMMIT));

        $this->assertSame(self::LONGFILE_COMMIT, $moved->getCommitHash());
        $this->assertSame($moved, $repository->getReferences()->getBranch('moving'));
        $this->assertSame(self::INITIAL_COMMIT, $branch->getCommitHash(), 'A branch is read once');
    }

    #[Group('legacy')]
    #[IgnoreDeprecations]
    public function testLegacyUpdate(): void
    {
        $this->expectUserDeprecationMessage('Since gitonomy/gitlib 2.0: The "Gitonomy\Git\ReferenceBag::update()" method is deprecated, use "Gitonomy\Git\Reference\Branch::moveTo()" instead.');

        $repository = self::createFoobarRepository();
        $references = $repository->getReferences();

        $references->update(new Branch($repository, 'refs/heads/master', self::INITIAL_COMMIT));

        $this->assertSame(self::INITIAL_COMMIT, $references->getBranch('master')->getCommitHash());
    }

    public function testBranchesEndingWithHead(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'branch', ['AHEAD', 'master']);

        $this->assertTrue($repository->getReferences()->hasBranch('AHEAD'));
    }

    public function testDeletingADeletedReferenceDoesNothing(): void
    {
        $repository = self::createFoobarRepository();
        $branch = $repository->getReferences()->createBranch('deleted', 'master');
        self::git($repository, 'update-ref', ['-d', 'refs/heads/deleted']);

        // Like git update-ref -d.
        $branch->delete();

        $this->assertFalse($repository->getReferences()->hasBranch('deleted'));
    }

    /**
     * The symbolic reference is deleted, not the branch it points to.
     */
    public function testDeletingASymbolicReference(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'update-ref', ['refs/remotes/origin/main', self::LONGFILE_COMMIT]);
        self::git($repository, 'symbolic-ref', ['refs/remotes/origin/HEAD', 'refs/remotes/origin/main']);

        $repository->getReferences()->delete('refs/remotes/origin/HEAD');

        $this->assertSame(\sprintf("%s refs/remotes/origin/main\n", self::LONGFILE_COMMIT), self::git($repository, 'for-each-ref', ['--format=%(objectname) %(refname)', 'refs/remotes/origin/']));
    }

    /**
     * The symbolic reference moves, not the branch it points to.
     * ReferenceBag leaves it out: through the driver, which Branch::moveTo()
     * calls.
     */
    public function testUpdatingASymbolicReference(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'update-ref', ['refs/remotes/origin/main', self::LONGFILE_COMMIT]);
        self::git($repository, 'symbolic-ref', ['refs/remotes/origin/HEAD', 'refs/remotes/origin/main']);

        $repository->getRepositoryDriver()->updateReference('refs/remotes/origin/HEAD', self::INITIAL_COMMIT);

        $this->assertSame(\sprintf("%s refs/remotes/origin/HEAD\n%s refs/remotes/origin/main\n", self::INITIAL_COMMIT, self::LONGFILE_COMMIT), self::git($repository, 'for-each-ref', ['--format=%(objectname) %(refname)', 'refs/remotes/origin/']));
    }

    /**
     * "git update-ref -d HEAD" deletes HEAD: git no longer sees a repository.
     */
    #[DataProvider('provideBare')]
    public function testDeletingHeadIsRefused(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);

        try {
            $repository->getReferences()->delete('HEAD');
            $this->fail('HEAD is no reference under "refs/".');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('"HEAD" is not the full name of a reference under "refs/", like "refs/heads/master".', $e->getMessage());
        }

        $repository = new Repository($repository->getPath(), driver: self::getDriver());
        $this->assertSame('refs/heads/master', self::headBranch($repository)->getFullname());
    }

    /**
     * "git update-ref" writes any name: HEAD, or a short name next to it.
     */
    #[DataProvider('provideWritesOfANameOutsideRefs')]
    public function testTheDriverWritesNoReferenceOutsideRefs(\Closure $write, string $fullname): void
    {
        $repository = self::createFoobarRepository();

        try {
            $write($repository, $fullname);
            $this->fail('Only a reference under "refs/" is written.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(\sprintf('"%s" is not the full name of a reference under "refs/", like "refs/heads/master".', $fullname), $e->getMessage());
        }

        $this->assertSame('ref: refs/heads/master', trim((string) file_get_contents($repository->getGitDir().'/HEAD')));
        $this->assertFileDoesNotExist($repository->getGitDir().'/master');
    }

    /**
     * @return iterable<string, array{\Closure(Repository, string): void, string}>
     */
    public static function provideWritesOfANameOutsideRefs(): iterable
    {
        $writes = [
            'create' => static function (Repository $repository, string $fullname): void { $repository->getRepositoryDriver()->createReference($fullname, self::INITIAL_COMMIT); },
            'update' => static function (Repository $repository, string $fullname): void { $repository->getRepositoryDriver()->updateReference($fullname, self::INITIAL_COMMIT); },
            'delete' => static function (Repository $repository, string $fullname): void { $repository->getRepositoryDriver()->deleteReference($fullname); },
        ];

        foreach ($writes as $writeName => $write) {
            foreach (['HEAD', 'master'] as $fullname) {
                yield $writeName.' '.$fullname => [$write, $fullname];
            }
        }
    }

    /**
     * Nothing is created through a symbolic reference to a missing branch:
     * git 2.x and libgit2 refuse it, git 1.x replaces it.
     */
    public function testCreatingABranchNamedLikeADanglingSymbolicReference(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'symbolic-ref', ['refs/heads/dangling', 'refs/heads/missing']);

        try {
            $repository->getReferences()->createBranch('dangling', self::LONGFILE_COMMIT);
        } catch (DriverException) {
        }

        $this->assertFalse($repository->getReferences()->hasBranch('missing'));
    }

    public function testTheHeadOfARemoteNamedWithASlashIsNoBranch(): void
    {
        $repository = self::createFoobarRepository();
        self::git($repository, 'update-ref', ['refs/remotes/foo/bar/main', self::LONGFILE_COMMIT]);
        self::git($repository, 'symbolic-ref', ['refs/remotes/foo/bar/HEAD', 'refs/remotes/foo/bar/main']);

        $names = array_map(static fn (Branch $branch): string => $branch->getFullname(), $repository->getReferences()->getRemoteBranches());

        $this->assertContains('refs/remotes/foo/bar/main', $names);
        $this->assertNotContains('refs/remotes/foo/bar/HEAD', $names);
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
        $this->assertNull($tag->getTaggerName());
        $this->assertNull($tag->getTaggerEmail());
        $this->assertNull($tag->getTaggerDate());
        $this->assertSame("No tagger.\n", $tag->getMessage());
    }

    public function testSignedTag(): void
    {
        $repository = self::createFoobarRepository(false);
        $hash = self::writeObject($repository, 'tag', \sprintf("object %s\ntype commit\ntag signed\ntagger Alice <alice@example.com> 1471428000 +0000\n\nSigned.\n-----BEGIN PGP SIGNATURE-----\n\nsignature\n-----END PGP SIGNATURE-----\n", self::LONGFILE_COMMIT));
        self::git($repository, 'update-ref', ['refs/tags/signed', $hash]);

        $tag = $repository->getReferences()->getTag('signed');

        $this->assertTrue($tag->isSigned());
        $this->assertSame("\n\nsignature\n", $tag->getGpgSignature());
        $this->assertSame("Signed.\n", $tag->getMessage());
    }

    public function testSshSignedTag(): void
    {
        $repository = self::createFoobarRepository(false);
        $hash = self::writeObject($repository, 'tag', \sprintf("object %s\ntype commit\ntag signed\ntagger Alice <alice@example.com> 1471428000 +0000\n\nSigned.\n-----BEGIN SSH SIGNATURE-----\nsignature\n-----END SSH SIGNATURE-----\n", self::LONGFILE_COMMIT));
        self::git($repository, 'update-ref', ['refs/tags/signed', $hash]);

        $tag = $repository->getReferences()->getTag('signed');

        $this->assertTrue($tag->isSigned());
        $this->assertSame("\nsignature\n", $tag->getGpgSignature());
        $this->assertSame("Signed.\n", $tag->getMessage());
    }

    #[DataProvider('provideBare')]
    public function testLightweightTag(bool $bare): void
    {
        $tag = self::createFoobarRepository($bare)->getReferences()->getTag('0.1');

        $this->assertFalse($tag->isAnnotated());
        $this->assertFalse($tag->isSigned());
        $this->assertNull($tag->getTaggerName());
        $this->assertNull($tag->getMessage());
        $this->assertNull($tag->getSubjectMessage());
        $this->assertNull($tag->getBodyMessage());
        $this->assertNull($tag->getGpgSignature());
    }
}
