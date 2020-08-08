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
}
