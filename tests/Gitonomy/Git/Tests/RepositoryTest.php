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

class RepositoryTest extends TestBase
{
    public function testGetBlob_WithExisting_Works()
    {
        $repo = self::getLibRepository();

        $blob = $repo->getBlob(self::README_BLOB);
        $this->assertTrue($blob instanceof Blob, "getBlob() returns a Blob object");
        $this->assertEquals(self::README_BLOB, $blob->getHash(), "getHash() returns passed hash");
    }

    public function testGetSize()
    {
        $repo = self::getLibRepository();

        $size = $repo->getSize();
        $this->assertGreaterThan(500, $size, "Repository is greater than 500KB");
    }
}
