<?php

declare(strict_types=1);
/**
 * Ideal CMS SiteSpider (https://idealcms.ru/)
 * @link      https://github.com/idealcms/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2020 Ideal CMS (https://idealcms.ru)
 * @license   https://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Spider;

use Ideal\Spider\Exception\MailTransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Отправка уведомлений об ошибках и об успешном завершении обхода сайта
 *
 * @package Ideal\Spider
 */
class Notify
{
    private readonly Mailer $mailer;

    public function __construct(

        /** Настройки smtp-сервера для отправки сообщений */
        string $mailerDsn,

        /** Переменная содержащая адрес главной страницы сайта */
        private readonly string $from,

        /** @var string Адрес для отправки писем */
        private readonly string $to,

        /** @var null|string Адрес для отправки писем с json-данными */
        private readonly ?string $toJson = null,
    ) {
        $this->mailer = new Mailer(Transport::fromDsn($mailerDsn));
    }

    /**
     * Функция отправки сообщение с отчетом о создании карты сайта
     *
     * @param string $subject Тема письма
     * @param string $text Сообщение (отчет)
     *
     * @throws MailTransportException
     */
    public function sendEmail(string $subject, string $text): void
    {
        // Отправляем письма об изменениях
        $this->send($this->to, $subject, $text);
    }

    /**
     * Отправка json-сообщение с отчетом о создании карты сайта
     *
     * @param string $subject Тема письма
     * @param string $text Json-отчёт
     *
     * @throws MailTransportException
     */
    public function sendJsonEmail(string $subject, string $text): void
    {
        // Отправляем письма об изменениях
        $this->send($this->toJson, $subject, $text);
    }

    /**
     * @throws MailTransportException
     */
    private function send(string $to, string $subject, string $text): void
    {
        $email = new Email()
            ->from($this->from)
            ->to($to)
            ->subject($subject)
            ->text($text)
        ;

        try {
            $this->mailer->send($email);
        } catch (\Throwable $throwable) {
            throw new MailTransportException($throwable->getMessage(), $throwable->getCode(), $throwable);
        }
    }
}
