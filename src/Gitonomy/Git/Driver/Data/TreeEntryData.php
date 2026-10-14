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

namespace Gitonomy\Git\Driver\Data;

/**
 * One entry of a tree, as read by a Driver.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class TreeEntryData
{
    public function __construct(
        public string $mode,
        public ObjectType $type,
        public string $hash,
        public string $name,
    ) {
    }
}
