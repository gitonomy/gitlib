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

abstract class AbstractTest extends \PHPUnit_Framework_TestCase
{
    const REPOSITORY_URL = 'git://github.com/gitonomy/foobar.git';

    const LONGFILE_COMMIT        = '4f17752acc9b7c54ba679291bf24cb7d354f0f4f';
    const BEFORE_LONGFILE_COMMIT = 'e0ec50e2af75fa35485513f60b2e658e245227e9';
    const INITIAL_COMMIT         = '74acd054c8ec873ae6be044041d3a85a4f890ba5';

    /**
     * Local clone of remote URL. Avoids network call on each test.
     */
    private static $localRepository;

    /**
     * Creates an empty git repository and returns instance.
     *
     * @return Repository
     */
    public static function createEmptyRepository($bare = true)
    {
        $dir = self::createTempDir();
        $repository = Admin::init($dir, $bare);
        self::registerDeletion($repository);

        return $repository;
    }

    /**
     * Can be used as data provider to get bare/not-bare repositories.
     */
    public static function provideFoobar()
    {
        return array(
            array(self::createFoobarRepository()),
            array(self::createFoobarRepository(false))
        );
    }

    /**
     * Creates a fixture test repository.
     *
     * @return Repository
     */
    public static function createFoobarRepository($bare = true)
    {
        if (null === self::$localRepository) {
            self::$localRepository = Admin::cloneTo(self::createTempDir(), self::REPOSITORY_URL);
            self::registerDeletion(self::$localRepository);
        }

        $repository = self::$localRepository->cloneTo(self::createTempDir(), $bare);
        self::registerDeletion($repository);

        return $repository;
    }

    public static function registerDeletion(Repository $repository)
    {
        register_shutdown_function(function () use ($repository) {
            if ($repository->getWorkingDir()) {
                $dir = $repository->getWorkingDir();
            } else {
                $dir = $repository->getGitDir();
            }
            AbstractTest::deleteDir($dir);
        });
    }

    /**
     * Created an empty directory and return path to it.
     *
     * @return string a fullpath
     */
    public static function createTempDir()
    {
        $tmpDir = tempnam(sys_get_temp_dir(), 'gitlib_');
        unlink($tmpDir);
        mkdir($tmpDir);

        return $tmpDir;
    }

    /**
     * Deletes a directory recursively.
     *
     * @param string $dir directory to delete
     */
    public static function deleteDir($dir)
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
}
