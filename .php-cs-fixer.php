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

$fileHeaderComment = <<<'EOF'
    This file is part of Gitonomy.

    (c) Alexandre Salomé <alexandre.salome@gmail.com>
    (c) Julien DIDIER <genzo.wm@gmail.com>

    This source file is subject to the MIT license that is bundled
    with this source code in the file LICENSE.
    EOF;

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->append([
        __FILE__,
        'castor.php',
    ])
    ->notPath('var')
;

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PHP84Migration' => true,
        '@PhpCsFixer' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'php_unit_internal_class' => false, // From @PhpCsFixer but we don't want it
        'php_unit_test_class_requires_covers' => false, // From @PhpCsFixer but we don't want it
        'phpdoc_add_missing_param_annotation' => false, // From @PhpCsFixer but we don't want it
        'header_comment' => ['header' => $fileHeaderComment],
        'ordered_class_elements' => true, // Symfony(PSR12) override the default value, but we don't want
        'blank_line_before_statement' => true, // Symfony(PSR12) override the default value, but we don't want
    ])
    ->setFinder($finder)
;
