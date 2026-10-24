<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Include_\AbsolutizeRequireAndIncludePathRector;
use Rector\CodingStyle\Rector\String_\SimplifyQuoteEscapeRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php73\Rector\String_\SensitiveHereNowDocRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpLevel(PhpVersion::PHP_85)
    ->withPreparedSets(
        true,
        true,
        true,
        true,
        true,
        true
    )
    ->withImportNames(
        true,
        true,
        false,
    )
    ->withSkip([
        /// Конфигурационные файлы пока живут по своим правилам, чтобы их было легче парсить,
        /// поэтому не преобразовываем строки в классы и не конвертируем кавычки.
        StringClassNameToClassConstantRector::class => ['config.php'],
        SimplifyQuoteEscapeRector::class => ['config.php'],
        // Ректор странно конвертирует heredoc, что PhpStorm перестаёт его понимать.
        SensitiveHereNowDocRector::class,
        AbsolutizeRequireAndIncludePathRector::class => ['_.php'],
        SafeDeclareStrictTypesRector::class,
    ])
;
