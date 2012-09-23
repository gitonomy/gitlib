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
use Gitonomy\Git\Diff;

class CommitTest extends TestBase
{
    public function testGetDiff()
    {
        $commit = $this->getInitialCommit();

        $diff = $commit->getDiff();

        $this->assertTrue($diff instanceof Diff, "getDiff() returns a Diff object");
        $this->assertEquals($commit->getHash(), $diff->getRevision(), "getDiff() revision is correct");
    }

    public function testGetHash()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals(self::INITIAL_COMMIT, $commit->getHash());
    }

    public function testGetShortHash()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('1040d33', $commit->getShortHash(), "Short hash");
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

    public function testGetAuthorName()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('alexandresalome', $commit->getAuthorName(), "Author name");
    }

    public function testGetAuthorEmail()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('alexandre.salome@gmail.com', $commit->getAuthorEmail(), "Author email");
    }

    public function testGetAuthorDate()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('2012-09-06 22:30:04', $commit->getAuthorDate()->format('Y-m-d H:i:s'), 'Author date');
    }

    public function testGetCommitterName()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('alexandresalome', $commit->getCommitterName(), "Committer name");
    }

    public function testGetCommitterEmail()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('alexandre.salome@gmail.com', $commit->getCommitterEmail(), "Committer email");
    }

    public function testGetCommitterDate()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('2012-09-06 22:30:04', $commit->getCommitterDate()->format('Y-m-d H:i:s'), 'Committer date');
    }

    public function testGetMessage()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('Initial commit'."\n", $commit->getMessage());
    }

    public function testGetShortMessage()
    {
        $commit = $this->getInitialCommit();

        $this->assertEquals('Initial commit', $commit->getShortMessage());
    }

    public function testGetLastModification()
    {
        $commit = $this->getTravisCommit();

        $lastModification = $commit->getLastModification('LICENSE');

        $this->assertTrue($lastModification instanceof Commit, "Last modification is a Commit object");
        $this->assertEquals(self::INITIAL_COMMIT, $lastModification->getHash(), "Last modification on LICENCE was initial commit");
    }

    private function getInitialCommit()
    {
        return $this->getLibRepository()->getCommit(self::INITIAL_COMMIT);
    }

    private function getTravisCommit()
    {
        return $this->getLibRepository()->getCommit(self::TRAVIS_COMMIT);
    }
}
