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

use Gitonomy\Git\Reference\Branch;
use Gitonomy\Git\Reference\Tag;

class ReferenceTest extends TestBase
{
    private $references;

    public function setUp()
    {
        $this->references = $this->getLibRepository()->getReferences();
    }

    public function testGetBranch()
    {
        $branch = $this->references->getBranch('master');

        $this->assertTrue($branch instanceof Branch, "Branch object is correct type");
        $this->assertEquals($branch->getCommitHash(), $branch->getCommit()->getHash(), "Hash is correctly resolved");
    }

    /**
     * @expectedException Gitonomy\Git\Exception\ReferenceNotFoundException
     */
    public function testGetBranch_NotExisting_Error()
    {
        $branch = $this->references->getBranch('notexisting');
    }

    public function testGetTag()
    {
        $tag = $this->references->getTag('0.1');

        $this->assertTrue($tag instanceof Tag, "Tag object is correct type");

        $this->assertEquals(self::INITIAL_COMMIT, $tag->getCommitHash(), "Commit hash is correct");
        $this->assertEquals(self::INITIAL_COMMIT, $tag->getCommit()->getHash(), "Commit hash is correct");
    }

    /**
     * @expectedException Gitonomy\Git\Exception\ReferenceNotFoundException
     */
    public function testGetTag_NotExisting_Error()
    {
        $branch = $this->references->getTag('notexisting');
    }

    public function testResolve()
    {
        $resolved = $this->references->resolve(self::INITIAL_COMMIT);

        $this->assertEquals(1, count($resolved), "1 revision resolved");
        $this->assertTrue($resolved[0] instanceof Tag, "Resolved object is a tag");
    }

    public function testResolveTags()
    {
        $resolved = $this->references->resolveTags(self::INITIAL_COMMIT);

        $this->assertEquals(1, count($resolved), "1 revision resolved");
        $this->assertTrue($resolved[0] instanceof Tag, "Resolved object is a tag");
    }

    public function testResolveBranches()
    {
        $master = $this->references->getBranch('master');

        $resolved = $this->references->resolveBranches($master->getCommitHash());

        $this->assertEquals(1, count($resolved), "1 revision resolved");
        $this->assertTrue($resolved[0] instanceof Branch, "Resolved object is a branch");
    }

    public function testCountable()
    {
        $this->assertGreaterThanOrEqual(2, count($this->references), "At least two references in repository");
    }

    public function testIterable()
    {
        $i = 0;
        foreach ($this->references as $ref) {
            $i++;
        }
        $this->assertGreaterThanOrEqual(2, $i, "At least two references in repository");
    }
}
