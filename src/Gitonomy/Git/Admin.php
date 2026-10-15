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
use Gitonomy\Git\Driver\Cli\LegacyOptions;

/**
 * Administration class for Git repositories.
 *
 * Its methods behave like in 1.x: the git options of 1.x (command,
 * environment_variables, inherit_environment_variables and process_timeout)
 * configure the CliDriver they run, and the repositories they return;
 * inherit_environment_variables only applies to these repositories.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 *
 * @deprecated since gitonomy/gitlib 1.7, use the static methods of Repository instead
 *
 * @phpstan-import-type Options from LegacyOptions
 */
final class Admin
{
    /**
     * Initializes a repository and returns the instance.
     *
     * @param string  $path    path to the repository
     * @param bool    $bare    indicate to create a bare repository
     * @param Options $options options for Repository creation
     *
     * @throws Exception\DriverException Directory exists or not writable
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::initBare() or Repository::init() instead
     */
    public static function init(string $path, bool $bare = true, array $options = []): Repository
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::%s()" instead.', __METHOD__, Repository::class, $bare ? 'initBare' : 'init');

        $options = new LegacyOptions($options);
        $driver = $options->adminDriver() ?? new CliDriver();
        if ($bare) {
            $driver->initBare($path);
        } else {
            $driver->init($path);
        }

        return new Repository($path, $options->workingDir, $options->repositoryDriver() ?? $driver);
    }

    /**
     * Checks the validity of a git repository url without cloning it.
     *
     * This will use the `ls-remote` command of git against the given url.
     * Usually, this command returns 0 when successful, and 128 when the
     * repository is not found.
     *
     * @param string  $url     url of repository to check
     * @param Options $options options for Repository creation
     *
     * @return bool true if url is valid
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::isValidRepository() instead
     */
    public static function isValidRepository(#[\SensitiveParameter] string $url, array $options = []): bool
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::isValidRepository()" instead.', __METHOD__, Repository::class);

        return (new LegacyOptions($options)->adminDriver() ?? new CliDriver())->isValidRepository($url);
    }

    /**
     * Checks the validity of a git repository url without cloning it and
     * check if a certain branch exists in that repository.
     *
     * This will use the `ls-remote` command of git against the given url.
     * Usually, this command returns 0 when successful, and 128 when the
     * repository is not found.
     *
     * @param string  $url        url of repository to check
     * @param string  $branchName name of branch to check
     * @param Options $options    options for Repository creation
     *
     * @return bool true if url is valid and branch exists
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::isValidRepositoryAndBranch() instead
     */
    public static function isValidRepositoryAndBranch(#[\SensitiveParameter] string $url, string $branchName, array $options = []): bool
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::isValidRepositoryAndBranch()" instead.', __METHOD__, Repository::class);

        return (new LegacyOptions($options)->adminDriver() ?? new CliDriver())->isValidRepositoryAndBranch($url, $branchName);
    }

    /**
     * Clone a repository to a local path.
     *
     * @param string  $path    indicates where to clone repository
     * @param string  $url     url of repository to clone
     * @param bool    $bare    indicates if repository should be bare or have a working copy
     * @param Options $options options for Repository creation
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::cloneBareFrom() or Repository::cloneFrom() instead
     */
    public static function cloneTo(string $path, #[\SensitiveParameter] string $url, bool $bare = true, array $options = []): Repository
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::%s()" instead, which takes the URL before the path.', __METHOD__, Repository::class, $bare ? 'cloneBareFrom' : 'cloneFrom');

        $options = new LegacyOptions($options);
        $driver = $options->adminDriver() ?? new CliDriver();
        if ($bare) {
            $driver->cloneBareFrom($url, $path, null);
        } else {
            $driver->cloneFrom($url, $path, null);
        }

        return new Repository($path, $options->workingDir, $options->repositoryDriver() ?? $driver);
    }

    /**
     * Clone a repository branch to a local path.
     *
     * @param string  $path    indicates where to clone repository
     * @param string  $url     url of repository to clone
     * @param string  $branch  branch to clone
     * @param bool    $bare    indicates if repository should be bare or have a working copy
     * @param Options $options options for Repository creation
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::cloneBareFrom() or Repository::cloneFrom() with their "branch" argument instead
     */
    public static function cloneBranchTo(string $path, #[\SensitiveParameter] string $url, string $branch, bool $bare = true, array $options = []): Repository
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::%s()" with its "branch" argument instead, which takes the URL before the path.', __METHOD__, Repository::class, $bare ? 'cloneBareFrom' : 'cloneFrom');

        $options = new LegacyOptions($options);
        $driver = $options->adminDriver() ?? new CliDriver();
        if ($bare) {
            $driver->cloneBareFrom($url, $path, $branch);
        } else {
            $driver->cloneFrom($url, $path, $branch);
        }

        return new Repository($path, $options->workingDir, $options->repositoryDriver() ?? $driver);
    }

    /**
     * Mirrors a repository (fetch all revisions, not only branches).
     *
     * @param string  $path    indicates where to clone repository
     * @param string  $url     url of repository to clone
     * @param Options $options options for Repository creation
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::mirrorFrom() instead
     */
    public static function mirrorTo(string $path, #[\SensitiveParameter] string $url, array $options = []): Repository
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%s()" method is deprecated, use "%s::mirrorFrom()" instead, which takes the URL before the path.', __METHOD__, Repository::class);

        $options = new LegacyOptions($options);
        $driver = $options->adminDriver() ?? new CliDriver();
        $driver->mirrorFrom($url, $path);

        return new Repository($path, $options->workingDir, $options->repositoryDriver() ?? $driver);
    }

    /**
     * Internal method to launch effective ``git clone`` command.
     *
     * @param string   $path    indicates where to clone repository
     * @param string   $url     url of repository to clone
     * @param string[] $args    arguments to be added to the command-line
     * @param Options  $options options for Repository creation
     *
     * @deprecated since gitonomy/gitlib 1.7, use Repository::cloneFrom(), Repository::cloneBareFrom() or Repository::mirrorFrom() instead
     */
    public static function cloneRepository(string $path, #[\SensitiveParameter] string $url, array $args = [], array $options = []): Repository
    {
        trigger_deprecation('gitonomy/gitlib', '1.7', 'The "%1$s()" method is deprecated, use "%2$s::cloneFrom()", "%2$s::cloneBareFrom()" or "%2$s::mirrorFrom()" instead, which take the URL before the path; they support no other argument of "git clone".', __METHOD__, Repository::class);

        $options = new LegacyOptions($options);
        $driver = $options->adminDriver() ?? new CliDriver();
        $driver->cloneRepository($path, $url, $args);

        return new Repository($path, $options->workingDir, $options->repositoryDriver() ?? $driver);
    }
}
