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

use Gitonomy\Git\Exception\ReferenceNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;

class RevisionTest extends AbstractTestCase
{
    #[DataProvider('provideBare')]
    public function testGetCommit(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $revision = $repository->getRevision(self::LONGFILE_COMMIT.'^');

        $commit = $revision->getCommit();

        $this->assertSame(self::BEFORE_LONGFILE_COMMIT, $commit->getHash(), 'Resolution is correct');
    }

    #[DataProvider('provideBare')]
    public function testGetFailingReference(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $this->expectException(ReferenceNotFoundException::class);
        $this->expectExceptionMessage('The revision "non-existent-commit" does not exist, or names no commit.');

        $repository->getRevision('non-existent-commit')->getCommit();
    }

    #[DataProvider('provideBare')]
    public function testGetLog(bool $bare): void
    {
        $repository = self::createFoobarRepository($bare);
        $revision = $repository->getRevision(self::LONGFILE_COMMIT);

        $log = $revision->getLog(null, 2, 3);
        $this->assertSame(2, $log->getOffset(), 'Log offset is passed');
        $this->assertSame(3, $log->getLimit(), 'Log limit is passed');
        $this->assertSame([$revision], $log->getRevisions()?->getAll(), 'Revision is passed');
    }
}
