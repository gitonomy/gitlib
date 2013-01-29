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
     * @param string          $path   path to the repository
     * @param boolean         $bare   indicate to create a bare repository
     * @param boolean         $debug  flag indicating if errors should be thrown
     * @param LoggerInterface $logger logger for debug purposes
     *
     * @return Repository
     *
     * @throws RuntimeException Directory exists or not writable (only if debug=true)
     */
    public static function init($path, $bare = true, $debug = true, LoggerInterface $logger = null)
    {
        $builder = ProcessBuilder::create(array('git', 'init', '-q'));

        if ($bare) {
            $builder->add('--bare');
        }

        $builder->add($path);

        $process = $builder->getProcess();
        $process->run();

        if (!$process->isSuccessFul()) {
            $message = sprintf("Error on repository initialization, command wasn't successful (%s). Error output:\n%s", $process->getCommandLine(), $process->getErrorOutput());

            if (null !== $logger) {
                $logger->error($message);
            }

            if (true === $debug) {
                throw new \RuntimeException($message);
            }
        }

        return new Repository($path, $debug, $logger);
    }

    /**
     * Clone a repository to a local path.
     *
     * @param string          $path   indicates where to clone repository
     * @param string          $url    url of repository to clone
     * @param boolean         $bare   indicates if repository should be bare or have a working copy
     * @param boolean         $debug  flag indicating if errors should be thrown
     * @param LoggerInterface $logger logger for debug purpopses
     *
     * @return Repository
     */
    public static function cloneTo($path, $url, $bare = true, $debug = true, LoggerInterface $logger = null)
    {
        $options = array();

        if ($bare) {
            $options[] = '--bare';
        }

        return static::cloneRepository($path, $url, $options, $debug, $logger);
    }

    /**
     * Mirrors a repository (fetch all revisions, not only branches).
     *
     * @param string          $path   indicates where to clone repository
     * @param string          $url    url of repository to clone
     * @param boolean         $debug  flag indicating if errors should be thrown
     * @param LoggerInterface $logger logger for debug purpopses
     *
     * @return Repository
     */
    public static function mirrorTo($path, $url, $debug = true, LoggerInterface $logger = null)
    {
        return static::cloneRepository($path, $url, array('--mirror'), $logger);
    }

    /**
     * Internal method to launch effective ``git clone`` command.
     *
     * @param string          $path    indicates where to clone repository
     * @param string          $url     url of repository to clone
     * @param array           $options arguments to be added to the command-line
     * @param boolean         $debug   flag indicating if errors should be thrown
     * @param LoggerInterface $logger  logger for debug purpopses
     *
     * @return Repository
     */
    private static function cloneRepository($path, $url, array $options = array(), $debug = true, LoggerInterface $logger = null)
    {
        $builder = ProcessBuilder::create(array('git', 'clone', '-q'));

        foreach ($options as $value) {
            $builder->add($value);
        }

        $builder->add($url);
        $builder->add($path);

        $process = $builder->getProcess();
        $process->run();

        if (!$process->isSuccessFul()) {
            throw new \RuntimeException(sprintf('Error while initializing repository: %s', $process->getErrorOutput()));
        }

        return new Repository($path, $debug, $logger);
    }
}
