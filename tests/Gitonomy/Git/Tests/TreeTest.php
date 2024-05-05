<?php

/**
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git\Tests;

use Gitonomy\Git\Blob;
use Gitonomy\Git\CommitReference;

class TreeTest extends AbstractTest
{
    const PATH_RESOLVING_COMMIT = 'cc06ac171d884282202dff88c1ded499a1f89420';

    /**
     * @dataProvider provideFooBar
     */
    public function testGetEntries($repository)
    {
        $tree = $repository->getCommit(self::LONGFILE_COMMIT)->getTree();

        $entries = $tree->getEntries();

        $this->assertNotEmpty($entries['long.php'], 'long.php is present');
        $this->assertTrue($entries['long.php'][1] instanceof Blob, 'long.php is a Blob');

        $this->assertNotEmpty($entries['README.md'], 'README.md is present');
        $this->assertTrue($entries['README.md'][1] instanceof Blob, 'README.md is a Blob');
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testGetCommitReferenceEntries($repository)
    {
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $commits = $tree->getCommitReferenceEntries();

        $this->assertNotEmpty($commits['barbaz'], 'barbaz is present');
        $this->assertTrue($commits['barbaz'][1] instanceof CommitReference, 'barbaz is a Commit');
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testGetTreeEntries($repository)
    {
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $trees = $tree->getTreeEntries();

        $this->assertEmpty($trees);
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testGetBlobEntries($repository)
    {
        $tree = $repository->getCommit(self::NO_MESSAGE_COMMIT)->getTree();

        $blobs = $tree->getBlobEntries();

        $this->assertNotEmpty($blobs['README.md'], 'README.md is present');
        $this->assertTrue($blobs['README.md'][1] instanceof Blob, 'README.md is a blob');
    }

    /**
     * @dataProvider provideFooBar
     */
    public function testResolvePath($repository)
    {
        $tree = $repository->getCommit(self::PATH_RESOLVING_COMMIT)->getTree();
        $path = 'test/a/b/c';

        $resolved = $tree->resolvePath($path);
        $entries = $resolved->getEntries();

        $this->assertNotEmpty($entries['d'], 'Successfully resolved source folder');
    }
}
