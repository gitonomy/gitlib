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

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;

use function Castor\guard_min_version;
use function Castor\import;
use function Castor\run;

guard_min_version('1.0.0');

import(__DIR__.'/tools/php-cs-fixer/castor.php');
import(__DIR__.'/tools/phpstan/castor.php');

#[AsTask(description: 'Install dependencies')]
function install(): void
{
    run(['composer', 'install']);
    qa\cs\install();
    qa\phpstan\install();
}

#[AsTask(description: 'Run PHPUnit', ignoreValidationErrors: true)]
function phpunit(#[AsRawTokens] array $rawTokens): void
{
    run(['vendor/bin/phpunit', ...$rawTokens]);
}
