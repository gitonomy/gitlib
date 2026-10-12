<?php

/*
 * This file is part of Gitonomy.
 *
 * (c) Alexandre Salomé <alexandre.salome@gmail.com>
 * (c) Julien DIDIER <genzo.wm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gitonomy\Git;

use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Driver\DriverInterface;

/**
 * Administration class for Git repositories.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class Admin
{
    /**
     * Initializes a repository and returns the instance.
     *
     * @param string          $path    path to the repository
     * @param bool            $bare    indicate to create a bare repository
     * @param array           $options options for Repository creation
     * @param DriverInterface $driver  backend used to run operations
     *
     * @throws Exception\DriverException Directory exists or not writable
     */
    public static function init(string $path, bool $bare = true, array $options = [], DriverInterface $driver = new CliDriver()): Repository
    {
        if ($bare) {
            $driver->initBare($path);
        } else {
            $driver->init($path);
        }

        return new Repository($path, $options, $driver);
    }

    /**
     * Checks the validity of a git repository url without cloning it.
     *
     * This will use the `ls-remote` command of git against the given url.
     * Usually, this command returns 0 when successful, and 128 when the
     * repository is not found.
     *
     * @param string          $url    url of repository to check
     * @param DriverInterface $driver backend used to run operations
     *
     * @return bool true if url is valid
     */
    public static function isValidRepository(string $url, DriverInterface $driver = new CliDriver()): bool
    {
        return $driver->isValidRepository($url);
    }

    /**
     * Checks the validity of a git repository url without cloning it and
     * check if a certain branch exists in that repository.
     *
     * This will use the `ls-remote` command of git against the given url.
     * Usually, this command returns 0 when successful, and 128 when the
     * repository is not found.
     *
     * @param string          $url        url of repository to check
     * @param string          $branchName name of branch to check
     * @param DriverInterface $driver     backend used to run operations
     *
     * @return bool true if url is valid and branch exists
     */
    public static function isValidRepositoryAndBranch(string $url, string $branchName, DriverInterface $driver = new CliDriver()): bool
    {
        return $driver->isValidRepositoryAndBranch($url, $branchName);
    }

    /**
     * Clone a repository to a local path.
     *
     * @param string          $path    indicates where to clone repository
     * @param string          $url     url of repository to clone
     * @param bool            $bare    indicates if repository should be bare or have a working copy
     * @param array           $options options for Repository creation
     * @param DriverInterface $driver  backend used to run operations
     */
    public static function cloneTo(string $path, string $url, bool $bare = true, array $options = [], DriverInterface $driver = new CliDriver()): Repository
    {
        $args = $bare ? ['--bare'] : [];

        return self::cloneRepository($path, $url, $args, $options, $driver);
    }

    /**
     * Clone a repository branch to a local path.
     *
     * @param string          $path    indicates where to clone repository
     * @param string          $url     url of repository to clone
     * @param string          $branch  branch to clone
     * @param bool            $bare    indicates if repository should be bare or have a working copy
     * @param array           $options options for Repository creation
     * @param DriverInterface $driver  backend used to run operations
     */
    public static function cloneBranchTo(string $path, string $url, string $branch, bool $bare = true, array $options = [], DriverInterface $driver = new CliDriver()): Repository
    {
        $args = ['--branch', $branch];
        if ($bare) {
            $args[] = '--bare';
        }

        return self::cloneRepository($path, $url, $args, $options, $driver);
    }

    /**
     * Mirrors a repository (fetch all revisions, not only branches).
     *
     * @param string          $path    indicates where to clone repository
     * @param string          $url     url of repository to clone
     * @param array           $options options for Repository creation
     * @param DriverInterface $driver  backend used to run operations
     */
    public static function mirrorTo(string $path, string $url, array $options = [], DriverInterface $driver = new CliDriver()): Repository
    {
        return self::cloneRepository($path, $url, ['--mirror'], $options, $driver);
    }

    /**
     * Internal method to launch effective ``git clone`` command.
     *
     * @param string          $path    indicates where to clone repository
     * @param string          $url     url of repository to clone
     * @param array           $args    arguments to be added to the command-line
     * @param array           $options options for Repository creation
     * @param DriverInterface $driver  backend used to run operations
     */
    public static function cloneRepository(string $path, string $url, array $args = [], array $options = [], DriverInterface $driver = new CliDriver()): Repository
    {
        $driver->cloneRepository($path, $url, $args);

        return new Repository($path, $options, $driver);
    }
}
