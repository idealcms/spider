<?php
// Подключаем все классы из папок модуля для использования без Composer
spiderRequireClasses(__DIR__);

/**
 * Рекурсивная функция обхода файлового дерева
 *
 * @param string $dir Начальная папка для подключения классов
 */
function spiderRequireClasses($dir)
{
    $arr = array_diff(scandir($dir), ['.', '..']);

    foreach ($arr as $v) {
        $file = $dir . '/' . $v;

        if (is_dir($file)) {
            // Если это папка, то входим в неё
            spiderRequireClasses($file);
            continue;
        }

        if (strrpos($v, '.php') !== strlen($v) - 4) {
            // Файлы с расширением, отличным от .php не включаем
            continue;
        }

        /** @noinspection PhpIncludeInspection */
        require_once $file;
    }
}
