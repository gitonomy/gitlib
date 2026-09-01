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
 * Helper class to support language particularity.
 *
 * @author Alexandre Salomé <alexandre.salome@gmail.com>
 */
final class StringHelper
{
    private static string $encoding = 'utf-8';

    public static function getEncoding(): string
    {
        return self::$encoding;
    }

    public static function setEncoding(string $encoding): void
    {
        self::$encoding = $encoding;
    }

    public static function strlen(string $string): int
    {
        return function_exists('mb_strlen') ? mb_strlen($string, self::$encoding) : strlen($string);
    }

    public static function substr(string $string, int $start, ?int $length = null): string
    {
        if (null === $length) {
            $length = self::strlen($string);
        }

        return function_exists('mb_substr') ? mb_substr($string, $start, $length, self::$encoding) : substr($string, $start, $length);
    }

    public static function strpos(string $haystack, string $needle, int $offset = 0): int|false
    {
        return function_exists('mb_strpos') ? mb_strpos($haystack, $needle, $offset, self::$encoding) : strpos($haystack, $needle, $offset);
    }

    public static function strrpos(string $haystack, string $needle, int $offset = 0): int|false
    {
        return function_exists('mb_strrpos') ? mb_strrpos($haystack, $needle, $offset, self::$encoding) : strrpos($haystack, $needle, $offset);
    }
}
