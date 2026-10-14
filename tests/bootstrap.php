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
// developer's: see fixtures/home/.gitconfig. No other variable of git reaches
// them either: run from a hook, the tests would get GIT_DIR or GIT_INDEX_FILE,
// and write the repository of the hook. GIT_COMMAND is the git binary of the
// tests. Symfony Process passes on the variables of getenv() that $_SERVER has
// too, $_ENV taking precedence.
$gitVariables = array_filter(array_keys([...getenv(), ...$_ENV]), static fn (string $name): bool => str_starts_with($name, 'GIT_') && 'GIT_COMMAND' !== $name);
foreach ([
    ...array_fill_keys($gitVariables, null),
    'GIT_CONFIG_NOSYSTEM' => '1',
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
