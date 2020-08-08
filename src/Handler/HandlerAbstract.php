<?php
/**
 * Ideal CMS SiteSpider (https://idealcms.ru/)
 * @link      https://github.com/idealcms/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2020 Ideal CMS (https://idealcms.ru)
 * @license   https://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Spider\Handler;


use Ideal\Spider\Crawler;

/**
 * Абстрактный класс для наследования кастомными обработчиками
 *
 * @package Ideal\Spider
 */
abstract class HandlerAbstract
{
    /** @var Crawler Объект со всеми настройками паука и собранными данными */
    protected $crawler;

    /**
     * Инициализаия обработчика
     *
     * @param Crawler $crawler
     */
    public function __construct($crawler)
    {
        $this->crawler = $crawler;
    }

    /**
     * Загрузка данных обработчика, сохранённых на предыдущем этапе работы скрипта
     *
     * @return void
     */
    abstract public function load();

    /**
     * Обработка полученного контента страницы
     *
     * @param string $url Адрес анализируемой страницы
     * @param string $content Контент анализируемой страницы
     * @return void
     */
    abstract public function parse($url, $content);

    /**
     * Сохранение промежуточных данных обработчика
     *
     * @return void
     */
    abstract public function save();

    /**
     * Действия, выполняемые при завершении обхода сайта
     *
     * @return void
     */
    abstract public function finish();

    /**
     * Проверка наличия временного файла или возможности его создать
     *
     * @param string $tmpFile Полный путь к временному файлу
     * @return bool
     */
    protected function checkTmpFile($tmpFile)
    {
        $notify = $this->crawler->getNotify();

        if (file_exists($tmpFile)) {
            if (!is_writable($tmpFile)) {
                $notify->stop("Временный файл {$tmpFile} недоступен для записи!");
                return false;
            }
            return true;
        }
        if ((file_put_contents($tmpFile, '') === false)) {
            // Файла нет и создать его не удалось
            $notify->stop("Не удалось создать временный файл {$tmpFile}!");
            return false;
        }
        return false;
    }
}
