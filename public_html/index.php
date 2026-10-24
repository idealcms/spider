<?php
/**
 * Запускаемый скрипт модуля создания карты сайта
 *
 * Возможны несколько вариантов запуска скрипта
 *
 * 1. Из командной строки:
 * /bin/php /var/www/example.com/examples/index.php
 *
 * 2. Из браузера:
 * https://example.com/index.php
 *
 * 3. Принудительное создание карты сайта, даже если сегодня она и создавалась
 * /bin/php index.php w
 *
 * 4. Принудительное создание карты сайта из браузера, даже если сегодня она и создавалась
 * https://example.com/index.php?w=1
 *
 */
require_once __DIR__ . '/../vendor/autoload.php';

$params = include 'config.php';

$params['site_root'] = __DIR__ . '/..';

$crawler = new Ideal\Spider\Crawler($params);

echo "<pre>\n";

try {
    $crawler->run();
} catch (Exception $e) {
    echo 'Ошибка: ' . $e->getMessage();
}
