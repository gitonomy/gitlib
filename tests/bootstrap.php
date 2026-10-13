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

// git and libgit2 read the configuration of fixtures/home only, whatever the
// developer's: see fixtures/home/.gitconfig. Symfony Process passes on the
// variables of getenv() that $_SERVER has too, $_ENV taking precedence.
foreach ([
    'GIT_CONFIG' => null,
    'GIT_CONFIG_COUNT' => null,
    'GIT_CONFIG_GLOBAL' => null,
    'GIT_CONFIG_NOSYSTEM' => '1',
    'GIT_CONFIG_PARAMETERS' => null,
    'GIT_CONFIG_SYSTEM' => null,
    'HOME' => __DIR__.'/fixtures/home',
    'XDG_CONFIG_HOME' => null,
] as $name => $value) {
    if (null === $value) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    } else {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}

if (defined('PHP_WINDOWS_VERSION_BUILD')) {
    $server = array_change_key_case($_SERVER, \CASE_UPPER);
    $_SERVER['GIT_ENVS'] = [];
    foreach (['PATH', 'SYSTEMROOT'] as $key) {
        if (isset($server[$key])) {
            $_SERVER['GIT_ENVS'][$key] = $server[$key];
        }
    }
    unset($server);
}
