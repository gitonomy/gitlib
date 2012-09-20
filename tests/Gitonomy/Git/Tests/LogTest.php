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

use Gitonomy\Git\Log;

class LogTest extends TestBase
{
    public function testGetCommits()
    {
        $repository = $this->getLibRepository();
        $log = $repository->getLog(self::TRAVIS_COMMIT, null, 3);

        $commits = $log->getCommits();

        $this->assertEquals(3, count($commits), "3 commits in log");
        $this->assertEquals(self::TRAVIS_COMMIT, $commits[0]->getHash(), "First is requested one");
        $this->assertEquals(self::TRAVIS_PARENT_COMMIT, $commits[1]->getHash(), "Second is travis parent\'s");
    }

    public function testCountCommits()
    {
        $repository = $this->getLibRepository();
        $log = $repository->getLog(self::TRAVIS_COMMIT, 2, 3);

        $this->assertEquals(5, $log->countCommits(), "5 commits found in history");
    }

    public function testCountAllCommits()
    {
        $repository = $this->getLibRepository();
        $log = $log = $repository->getLog(null);

        $this->assertGreaterThan(30, $log->countCommits(), "At least 10 commits");
    }

    public function testIterable()
    {
        $repository = $this->getLibRepository();
        $log = $repository->getLog(self::TRAVIS_COMMIT);

        $expectedHashes = array(self::TRAVIS_COMMIT, self::TRAVIS_PARENT_COMMIT);
        foreach ($log as $entry) {
            $hash = array_shift($expectedHashes);
            $this->assertEquals($hash, $entry->getHash());
            if (count($expectedHashes) == 0) {
                break;
            }
        }
    }

    public function testCountable()
    {
        $repository = $this->getLibRepository();
        $log = $repository->getLog(self::TRAVIS_COMMIT, 2, 3);

        $this->assertEquals(5, count($log), "Log is countable");
    }
}
