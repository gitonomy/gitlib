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
 * One reference entry (branch, tag, stash...), as read by a Driver.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final readonly class ReferenceData
{
    public function __construct(
        public string $fullname,
        public string $commitHash,
    ) {
    }
}
