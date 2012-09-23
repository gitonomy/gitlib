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
    private static $repo;

    // Initial commit is the first commit of the repository
    const INITIAL_COMMIT       = '1040d331549232a7d64907ec75d71d31da2e43f4';
    const INITIAL_TREE         = '8bfd3135e80ee17c0d12d4b0f0f2297469aafdb7';

    // Travis commit is the commit integrating Travis-CI to the project
    const TRAVIS_COMMIT        = '6964dfd6bdc1b4449f8de2d687e4609f08219cf2';
    const TRAVIS_PARENT_COMMIT = '922b7419044ddab753f66e163bbdd8c236f4d21e';

    // Doc & Test commit (new file, deleted file, modified files)
    const DOC_COMMIT           = 'f3c32f1e23d46391380c84a8cb388d1b86de9dfc';

    // References a blob in project: the README file
    const README_BLOB     = 'e43530af24200d2ba946db7e6a069899287ec772';
    const README_FRAGMENT = 'methods to access Git repository';

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
        if (null === self::$repo) {
            $dir = __DIR__.'/../../../../test-sandbox';
            if (!is_dir($dir)) {
                $this->markTestSkipped("Test sandbox folder not present");
            }

            self::$repo = new Repository($dir);
        }

        return self::$repo;
    }
}
