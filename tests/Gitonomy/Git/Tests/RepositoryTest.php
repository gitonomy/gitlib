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

use Gitonomy\Git\Repository;
use Gitonomy\Git\Blob;
use Gitonomy\Git\Event\Events;
use Gitonomy\Git\Event\PreCommandEvent;
use Gitonomy\Git\Event\PostCommandEvent;

class RepositoryTest extends AbtractTest
{
    public function testGetBlob_WithExisting_Works()
    {
        $repo = $this->getLibRepository();

        $blob = $repo->getBlob(self::README_BLOB);
        $this->assertTrue($blob instanceof Blob, "getBlob() returns a Blob object");
        $this->assertEquals(self::README_BLOB, $blob->getHash(), "getHash() returns passed hash");
    }

    public function testGetSize()
    {
        $repo = $this->getLibRepository();

        $size = $repo->getSize();
        $this->assertGreaterThan(100, $size, "Repository is greater than 100KB");
    }

    public function testIsBare()
    {
        // Lib repository
        $repo = $this->getLibRepository();
        $this->assertTrue($repo->isBare(), "Lib repository is bare");

        // Test repository
        $repo = $this->getTestRepository();
        $this->assertFalse($repo->isBare(), "Working copy is not bare");
    }

    public function testEventDispatcher_Basis()
    {
        $repo = new Repository($this->getTestDirectory());

        $test = $this;

        $before = false;
        $repo->addListener(Events::PRE_COMMAND, function ($event) use ($test, &$before) {
            $test->assertTrue($event instanceof PreCommandEvent);
            $test->assertEquals('remote', $event->getCommand(), "command is remote");
            $test->assertEquals(array('-v'), $event->getArgs(), "args is -v");
            $before = true;
        });

        $after = false;
        $repo->addListener(Events::POST_COMMAND, function ($event) use ($test, &$after) {
            $test->assertTrue($event instanceof PostCommandEvent);
            $test->assertEquals('remote', $event->getCommand(), "command is remote");
            $test->assertEquals(array('-v'), $event->getArgs(), "args is -v");
            $after = true;
        });

        $repo->run('remote', array('-v'));

        $this->assertTrue($before, "pre-command called");
        $this->assertTrue($after,  "post-command called");
    }

    public function testEventDispatcher_Error()
    {
        $repo = $this->getLibRepository();

        $test = $this;

        $after = false;
        $repo->addListener(Events::POST_COMMAND, function ($event) use ($test, &$after) {
            $after = true;
        });

        try {
            $repo->run('foobar');
            $this->fail("expected exception on invalid command");
        } catch (\RuntimeException $e) {
        }

        $this->assertTrue($after,  "post-command called");
    }

    public function testLoggerOk()
    {
        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger
            ->expects($this->once())
            ->method('info')
        ;
        $logger
            ->expects($this->exactly(2))
            ->method('debug')
        ;

        $repo = $this->createRepositoryInstance($this->getLibDirectory());
        $repo->setLogger($logger);

        $this->assertTrue($repo->isBare(), "A working command log everything");
    }

    /**
     * @expectedException RuntimeException
     */
    public function testLoggerNOk()
    {
        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $logger
            ->expects($this->once())
            ->method('info')
        ;
        $logger
            ->expects($this->exactly(3))
            ->method('debug')
        ;

        $repo = $this->createRepositoryInstance($this->getLibDirectory());
        $repo->setLogger($logger);

        $this->assertTrue($repo->run('not-work'), "A failing command log everything");
    }
}
