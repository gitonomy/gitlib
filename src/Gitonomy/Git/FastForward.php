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
 * Whether a merge fast-forwards the branch, when its history allows it.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
enum FastForward
{
    /**
     * Fast-forwards when possible, merges otherwise, like "git merge --ff".
     */
    case Allow;

    /**
     * Always creates a merge commit, like "git merge --no-ff".
     */
    case Never;

    /**
     * Refuses anything but a fast-forward, like "git merge --ff-only".
     */
    case Only;
}
