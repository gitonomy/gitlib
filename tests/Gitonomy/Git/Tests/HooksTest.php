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

class HooksTest extends TestBase
{
    private $tmpDir;
    private $hooksDir;
    private $repository;
    private $hooks;

    public function setUp()
    {
        $this->tmpDir     = $this->createTempDir();
        $this->repository = Admin::init($this->tmpDir);
        $this->hooksDir   = $this->tmpDir.'/hooks';
        $this->hooks      = $this->repository->getHooks();
    }

    public function tearDown()
    {
        $this->deleteDir($this->tmpDir);
    }

    public function testHas()
    {
        $this->assertFalse($this->hooks->has('foo'), "No foo hook present in repository");
        touch($this->hooksDir.'/foo');
        $this->assertTrue($this->hooks->has('foo'), "foo hook present in repository");
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testGet_InvalidName_ThrowsException()
    {
        $this->hooks->get('foo');
    }

    public function testGet()
    {
        file_put_contents($this->hooksDir.'/foo', 'foobar');

        $this->assertEquals('foobar', $this->hooks->get('foo'));
    }

    public function testSymlink()
    {
        $file = $this->hooksDir.'/target-symlink';
        file_put_contents($file, 'foobar');

        $this->hooks->setSymlink('foo', $file);

        $this->assertTrue(is_link($this->hooksDir.'/foo'), "foo hook is a symlink");
        $this->assertEquals($file, readlink($this->hooksDir.'/foo'), "target of symlink is correct");
    }

    /**
     * @expectedException LogicException
     */
    public function testSymlink_WithExisting_ThrowsLogicException()
    {
        $file    = $this->hooksDir.'/target-symlink';
        $fooFile = $this->hooksDir.'/foo';

        file_put_contents($file, 'foobar');
        touch($fooFile);

        $this->hooks->setSymlink('foo', $file);
    }

    public function testSet()
    {
        $file = $this->hooksDir.'/foo';
        $this->hooks->set('foo', 'bar');

        $this->assertEquals('bar', file_get_contents($file), 'Hook content is correct');

        $perms = fileperms($file);
        $this->assertEquals(0777, $perms & 0777, "Hook permissions are correct");
    }

    public function testSet_Existing_ThrowsLogicException()
    {
        $this->hooks->set('foo', 'bar');

        $this->setExpectedException('LogicException');
        $this->hooks->set('foo', 'bar');
    }

    public function testRemove()
    {
        $file = $this->hooksDir.'/foo';
        touch($file);

        $this->hooks->remove('foo');
        $this->assertFalse(file_exists($file));
    }

    /**
     * @expectedException LogicException
     */
    public function testRemove_NotExisting_ThrowsLogicException()
    {
        $this->hooks->remove('foo');
    }
}
