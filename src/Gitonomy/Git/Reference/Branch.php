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

namespace Gitonomy\Git\Reference;

use Gitonomy\Git\Reference;

/**
 * Representation of a branch reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Branch extends Reference
{
    private $local = null;

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        if (preg_match('#^refs/heads/(?<name>.*)$#', $this->fullname, $vars)) {
            return $vars['name'];
        }

        if (preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $this->fullname, $vars)) {
            return $vars['remote'].'/'.$vars['name'];
        }

        throw new \RuntimeException(sprintf('Cannot extract branch name from "%s"', $this->fullname));
    }

    public function isRemote()
    {
        $this->detectBranchType();

        return !$this->local;
    }

    public function isLocal()
    {
        $this->detectBranchType();

        return $this->local;
    }

    private function detectBranchType()
    {
        if (null === $this->local) {
            $this->local = !preg_match('#^refs/remotes/(?<remote>[^/]*)/(?<name>.*)$#', $this->fullname);
        }
    }
}
