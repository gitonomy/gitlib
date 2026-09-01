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

use Gitonomy\Git\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

class BlameTest extends AbstractTestCase
{
    #[DataProvider('provideFoobar')]
    public function testBlame(Repository $repository): void
    {
        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md');

        $this->assertCount(7, $blame);

        $this->assertEquals('alice', $blame->getLine(1)->getCommit()->getAuthorName());
        $this->assertEquals(self::INITIAL_COMMIT, $blame->getLine(1)->getCommit()->getHash());

        $this->assertEquals('alice', $blame->getLine(5)->getCommit()->getAuthorName());
        $this->assertNotEquals(self::INITIAL_COMMIT, $blame->getLine(5)->getCommit()->getHash());
    }

    #[DataProvider('provideFoobar')]
    public function testGroupedBlame(Repository $repository): void
    {
        $blame = $repository->getBlame(self::LONGFILE_COMMIT, 'README.md')->getGroupedLines();

        $this->assertCount(3, $blame);

        $this->assertEquals(self::INITIAL_COMMIT, $blame[0][0]->getHash());
    }
}
