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

use Gitonomy\Git\Reference\Branch;
use PHPUnit\Framework\Attributes\DataProvider;

class ReferenceBagTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testGetFirstBranch(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $branch = $repository->getReferences()->getFirstBranch();

        $this->assertInstanceOf(Branch::class, $branch);
    }

    public function testGetFirstBranchWithNoBranches(): void
    {
        $repository = self::createFoobarRepository(false);
        $wc = $repository->getWorkingCopy();
        $wc->checkout('0.1');

        foreach ($repository->getReferences()->getBranches() as $branch) {
            $branch->delete();
        }

        $refs = $repository->getReferences(true);
        $this->assertFalse($refs->hasBranches());
        $this->assertFalse($refs->getFirstBranch(), 'getFirstBranch() must not return a non-branch reference');
    }

    #[DataProvider('provideBare')]
    public function testUnknownReference(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $hash = $repository->getLog()->getSingleCommit()->getHash();

        self::git($repository, 'update-ref', ['refs/pipelines/1', $hash]);
        self::git($repository, 'update-ref', ['refs/merge-request/1/head', $hash]);
        self::git($repository, 'update-ref', ['refs/pull/1/head', $hash]);
        self::git($repository, 'update-ref', ['refs/notes/gtm-data', $hash]);

        $refs = $repository->getReferences()->getAll();
        $this->assertIsArray($refs);

        // Check that at least it has the master ref
        $this->assertArrayHasKey('refs/heads/master', $refs);

        // Check that our custom refs have been ignored
        $this->assertArrayNotHasKey('refs/pipelines/1', $refs);
        $this->assertArrayNotHasKey('refs/merge-request/1/head', $refs);
        $this->assertArrayNotHasKey('refs/pull/1/head', $refs);
        $this->assertArrayNotHasKey('refs/notes/gtm-data', $refs);
    }

    #[DataProvider('provideBare')]
    public function testEmptyRepo(bool $bare): void
    {
        $repository = self::createEmptyRepository($bare);
        $refs = $repository->getReferences()->getAll();
        $this->assertSame([], $refs);
    }
}
