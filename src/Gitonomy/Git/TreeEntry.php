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
 * An entry of a tree: a blob, a tree or a submodule, with its name and mode.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class TreeEntry
{
    public function __construct(
        private string $name,
        private string $mode,
        private Blob|Tree|Submodule $object,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string The mode git stores, like "100644" for a file or "040000" for a tree
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    public function getObject(): Blob|Tree|Submodule
    {
        return $this->object;
    }
}
