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
class StringHelper
{
    private static $encoding = 'utf-8';

    /**
     * @return string
     */
    public static function getEncoding()
    {
        return self::$encoding;
    }

    /**
     * @param string $encoding
     */
    public static function setEncoding($encoding)
    {
        self::$encoding = $encoding;
    }

    /**
     * @param string $string
     *
     * @return int
     */
    public static function strlen($string)
    {
        return function_exists('mb_strlen') ? mb_strlen($string, self::$encoding) : strlen($string);
    }

    /**
     * @param string $string
     * @param int $start
     * @param int|false $length
     *
     * @return false|string
     */
    public static function substr($string, $start, $length = false)
    {
        if (false === $length) {
            $length = self::strlen($string);
        }

        return function_exists('mb_substr') ? mb_substr($string, $start, $length, self::$encoding) : substr($string, $start, $length);
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @param int $offset
     *
     * @return false|int
     */
    public static function strpos($haystack, $needle, $offset = 0)
    {
        return function_exists('mb_strpos') ? mb_strpos($haystack, $needle, $offset, self::$encoding) : strpos($haystack, $needle, $offset);
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @param int $offset
     *
     * @return false|int
     */
    public static function strrpos($haystack, $needle, $offset = 0)
    {
        return function_exists('mb_strrpos') ? mb_strrpos($haystack, $needle, $offset, self::$encoding) : strrpos($haystack, $needle, $offset);
    }
}
