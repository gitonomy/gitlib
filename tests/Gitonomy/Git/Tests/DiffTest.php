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

use Gitonomy\Git\Diff;

class DiffTest extends AbstractTest
{
    public function testGetRevisions()
    {
        $diff = $this->getTravisDiff();

        $this->assertEquals(array(self::TRAVIS_COMMIT), $diff->getRevisions(), "Revision returns passed revision");
    }

    public function testGetFiles_Addition()
    {
        $files = $this->getTravisDiff()->getFiles();

        $this->assertEquals(2, count($files), "2 files in diff");

        $this->assertTrue($files[0]->isCreation(), ".travis.yml created");

        $this->assertEquals(null,          $files[0]->getOldName(), "First file name is a new file");
        $this->assertEquals('.travis.yml', $files[0]->getNewName(), "First file name is .travis.yml");
        $this->assertEquals(null,          $files[0]->getOldMode(), "First file mode is a new file");
        $this->assertEquals('100644',      $files[0]->getNewMode(), "First file mode is correct");

        $this->assertEquals(7, $files[0]->getAdditions(), "10 lines added");
        $this->assertEquals(0, $files[0]->getDeletions(), "0 lines deleted");

        try {
            $files[0]->getOldBlob();
            $this->fail("Should not be able to get old blob on addition");
        } catch (\LogicException $e) {}

        $this->assertContains("language: php", $files[0]->getNewBlob()->getContent());
    }

    public function testGetFiles_Modification()
    {
        $files = $this->getTravisDiff()->getFiles();

        $this->assertEquals(2, count($files), "2 files in diff");

        $this->assertTrue($files[1]->isModification(), "README.md modified");

        $this->assertEquals('README.md', $files[1]->getOldName(), "Second file name is a new file");
        $this->assertEquals('README.md', $files[1]->getNewName(), "Second file name is .travis.yml");
        $this->assertEquals('100644',    $files[1]->getOldMode(), "Second file mode is a new file");
        $this->assertEquals('100644',    $files[1]->getNewMode(), "Second file mode is correct");

        $this->assertEquals(2, $files[1]->getAdditions(), "2 lines added");
        $this->assertEquals(0, $files[1]->getDeletions(), "0 lines deleted");

        $oldBlob = $files[1]->getOldBlob();
        $newBlob = $files[1]->getNewBlob();

        $this->assertNotContains("Build Status", $oldBlob->getContent());
        $this->assertContains("Build Status", $newBlob->getContent());
    }

    public function testGetFiles_Deletion()
    {
        $files = $this->getDocDiff()->getFiles();

        $this->assertEquals(7, count($files), "7 files modified");

        $this->assertTrue($files[3]->isDeletion(), "4th file is a deletion");
        $this->assertEquals("doc/api/objects.rst", $files[3]->getOldName(), "4th file is doc/api/objects.rst");
        $this->assertEquals(28, $files[3]->getDeletions(), "4th file is doc/api/objects.rst");

        try {
            $files[3]->getNewBlob();
            $this->fail("Should not be able to get new blob on deletion");
        } catch (\LogicException $e) {}
    }

    public function testFileChanges()
    {
        $files = $this->getTravisDiff()->getFiles();

        $this->assertEquals(2, count($files), "2 files modified");
    }

    public function testDiffRangeParse()
    {
        $files = $this->getTravisDiff()->getFiles();

        $changes = $files[0]->getChanges();

        $this->assertEquals(0, $changes[0]->getRangeOldStart());
        $this->assertEquals(0, $changes[0]->getRangeOldCount());

        $this->assertEquals(1, $changes[0]->getRangeNewStart());
        $this->assertEquals(7, $changes[0]->getRangeNewCount());
    }

    private function getDocDiff()
    {
        return $this->getLibRepository()->getCommit(self::DOC_COMMIT)->getDiff();
    }

    private function getTravisDiff()
    {
        return $this->getLibRepository()->getCommit(self::TRAVIS_COMMIT)->getDiff();
    }
}
