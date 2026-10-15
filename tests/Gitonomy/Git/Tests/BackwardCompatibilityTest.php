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

use Gitonomy\Git\Admin;
use Gitonomy\Git\Blame;
use Gitonomy\Git\Diff\Diff;
use Gitonomy\Git\Diff\File;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Log;
use Gitonomy\Git\PushReference;
use Gitonomy\Git\Reference;
use Gitonomy\Git\ReferenceBag;
use Gitonomy\Git\Repository;

/**
 * 1.7 keeps working what worked with 1.6: the named arguments, the classes
 * extending the ones of gitlib, and the methods they override.
 */
class BackwardCompatibilityTest extends AbstractTest
{
    public function testEmptyOptions()
    {
        $source = self::createFoobarRepository(false);

        $repository = new Repository($source->getWorkingDir(), []);

        $this->assertSame($source->getWorkingDir(), $repository->getWorkingDir());
    }

    /**
     * @group legacy
     */
    public function testNamedArguments()
    {
        $source = self::createFoobarRepository(false);
        $repository = new Repository($source->getGitDir(), options: ['working_dir' => $source->getWorkingDir()]);

        $this->assertSame($source->getWorkingDir(), $repository->getWorkingDir());
        $this->assertCount(1, $repository->getDiff(revisions: self::BEFORE_LONGFILE_COMMIT.'..'.self::LONGFILE_COMMIT)->getFiles());
        $this->assertCount(3, $repository->getBlame(self::LONGFILE_COMMIT, 'README.md', lineRange: 5));
        $this->assertCount(3, new Blame($repository, $repository->getRevision(self::LONGFILE_COMMIT), 'README.md', lineRange: 5));

        $references = $repository->getReferences();
        $references->createBranch('foobar', commitHash: self::INITIAL_COMMIT);
        $references->createTag('foobar', commitHash: self::INITIAL_COMMIT);
        $this->assertCount(2, $references->resolve(hash: self::INITIAL_COMMIT));
        $this->assertCount(1, $references->resolveBranches(hash: self::INITIAL_COMMIT));
        $this->assertCount(1, $references->resolveTags(hash: self::INITIAL_COMMIT));

        $this->assertSame('refs/heads/foobar', (new PushReference($repository, reference: 'refs/heads/foobar', before: self::INITIAL_COMMIT, after: self::LONGFILE_COMMIT))->getFullname());

        $data = $repository->getCommit(self::LONGFILE_COMMIT)->getDiff()->toArray();
        $this->assertCount(1, Diff::fromArray(array: $data)->getFiles());
        $this->assertSame('long.php', File::fromArray(array: $data['files'][0])->getName());
        $this->assertCount(1, Diff::parse(rawDiff: $data['rawDiff'])->getFiles());
    }

    /**
     * @group legacy
     */
    public function testSubclassesOverridingMethodsWithTheSignatureOf16()
    {
        $repository = self::createFoobarRepository();
        $legacy = new LegacyRepository($repository->getGitDir());

        $this->assertCount(1, $legacy->getDiff(self::BEFORE_LONGFILE_COMMIT.'..'.self::LONGFILE_COMMIT)->getFiles());
        $this->assertCount(3, $legacy->getBlame(self::LONGFILE_COMMIT, 'README.md', 5));

        $data = $repository->getCommit(self::LONGFILE_COMMIT)->getDiff()->toArray();
        $this->assertCount(1, LegacyDiff::parse($data['rawDiff'])->getFiles());
        $this->assertCount(1, LegacyDiff::fromArray($data)->getFiles());
        $this->assertSame('long.php', LegacyFile::fromArray($data['files'][0])->getName());
    }

    /**
     * @group legacy
     */
    public function testOverriddenAdminCloneRepository()
    {
        LegacyAdmin::$args = [];

        $repository = LegacyAdmin::cloneTo(self::createTempDir(), self::createFoobarRepository()->getGitDir(), false);

        $this->assertSame([[]], LegacyAdmin::$args);
        $this->assertFalse($repository->isBare());
    }

    /**
     * @group legacy
     */
    public function testOverriddenReferenceBagUpdate()
    {
        $references = new LegacyReferenceBag(self::createFoobarRepository());

        $references->createBranch('foobar', self::INITIAL_COMMIT);
        $references->createTag('foobar', self::INITIAL_COMMIT);

        $this->assertSame(['refs/heads/foobar', 'refs/tags/foobar'], $references->updated);
    }

    /**
     * @group legacy
     */
    public function testOverriddenLogSetLimit()
    {
        $log = new LegacyLog(self::createFoobarRepository(), self::LONGFILE_COMMIT, null, null, 5);

        $this->assertSame(self::LONGFILE_COMMIT, $log->getSingleCommit()->getHash());
        $this->assertSame([5], $log->limits);
        $this->assertSame(5, $log->getLimit());
    }

    public function testOverriddenPushReferenceMethods()
    {
        $reference = new LegacyPushReference(self::createFoobarRepository(), 'refs/heads/foobar', self::BEFORE_LONGFILE_COMMIT, self::LONGFILE_COMMIT);

        // The overrides say it creates the reference: neither forced, nor fast-forward.
        $this->assertFalse($reference->isForce());
        $this->assertFalse($reference->isFastForward());
        $this->assertSame(self::LONGFILE_COMMIT, $reference->getRange());
        // getRevision() gives the commits of the log, from the first one.
        $this->assertCount(8, $reference->getLog()->getCommits());
    }

    public function testOverriddenFileChangeGetCount()
    {
        $file = new File('a.txt', 'a.txt', '100644', '100644', 'abc', 'def', false, false, [new LegacyFileChange(1, 1, 1, 1, [])]);

        $this->assertSame(42, $file->getAdditions());
        $this->assertSame(42, $file->getDeletions());
    }

    /**
     * @group legacy
     */
    public function testOverriddenFileSetRepository()
    {
        $repository = self::createFoobarRepository();
        $file = new LegacyFile('a.txt', 'a.txt', '100644', '100644', 'abc', 'def', false);

        (new Diff([$file], ''))->setRepository($repository);

        $this->assertSame($repository, $file->repositorySet);
    }
}

class LegacyRepository extends Repository
{
    public function getDiff($revisions)
    {
        return parent::getDiff($revisions);
    }

    public function getBlame($revision, $file, $lineRange = null)
    {
        return parent::getBlame($revision, $file, $lineRange);
    }
}

class LegacyDiff extends Diff
{
    public static function parse($rawDiff)
    {
        return parent::parse($rawDiff);
    }

    public static function fromArray(array $array)
    {
        return parent::fromArray($array);
    }
}

class LegacyFile extends File
{
    public $repositorySet;

    public static function fromArray(array $array)
    {
        return parent::fromArray($array);
    }

    public function setRepository(Repository $repository)
    {
        $this->repositorySet = $repository;
    }
}

class LegacyFileChange extends FileChange
{
    public function getCount($type)
    {
        return 42;
    }
}

class LegacyAdmin extends Admin
{
    public static $args = [];

    public static function cloneRepository($path, $url, array $args = [], array $options = [])
    {
        self::$args[] = $args;

        return parent::cloneRepository($path, $url, $args, $options);
    }
}

class LegacyReferenceBag extends ReferenceBag
{
    public $updated = [];

    public function update(Reference $reference)
    {
        $this->updated[] = $reference->getFullname();

        return parent::update($reference);
    }
}

class LegacyLog extends Log
{
    public $limits = [];

    public function setLimit($limit)
    {
        $this->limits[] = $limit;

        return parent::setLimit($limit);
    }
}

class LegacyPushReference extends PushReference
{
    public function isCreate()
    {
        return true;
    }

    public function isDelete()
    {
        return false;
    }

    public function getRevision()
    {
        return $this->getAfter();
    }
}
