<?php
/**
 * Запускаемый скрипт модуля создания карты сайта
 *
 * Возможны несколько вариантов запуска скрипта
 *
 * 1. Из крона (с буферизацией вывода):
 * /bin/php /var/www/example.com/examples/index.php
 *
 * 2. Из командной строки из папки скрипта (без буферизации вывода):
 * /bin/php index.php
 *
 * 3. Из браузера:
 * http://example.com/index.php
 *
 * 4. Принудительное создание карты сайта, даже если сегодня она и создавалась
 * /bin/php index.php w
 *
 * 5. Принудительное создание карты сайта из браузера, даже если сегодня она и создавалась
 * http://example.com/index.php?w=1
 *
 */
require_once __DIR__ . '/../vendor/autoload.php';

$params = include 'spider.php';

$crawler = new Ideal\Spider\Crawler($params);

if ($crawler->ob) {
    ob_start();
}

echo "<pre>\n";

$message = '';
try {
    $crawler->run();
} catch (Exception $e) {
    $message = $e->getMessage();
}

echo $message;

if ($crawler->ob) {
    // Если было кэширование вывода, получаем вывод и отображаем его
    $text = ob_get_clean();
    echo $text;
    // Если нужно, отправляем письмо с выводом скрипта
    $crawler->sendCron($text);
}
