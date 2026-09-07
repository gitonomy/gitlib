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

use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

class ReferenceBagTest extends AbstractTestCase
{
    #[DataProvider('provideFoobar')]
    public function testUnknownReference(Repository $repository): void
    {
        $hash = $repository->getLog()->getSingleCommit()->getHash();

        $repository->run('update-ref', ['refs/pipelines/1', $hash]);
        $repository->run('update-ref', ['refs/merge-request/1/head', $hash]);
        $repository->run('update-ref', ['refs/pull/1/head', $hash]);
        $repository->run('update-ref', ['refs/notes/gtm-data', $hash]);

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

    #[DataProvider('provideEmpty')]
    public function testEmptyRepo(Repository $repository): void
    {
        $refs = $repository->getReferences()->getAll();
        $this->assertSame([], $refs);
    }
}
