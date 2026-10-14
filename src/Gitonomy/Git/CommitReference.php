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

trigger_deprecation('gitonomy/gitlib', '2.0', 'The "%s" class is deprecated, use "%s" instead.', CommitReference::class, Submodule::class);

// Declares the alias, unless Submodule already did.
class_exists(Submodule::class);

// @phpstan-ignore if.alwaysFalse (Declares the class for IDEs only.)
if (false) {
    /**
     * @deprecated since gitonomy/gitlib 2.0, use Submodule instead
     */
    final readonly class CommitReference
    {
    }
}
