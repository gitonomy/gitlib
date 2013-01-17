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

class BlobTest extends AbtractTest
{
    public function testGetContent()
    {
        $blob = $this->getLibRepository()->getBlob(self::README_BLOB);

        $this->assertContains(self::README_FRAGMENT, $blob->getContent());
    }

    /**
     * @expectedException RuntimeException
     */
    public function testNotExisting()
    {
        $blob = $this->getLibRepository()->getBlob("foobar");
        $blob->getContent();
    }

    public function testGetMimetype()
    {
        $blob = $this->getLibRepository()->getBlob(self::README_BLOB);
        $this->assertRegexp('#text/plain#', $blob->getMimetype());
    }

    public function testIsText()
    {
        $blob = $this->getLibRepository()->getBlob(self::README_BLOB);
        $this->assertTrue($blob->isText());
    }

    public function testIsBinary()
    {
        $blob = $this->getLibRepository()->getBlob(self::README_BLOB);
        $this->assertFalse($blob->isBinary());
    }
}
