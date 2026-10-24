<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in(['src', 'tests'])
;

return new PhpCsFixer\Config()
    ->setCacheFile('var/.phpcs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP85Migration' => true,
        '@PER-CS3x0' => true,
        'declare_strict_types' => true,
        'general_phpdoc_tag_rename' => ['replacements' => ['inheritDocs' => 'inheritDoc'], 'fix_inline' => true],
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'linebreak_after_opening_tag' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'allow_unused_params' => true],
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_no_useless_inheritdoc' => false,
        'single_space_around_construct' => true,
        'types_spaces' => ['space' => 'none'],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property',
                'property_static',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'magic',
                'method',
                'method_static',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
                'destruct',
                'phpunit',
            ],
        ],
    ])
    ->setFinder($finder)
;
