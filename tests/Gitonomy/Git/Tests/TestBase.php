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

use Gitonomy\Git\Repository;

class TestBase extends \PHPUnit_Framework_TestCase
{
    public function createTempDir()
    {
        $tmpDir = tempnam(sys_get_temp_dir(), 'gitlib_');
        unlink($tmpDir);
        mkdir($tmpDir);

        return $tmpDir;
    }

    public function deleteDir($dir)
    {
        $iterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS);
        $iterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if (is_dir($file)) {
                rmdir($file);
            } else {
                unlink($file);
            }
        }

        rmdir($dir);
    }

    public function getLibRepository()
    {
        return new Repository(__DIR__.'/../../../../');
    }
}
