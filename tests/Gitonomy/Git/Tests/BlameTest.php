<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the GPL license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Tests;

class BlameTest extends AbstractTest
{
    public function testBlame()
    {
        $blame = $this->getLibRepository()->getBlame(self::DOC_COMMIT, 'README.md');

        $this->assertCount(15, $blame);

        $this->assertEquals('alexandresalome', $blame->getLine(1)->getCommit()->getAuthorName());
        $this->assertEquals(self::INITIAL_COMMIT, $blame->getLine(1)->getCommit()->getHash());

        $this->assertEquals('alexandresalome', $blame->getLine(5)->getCommit()->getAuthorName());
        $this->assertNotEquals(self::INITIAL_COMMIT, $blame->getLine(5)->getCommit()->getHash());
    }

    public function testGroupedBlame()
    {
        $blame = $this->getLibRepository()->getBlame(self::DOC_COMMIT, 'README.md')->getGroupedLines();

        $this->assertCount(4, $blame);

        $this->assertEquals(self::INITIAL_COMMIT, $blame[0][0]->getHash());
    }
}
