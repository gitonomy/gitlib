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

/**
 * The submodules of trees, a Submodule: 2.0 returns Submodule objects, and
 * makes this class its alias.
 *
 * @deprecated since gitonomy/gitlib 1.7, use Submodule instead
 */
class CommitReference extends Submodule
{
    /**
     * @var string
     */
    private $hash;

    public function __construct($hash)
    {
        $this->hash = $hash;
    }

    /**
     * The hash of this class, which the objects 1.6 serialized have too, unlike the one of Submodule.
     */
    public function getHash(): string
    {
        return $this->hash;
    }
}
