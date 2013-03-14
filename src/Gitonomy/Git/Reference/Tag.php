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

use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Reference;

/**
 * Representation of a tag reference.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
class Tag extends Reference
{
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        if (!preg_match('#^refs/tags/(.*)$#', $this->fullname, $vars)) {
            throw new RuntimeException(sprintf('Cannot extract tag name from "%s"', $this->fullname));
        }

        return $vars[1];
    }
}
