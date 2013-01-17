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

use Gitonomy\Git\PushReference;

class PushReferenceTest extends AbtractTest
{
    const CREATE = 1;
    const DELETE = 2;
    const FORCE  = 4;
    const FAST_FORWARD  = 8;

    public function provideIsers()
    {
        // mask: force fastforward create delete
        return array(
            array('foo', PushReference::ZERO,        self::TRAVIS_COMMIT,        self::CREATE),
            array('foo', self::TRAVIS_COMMIT,        PushReference::ZERO,        self::DELETE),
            array('foo', self::TRAVIS_COMMIT,        self::TRAVIS_PARENT_COMMIT, self::FORCE),
            array('foo', self::TRAVIS_PARENT_COMMIT, self::TRAVIS_COMMIT,        self::FAST_FORWARD),
        );
    }

    /**
     * @dataProvider provideIsers
     */
    public function testIsers($reference, $before, $after, $mask)
    {
        $reference = new PushReference($this->getLibRepository(), $reference, $before, $after);
        $this->assertEquals($mask & self::CREATE,        $reference->isCreate(),       'Create value is correct.');
        $this->assertEquals($mask & self::DELETE,        $reference->isDelete(),       'Delete value is correct.');
        $this->assertEquals($mask & self::FORCE,         $reference->isForce(),        'Force value is correct.');
        $this->assertEquals($mask & self::FAST_FORWARD,  $reference->isFastForward(),  'FastForward value is correct.');
    }

    public function testLog()
    {
        $ref = $this->getReference('foo', self::INITIAL_COMMIT, self::TRAVIS_COMMIT);

        $log = $ref->getLog()->getCommits();
        $this->assertEquals(4, count($log), "4 commits in log");
        $this->assertEquals('Travis-CI integration', $log[0]->getShortMessage(), "First commit is correct");
    }

    public function testLogWithExclude()
    {
        $ref = $this->getReference('foo', PushReference::ZERO, self::TRAVIS_COMMIT);

        $log = $ref->getLog(array(self::INITIAL_COMMIT))->getCommits();
        $this->assertEquals(4, count($log), "4 commits in log");
        $this->assertEquals('Travis-CI integration', $log[0]->getShortMessage(), "First commit is correct");
    }

    protected function getReference($reference, $before, $after)
    {
        return new PushReference($this->getLibRepository(), $reference, $before, $after);
    }
}
