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

use Gitonomy\Git\Admin;
use Gitonomy\Git\Reference\Branch;

class WorkingCopyTest extends AbtractTest
{
    protected $tempDir;
    protected $repo;

    public function setUp()
    {
        $this->tempDir = $this->createTempDir();

        $path = $this->tempDir.'/foo';
        $this->repo = Admin::init($path, false);
        $this->repo->run('remote', array('add', 'origin', $this->getTestDirectory()));
        $this->repo->run('fetch', array('origin'));
    }

    public function tearDown()
    {
        $this->deleteDir($this->tempDir);
    }

    /**
     * @expectedException LogicException
     */
    public function testNoWorkingCopyInBare()
    {
        $path = $this->tempDir.'/bare';
        $repo = Admin::init($path);

        $repo->getWorkingCopy();
    }

    public function testCheckout()
    {
        $wc = $this->repo->getWorkingCopy();
        $wc->checkout('origin/master', 'master');

        $head = $this->repo->getHead();
        $this->assertTrue($head instanceof Branch, "HEAD is a branch");
        $this->assertEquals("master", $head->getName(), "HEAD is branch master");
    }

    /**
     * @expectedException RuntimeException
     */
    public function testCheckoutUnexisting()
    {
        $wc = $this->repo->getWorkingCopy();
        $wc->checkout('foobar');
    }

    public function testAttachedHead()
    {
        $wc = $this->repo->getWorkingCopy();
        $wc->checkout('master');

        $head = $this->repo->getHead();
        $this->assertTrue($this->repo->isHeadAttached(), "HEAD is attached");
        $this->assertFalse($this->repo->isHeadDetached(), "HEAD is not detached");
    }

    public function testDetachedHead()
    {
        $wc = $this->repo->getWorkingCopy();
        $wc->checkout('0.1');

        $head = $this->repo->getHead();
        $this->assertFalse($this->repo->isHeadAttached(), "HEAD is not attached");
        $this->assertTrue($this->repo->isHeadDetached(), "HEAD is detached");
    }
}
