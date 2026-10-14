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

namespace Gitonomy\Git\Diff;

/**
 * The kind of a line of a hunk, its values those of the deprecated
 * FileChange::LINE_* constants.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
enum DiffLineType: int
{
    case Addition = 1;
    case Context = 0;
    case Deletion = -1;
}
