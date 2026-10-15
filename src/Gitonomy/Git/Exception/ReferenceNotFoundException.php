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

namespace Gitonomy\Git\Exception;

/**
 * @final since gitonomy/gitlib 1.7
 */
class ReferenceNotFoundException extends \InvalidArgumentException implements GitExceptionInterface
{
    public function __construct($reference)
    {
        parent::__construct(sprintf('Reference not found: "%s"', $reference));
    }
}
