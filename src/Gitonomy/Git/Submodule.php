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
 * A submodule in a tree: the commit of another repository it points to.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @final
 */
class Submodule
{
    /**
     * @var string
     */
    private $hash;

    public function __construct(string $hash)
    {
        $this->hash = $hash;
    }

    /**
     * @return string The commit of the submodule, in its own repository
     */
    public function getHash(): string
    {
        return $this->hash;
    }
}
