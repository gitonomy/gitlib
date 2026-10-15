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

namespace Gitonomy\Git;

use Gitonomy\Git\Driver\Cli\CliDriver;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Util\LegacyOverride;

/**
 * Administration class for Git repositories.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @deprecated since gitonomy/gitlib 1.7, use the static methods of Repository instead
 *
 * @final since gitonomy/gitlib 1.7
 */
class Admin
{
    /**
     * Initializes a repository and returns the instance.
     *
     * @param string $path    path to the repository
     * @param bool   $bare    indicate to create a bare repository
     * @param array  $options options for Repository creation
     *
     * @throws RuntimeException Directory exists or not writable (only if debug=true)
     *
     * @return Repository
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::initBare() or Repository::init() instead
     */
    public static function init($path, $bare = true, array $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::%s()" instead.', __METHOD__, Repository::class, $bare ? 'initBare' : 'init');

        $driver = CliDriver::fromOptions($options);
        if ($bare) {
            $driver->initBare($path);
        } else {
            $driver->init($path);
        }

        return new Repository($path, $options);
    }

    /**
     * Checks the validity of a git repository url without cloning it.
     *
     * This will use the `ls-remote` command of git against the given url.
     * Usually, this command returns 0 when successful, and 128 when the
     * repository is not found.
     *
     * @param string $url     url of repository to check
     * @param array  $options options for Repository creation
     *
     * @return bool true if url is valid
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::isValidRepository() instead
     */
    public static function isValidRepository($url, array $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::isValidRepository()" instead.', __METHOD__, Repository::class);

        // Unlike Repository::isValidRepository(), in the working directory of PHP, where a relative path may name a remote.
        $process = CliDriver::fromOptions($options)->createProcess('ls-remote', [$url]);

        $process->run();

        return $process->isSuccessFul();
    }

    /**
     * Checks the validity of a git repository url without cloning it and
     * check if a certain branch exists in that repository.
     *
     * This will use the `ls-remote` command of git against the given url.
     * Usually, this command returns 0 when successful, and 128 when the
     * repository is not found.
     *
     * @param string $url        url of repository to check
     * @param string $branchName name of branch to check
     * @param array  $options    options for Repository creation
     *
     * @return bool true if url is valid and branch exists
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::isValidRepositoryAndBranch() instead
     */
    public static function isValidRepositoryAndBranch($url, $branchName, array $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::isValidRepositoryAndBranch()" instead.', __METHOD__, Repository::class);

        // Unlike Repository::isValidRepositoryAndBranch(), "release" matches "feature/release", and "rel" "release",
        // in the working directory of PHP.
        $process = CliDriver::fromOptions($options)->createProcess('ls-remote', ['--heads', $url, $branchName]);

        $process->run();
        $processOutput = $process->getOutput();

        return $process->isSuccessFul() && strpos($processOutput, $branchName) !== false;
    }

    /**
     * Clone a repository to a local path.
     *
     * @param string $path    indicates where to clone repository
     * @param string $url     url of repository to clone
     * @param bool   $bare    indicates if repository should be bare or have a working copy
     * @param array  $options options for Repository creation
     *
     * @return Repository
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::cloneBareFrom() or Repository::cloneFrom() instead
     */
    public static function cloneTo($path, $url, $bare = true, array $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::%s()" instead, which takes the URL before the path.', __METHOD__, Repository::class, $bare ? 'cloneBareFrom' : 'cloneFrom');

        $args = $bare ? ['--bare'] : [];

        return self::cloneWith($path, $url, $args, $options);
    }

    /**
     * Clone a repository branch to a local path.
     *
     * @param string $path    indicates where to clone repository
     * @param string $url     url of repository to clone
     * @param string $branch  branch to clone
     * @param bool   $bare    indicates if repository should be bare or have a working copy
     * @param array  $options options for Repository creation
     *
     * @return Repository
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::cloneBareFrom() or Repository::cloneFrom() with their "branch" argument instead
     */
    public static function cloneBranchTo($path, $url, $branch, $bare = true, $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::%s()" with its "branch" argument instead, which takes the URL before the path.', __METHOD__, Repository::class, $bare ? 'cloneBareFrom' : 'cloneFrom');

        $args = ['--branch', $branch];
        if ($bare) {
            $args[] = '--bare';
        }

        return self::cloneWith($path, $url, $args, $options);
    }

    /**
     * Mirrors a repository (fetch all revisions, not only branches).
     *
     * @param string $path    indicates where to clone repository
     * @param string $url     url of repository to clone
     * @param array  $options options for Repository creation
     *
     * @return Repository
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::mirrorFrom() instead
     */
    public static function mirrorTo($path, $url, array $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::mirrorFrom()" instead, which takes the URL before the path.', __METHOD__, Repository::class);

        return self::cloneWith($path, $url, ['--mirror'], $options);
    }

    /**
     * Internal method to launch effective ``git clone`` command.
     *
     * @param string $path    indicates where to clone repository
     * @param string $url     url of repository to clone
     * @param array  $args    arguments to be added to the command-line
     * @param array  $options options for Repository creation
     *
     * @return Repository
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::cloneFrom(), Repository::cloneBareFrom() or Repository::mirrorFrom() instead
     */
    public static function cloneRepository($path, $url, array $args = [], array $options = [])
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%1$s()" method is deprecated, use "%2$s::cloneFrom()", "%2$s::cloneBareFrom()" or "%2$s::mirrorFrom()" instead, which take the URL before the path; they support no other argument of "git clone".', __METHOD__, Repository::class);

        CliDriver::fromOptions($options)->cloneRepository($url, $path, $args);

        return new Repository($path, $options);
    }

    private static function cloneWith($path, $url, array $args, array $options)
    {
        // Like in 1.6, a subclass overriding cloneRepository() clones the repository.
        if (LegacyOverride::isOverridden(static::class, self::class, 'cloneRepository')) {
            return static::cloneRepository($path, $url, $args, $options);
        }

        CliDriver::fromOptions($options)->cloneRepository($url, $path, $args);

        return new Repository($path, $options);
    }
}
