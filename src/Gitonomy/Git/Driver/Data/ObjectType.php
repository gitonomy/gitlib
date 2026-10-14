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
 * The type of a git object.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
enum ObjectType: string
{
    case Blob = 'blob';
    case Commit = 'commit';
    case Tag = 'tag';
    case Tree = 'tree';
}
