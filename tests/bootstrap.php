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

require __DIR__.'/../vendor/autoload.php';

if (defined('PHP_WINDOWS_VERSION_BUILD')) {
    $server = array_change_key_case($_SERVER, true);
    $_SERVER['GIT_ENVS'] = [];
    foreach (['PATH', 'SYSTEMROOT'] as $key) {
        if (isset($server[$key])) {
            $_SERVER['GIT_ENVS'][$key] = $server[$key];
        }
    }
    unset($server);
}
