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

/**
 * Administration class for Git repositories.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Admin
{
    /**
     * Initializes a repository and returns the instance. If the repository
     * already exists, this command is safe and does nothing.
     *
     * @param string $path Path to the repository
     *
     * @return Gitonomy\Git\Repository
     */
    public static function init($path)
    {
        system(sprintf('git init -q --bare %s', $path));

        return new Repository($path);
    }
}
