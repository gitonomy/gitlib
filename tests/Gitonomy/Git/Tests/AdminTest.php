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
use Gitonomy\Git\Repository;

class AdminTest extends AbstractTest
{
    private $tmpDir;

    public function setUp()
    {
        $this->tmpDir = self::createTempDir();
    }

    public function tearDown()
    {
        $this->deleteDir(self::createTempDir());
    }

    public function testBare()
    {
        $repository = Admin::init($this->tmpDir);

        $objectDir = $this->tmpDir.'/objects';

        $this->assertTrue($repository->isBare(), "Repository is bare");
        $this->assertTrue(is_dir($objectDir),     "objects/ folder is present");
        $this->assertTrue($repository instanceof Repository, "Admin::init returns a repository");
        $this->assertEquals($this->tmpDir, $repository->getGitDir(), "The folder passed as argument is git dir");
        $this->assertNull($repository->getWorkingDir(), "No working dir in bare repository");
    }

    public function testNotBare()
    {
        $repository = Admin::init($this->tmpDir, false);

        $objectDir = $this->tmpDir.'/.git/objects';

        $this->assertFalse($repository->isBare(), "Repository is not bare");
        $this->assertTrue(is_dir($objectDir), "objects/ folder is present");
        $this->assertTrue($repository instanceof Repository, "Admin::init returns a repository");
        $this->assertEquals($this->tmpDir.'/.git', $repository->getGitDir(), "git dir as subfolder of argument");
        $this->assertEquals($this->tmpDir, $repository->getWorkingDir(), "working dir present in bare repository");
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testClone($repository)
    {
        $newDir = self::createTempDir();
        $new = $repository->cloneTo($newDir, $repository->isBare());
        self::registerDeletion($new);

        $newRefs = array_keys($new->getReferences()->getAll());

        $this->assertTrue(in_array('refs/heads/master', $newRefs));
        $this->assertTrue(in_array('refs/tags/0.1', $newRefs));

        if ($repository->isBare()) {
            $this->assertEquals($newDir, $new->getGitDir());
        $this->assertTrue(in_array('refs/heads/new-feature', $newRefs));
        } else {
            $this->assertEquals($newDir.'/.git', $new->getGitDir());
            $this->assertEquals($newDir, $new->getWorkingDir());
        }
    }

    /**
     * @expectedException RuntimeException
     */
    public function testExistingFile()
    {
        $file = $this->tmpDir.'/test';
        touch($file);

        Admin::init($file);
    }
}
