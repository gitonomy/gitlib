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
use Gitonomy\Git\Exception\ReferenceNotFoundException;
use Gitonomy\Git\Log;
use Gitonomy\Git\Revision;
use PHPUnit\Framework\Attributes\DataProvider;

class RevisionTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testGetCommit(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $revision = $repository->getRevision(self::LONGFILE_COMMIT.'^');

        $this->assertInstanceOf(Revision::class, $revision, 'Revision object type');

        $commit = $revision->getCommit();

        $this->assertInstanceOf(Commit::class, $commit, 'getCommit returns a Commit');

        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $commit->getHash(), 'Resolution is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetFailingReference(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('Can not find revision "non-existent-commit"');

        $repository->getRevision('non-existent-commit')->getCommit();
    }

    #[DataProvider('provideBare')]
    public function testGetLog(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $revision = $repository->getRevision(self::LONGFILE_COMMIT);

        $log = $revision->getLog(null, 2, 3);

        $this->assertInstanceOf(Log::class, $log, 'Log type object');
        $this->assertEquals(2, $log->getOffset(), 'Log offset is passed');
        $this->assertEquals(3, $log->getLimit(), 'Log limit is passed');
        $this->assertEquals([$revision], $log->getRevisions()->getAll(), 'Revision is passed');
    }
}
