<?php
require __DIR__.'/../vendor/autoload.php';

if (defined('PHP_WINDOWS_VERSION_BUILD')) {
    $server = array_change_key_case($_SERVER);
    $_SERVER['GIT_ENVS'] = array();
    foreach (array('PATH', 'SYSTEMROOT') as $key) {
        if (isset($server[strtolower($key)])) {
            $_SERVER['GIT_ENVS'][strtoupper($key)] = $server[strtolower($key)];
        }
    }
    unset($server);
}
