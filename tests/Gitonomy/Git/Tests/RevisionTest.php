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
use Gitonomy\Git\Log;
use Gitonomy\Git\Revision;

class RevisionTest extends AbstractTest
{
    /**
     * @dataProvider provideFoobar
     */
    public function testGetCommit($repository)
    {
        $revision = $repository->getRevision(self::LONGFILE_COMMIT.'^');

        $this->assertTrue($revision instanceof Revision, "Revision object type");

        $commit = $revision->getResolved();

        $this->assertTrue($commit instanceof Commit, "getResolved returns a Commit");

        $this->assertEquals(self::BEFORE_LONGFILE_COMMIT, $commit->getHash(), "Resolution is correct");
    }

    /**
     * @dataProvider provideFoobar
     * @expectedException Gitonomy\Git\Exception\ReferenceNotFoundException
     * @expectedExceptionMessage Can not find reference "non-existent-commit"
     */
    public function testGetFailingReference($repository)
    {
        $revision = $repository->getRevision('non-existent-commit')->getResolved();
    }

    /**
     * @dataProvider provideFoobar
     */
    public function testGetLog($repository)
    {
        $revision = $repository->getRevision(self::LONGFILE_COMMIT);

        $log = $revision->getLog(null, 2, 3);

        $this->assertTrue($log instanceof Log, "Log type object");
        $this->assertEquals(2, $log->getOffset(), "Log offset is passed");
        $this->assertEquals(3, $log->getLimit(), "Log limit is passed");
        $this->assertEquals(array(self::LONGFILE_COMMIT), $log->getRevisions(), "Revision is passed");
    }
}
