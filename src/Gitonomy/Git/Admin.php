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

namespace Gitonomy\Git;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Administration class for Git repositories.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Admin
{
    /**
     * Initializes a repository and returns the instance.
     *
     * @param string  $path    path to the repository
     * @param boolean $bare    indicate to create a bare repository
     * @param array   $options options for Repository creation
     *
     * @return Repository
     *
     * @throws RuntimeException Directory exists or not writable (only if debug=true)
     */
    public static function init($path, $bare = true, array $options = array())
    {
        $builder = ProcessBuilder::create(array('git', 'init', '-q'));

        if ($bare) {
            $builder->add('--bare');
        }

        $builder->add($path);

        $builder->inheritEnvironmentVariables(false);
        $process = $builder->getProcess();
        if (isset($options['environment_variables'])) {
            $process->setEnv($options['environment_variables']);
        }
        $process->run();

        if (!$process->isSuccessFul()) {
            throw new \RuntimeException(sprintf("Error on repository initialization, command wasn't successful (%s). Error output:\n%s", $process->getCommandLine(), $process->getErrorOutput()));
        }

        return new Repository($path, $options);
    }

    /**
     * Clone a repository to a local path.
     *
     * @param string  $path   indicates where to clone repository
     * @param string  $url    url of repository to clone
     * @param boolean $bare   indicates if repository should be bare or have a working copy
     * @param array   $options options for Repository creation
     *
     * @return Repository
     */
    public static function cloneTo($path, $url, $bare = true, array $options = array())
    {
        $args = $bare ? array('--bare') : array();

        return static::cloneRepository($path, $url, $args, $options);
    }

    /**
     * Mirrors a repository (fetch all revisions, not only branches).
     *
     * @param string  $path   indicates where to clone repository
     * @param string  $url    url of repository to clone
     * @param array   $options options for Repository creation
     *
     * @return Repository
     */
    public static function mirrorTo($path, $url, array $options = array())
    {
        return static::cloneRepository($path, $url, array('--mirror'), $options);
    }

    /**
     * Internal method to launch effective ``git clone`` command.
     *
     * @param string  $path    indicates where to clone repository
     * @param string  $url     url of repository to clone
     * @param array   $args    arguments to be added to the command-line
     * @param array   $options options for Repository creation
     *
     * @return Repository
     */
    private static function cloneRepository($path, $url, array $args = array(), array $options = array())
    {
        $builder = ProcessBuilder::create(array('git', 'clone', '-q'));
        foreach ($args as $value) {
            $builder->add($value);
        }
        $builder->add($url);
        $builder->add($path);

        $builder->inheritEnvironmentVariables(false);
        if (isset($options['environment_variables'])) {
            $builder->setEnv($options['environment_variables']);
        }

        $process = $builder->getProcess();
        $process->run();

        if (!$process->isSuccessFul()) {
            throw new \RuntimeException(sprintf('Error while initializing repository: %s', $process->getErrorOutput()));
        }

        return new Repository($path, $options);
    }
}
