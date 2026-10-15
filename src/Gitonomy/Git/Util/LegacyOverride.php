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

namespace Gitonomy\Git\Util;

/**
 * Tells whether a subclass overrides a method that gitlib 1.x called itself,
 * deprecated in 1.7: gitlib keeps calling the override, like before.
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @internal To be removed in 2.0, with the deprecated methods
 */
final class LegacyOverride
{
    /**
     * @param object|class-string $objectOrClass The instance, or the class of a static call
     * @param class-string        $class         The class of gitlib declaring $method
     */
    public static function isOverridden(object|string $objectOrClass, string $class, string $method): bool
    {
        return $class !== (new \ReflectionMethod($objectOrClass, $method))->getDeclaringClass()->getName();
    }
}
