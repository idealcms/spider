<?php
/**
 * Ideal CMS SiteSpider (https://idealcms.ru/)
 * @link      https://github.com/idealcms/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2020 Ideal CMS (https://idealcms.ru)
 * @license   https://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Spider\Handler;

use Ideal\Spider\Crawler;
use Ideal\Spider\Exception\MailTransportException;

/**
 * Абстрактный класс для наследования кастомными обработчиками
 *
 * @package Ideal\Spider
 */
abstract class HandlerAbstract
{
    /** @var Crawler Объект со всеми настройками паука и собранными данными */
    protected Crawler $crawler;

    private array $trans = [];

    /**
     * Инициализация обработчика
     */
    public function __construct(Crawler $crawler)
    {
        $this->crawler = $crawler;
    }

    /**
     * Загрузка данных обработчика, сохранённых на предыдущем этапе работы скрипта
     */
    abstract public function load(): void;

    /**
     * Обработка полученного контента страницы
     *
     * @param string $url Адрес анализируемой страницы
     * @param string $content Контент анализируемой страницы
     */
    abstract public function parse(string $url, string $content): void;

    /**
     * Сохранение промежуточных данных обработчика
     */
    abstract public function save(): void;

    /**
     * Действия, выполняемые при завершении обхода сайта
     */
    abstract public function finish(): void;

    /**
     * Проверка наличия временного файла или возможности его создать
     *
     * @param string $tmpFile Полный путь к временному файлу
     *
     * @throws MailTransportException
     */
    protected function checkTmpFile(string $tmpFile): bool
    {
        if (file_exists($tmpFile)) {
            if (!is_writable($tmpFile)) {
                $this->crawler->stop("Временный файл $tmpFile недоступен для записи!");
            }
            return true;
        }
        if ((file_put_contents($tmpFile, '') === false)) {
            // Файла нет и создать его не удалось
            $this->crawler->stop("Не удалось создать временный файл $tmpFile!");
        }

        return false;
    }

    /**
     * Преобразования специальных символов для xml файла карты сайта в HTML сущности
     *
     * @param string $str Ссылка для обработки
     *
     * @return string Обработанная ссылка
     */
    public function xmlEscape(string $str): string
    {
        // Возвращается ссылка, в которой символы &, ", < и > заменены на HTML сущности
        return preg_replace(
            "/&(?![A-Za-z]{0,4}\w{2,3};|#\d{2,4};)/",
            "&#38;",
            strtr($str, $this->getTrans())
        );
    }

    protected function getTrans(): array
    {
        if ($this->trans === []) {
            $this->trans = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES);
            foreach ($this->trans as $key => $value) {
                $this->trans[$key] = '&#' . ord($key) . ';';
            }
            // don't translate the '&' in case it is part of &xxx;
            $this->trans[chr(38)] = '&amp;'; // chr(38) = '&'
        }

        return $this->trans;
    }
}
