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

// HOME is in the working tree: gpg, which git runs to check a signature, would
// create its ".gnupg" directory there.
$gnupgHome = tempnam(sys_get_temp_dir(), 'gitlib_gnupg_') ?: throw new RuntimeException('Unable to create a temporary file.');
unlink($gnupgHome);
mkdir($gnupgHome, 0o700);
register_shutdown_function(static function () use ($gnupgHome): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($gnupgHome, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($gnupgHome);
});

foreach ([
    ...array_fill_keys($gitVariables, null),
    'GIT_CONFIG_NOSYSTEM' => '1',
    'GNUPGHOME' => $gnupgHome,
    'HOME' => __DIR__.'/fixtures/home',
    // The tests read the messages of git, which the language of the developer would translate.
    'LANGUAGE' => 'C',
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
