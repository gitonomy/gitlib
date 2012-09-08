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

class AdminTest extends TestBase
{
    private $tmpDir;

    public function setUp()
    {
        $this->tmpDir = $this->createTempDir();
    }

    public function tearDown()
    {
        $this->deleteDir($this->tmpDir);
    }

    public function testBare_WithInitialDirectory_Works()
    {
        Admin::init($this->tmpDir);

        $objectDir = $this->tmpDir.'/objects';
        $this->assertTrue(is_dir($objectDir),     "objects/ folder is present");
    }

    public function testBare_WithoutInitialDirectory_StillWorks()
    {
        Admin::init($this->tmpDir.'/test');

        $objectDir = $this->tmpDir.'/test/objects';
        $this->assertTrue(is_dir($objectDir),     "objects/ folder is present");
    }

    public function testNotBare_WithInitialDirectory_Works()
    {
        Admin::init($this->tmpDir, false);

        $objectDir = $this->tmpDir.'/.git/objects';
        $this->assertTrue(is_dir($objectDir),     "objects/ folder is present");
    }

    public function testNotBare_WithoutInitialDirectory_StillWorks()
    {
        Admin::init($this->tmpDir.'/test', false);

        $objectDir = $this->tmpDir.'/test/.git/objects';
        $this->assertTrue(is_dir($objectDir),     "objects/ folder is present");
    }

    /**
     * @expectedException RuntimeException
     */
    public function testFail()
    {
        $file = $this->tmpDir.'/test';
        touch($file);

        Admin::init($file);
    }
}
