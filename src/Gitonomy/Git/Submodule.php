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

/**
 * A submodule in a tree: the commit of another repository it points to.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class Submodule
{
    public function __construct(private string $hash)
    {
    }

    /**
     * @return string The commit of the submodule, in its own repository
     */
    public function getHash(): string
    {
        return $this->hash;
    }
}

// For the deprecated name, like Symfony renames classes.
if (!class_exists(CommitReference::class, false)) {
    class_alias(Submodule::class, CommitReference::class);
}
