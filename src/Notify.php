<?php
/**
 * Ideal CMS SiteSpider (https://idealcms.ru/)
 * @link      https://github.com/idealcms/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2020 Ideal CMS (https://idealcms.ru)
 * @license   https://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Spider;


use RuntimeException;

/**
 * Отправка уведомлений об ошибках и об успешном завершении обхода сайта
 *
 * @package Ideal\Spider
 */
class Notify
{
    /** @var string Переменная содержащая адрес главной страницы сайта */
    private $host;

    /** @var string Адрес для отправки писем по умолчанию */
    private $emailNotify;

    public function setData($host, $emailNotify)
    {
        $this->host = $host;
        $this->emailNotify = $emailNotify;
    }

    /**
     * Функция отправки сообщение с отчетом о создании карты сайта
     *
     * @param string $text Сообщение (отчет)
     * @param string $to Email того, кому отправить письмо
     * @param string $subject Тема письма
     */
    public function sendEmail($text, $to = '', $subject = '')
    {
        $header = "MIME-Version: 1.0\r\n"
            . "Content-type: text/plain; charset=utf-8\r\n"
            . 'From: sitemap@' . $this->host;

        $to = (empty($to)) ? $this->emailNotify : $to;
        $subject = (empty($subject)) ? $this->host . ' sitemap' : $subject;

        // Отправляем письма об изменениях
        // print 'to: ' . $to . "\nsubj: " . $subject . "\ntext: " . $text . "\n";
        mail($to, $subject, $text, $header);
    }

    /**
     * Вывод сообщения и завершение работы скрипта
     *
     * @param string $message Сообщение для вывода
     * @param bool $sendNotification Флаг обозначающий необходимость отправления сообщения перед остановкой скрипта
     *
     * @throws RuntimeException
     */
    public function stop($message, $sendNotification = true)
    {
        if ($sendNotification) {
            $this->sendEmail($message, '', $this->host . ' sitemap error');
        }
        throw new RuntimeException($message);
    }
}