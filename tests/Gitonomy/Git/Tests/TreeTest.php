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

use Gitonomy\Git\Blob;

class TreeTest extends TestBase
{
    public function testCase()
    {
        $tree = $this->getLibRepository()->getCommit(self::TRAVIS_COMMIT)->getTree();

        $entries = $tree->getEntries();

        $this->assertTrue(isset($entries['.gitignore']), ".gitignore is present");
        $this->assertTrue($entries['.gitignore'][1] instanceof Blob, ".gitignore is a Blob");

        $this->assertTrue(isset($entries['README.md']), "README.md is present");
        $this->assertTrue($entries['README.md'][1] instanceof Blob, "README.md is a Blob");
        $this->assertEquals(self::README_BLOB, $entries['README.md'][1]->getHash(), "README.md hash is correct");
    }

    public function testResolvePath()
    {
        $tree = $this->getLibRepository()->getCommit(self::TRAVIS_COMMIT)->getTree();
        $path = 'src/Gitonomy/Git';

        $resolved = $tree->resolvePath($path);
        $entries = $resolved->getEntries();

        $this->assertTrue(isset($entries['Admin.php']), "Successfully resolved source folder");
    }
}
