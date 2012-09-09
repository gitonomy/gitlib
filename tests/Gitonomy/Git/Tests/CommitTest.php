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

use Gitonomy\Git\Commit;

class CommitTest extends TestBase
{
    public function testGetHash()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals(self::INITIAL_COMMIT, $commit->getHash());
    }

    public function testGetParentHashes_WithNoParent()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals(0, count($commit->getParentHashes()), "No parent on initial commit");
    }

    public function testGetParentHashes_WithOneParent()
    {
        $commit  = $this->getTravisCommit();
        $parents = $commit->getParentHashes();

        $this->assertEquals(1, count($parents), "One parent found");
        $this->assertEquals(self::TRAVIS_PARENT_COMMIT, $parents[0], "Parent hash is correct");
    }

    public function testGetParents_WithOneParent()
    {
        $commit  = $this->getTravisCommit();
        $parents = $commit->getParents();

        $this->assertEquals(1, count($parents), "One parent found");
        $this->assertTrue($parents[0] instanceof Commit, "First parent is a Commit object");
        $this->assertEquals(self::TRAVIS_PARENT_COMMIT, $parents[0]->getHash(), "First parents's hash is correct");
    }

    public function testGetTree()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals(self::INITIAL_TREE, $commit->getTreeHash(), "Tree hash is correct");
    }

    private function getInitialCommit()
    {
        return self::getLibRepository()->getCommit(self::INITIAL_COMMIT);
    }

    private function getTravisCommit()
    {
        return self::getLibRepository()->getCommit(self::TRAVIS_COMMIT);
    }
}
