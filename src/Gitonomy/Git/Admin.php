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
     * @param string  $path Path to the repository
     * @param boolean $bare Indicate to create a bare repository
     *
     * @return Repository
     *
     * @throws RuntimeException Directory exists or not writable
     */
    public static function init($path, $bare = true, LoggerInterface $logger = null)
    {
        $builder = ProcessBuilder::create(array('git', 'init', '-q'));

        if ($bare) {
            $builder->add('--bare');
        }

        $builder->add($path);

        $process = $builder->getProcess();
        $process->run();

        if (!$process->isSuccessFul()) {
            throw new \RuntimeException(sprintf('Error while initializing repository: %s', $process->getErrorOutput()));
        }

        return new Repository($path, null, $logger);
    }

    public static function cloneTo($path, $url, $bare = true, LoggerInterface $logger = null)
    {
        $builder = ProcessBuilder::create(array('git', 'clone', '-q'));

        if ($bare) {
            $builder->add('--bare');
        }

        $builder->add($url);
        $builder->add($path);

        $process = $builder->getProcess();
        $process->run();

        if (!$process->isSuccessFul()) {
            throw new \RuntimeException(sprintf('Error while initializing repository: %s', $process->getErrorOutput()));
        }

        return new Repository($path, null, $logger);
    }
}
