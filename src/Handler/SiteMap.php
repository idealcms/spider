<?php
/**
 * Ideal CMS SiteSpider (https://idealcms.ru/)
 * @link      https://github.com/idealcms/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2020 Ideal CMS (https://idealcms.ru)
 * @license   https://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Spider\Handler;


use Exception;

/**
 * Построение xml-карты сайта на основе собранных основным скриптом страниц сайта
 *
 * @package Ideal\Spider
 */
class SiteMap extends HandlerAbstract
{
    /**
     * Проверка наличия, доступности для записи и актуальности xml-файла карты сайта
     *
     * @throws Exception
     */
    public function load()
    {
        $config = $this->crawler->getConfig();
        $notify = $this->crawler->getNotify();

        $xmlFile = $config['site_root'] . $config['sitemap_file'];

        // Проверяем существует ли файл и доступен ли он для чтения и записи
        if (file_exists($xmlFile)) {
            if (!is_readable($xmlFile)) {
                $notify->stop("File {$xmlFile} is not readable!");
            }
            if (!is_writable($xmlFile)) {
                $notify->stop("File {$xmlFile} is not writable!");
            }
        } else if ((file_put_contents($xmlFile, '') === false)) {
            // Файла нет и создать его не удалось
            $notify->stop("Couldn't create file {$xmlFile}!");
        } else {
            // Удаляем пустой файл, т.к. пустого файла не должно быть
            unlink($xmlFile);
            return;
        }

        // Проверяем, обновлялась ли сегодня карта сайта
        if (date('d:m:Y', filemtime($xmlFile)) === date('d:m:Y')) {
            if ($this->crawler->status === 'cron') {
                $notify->stop("Sitemap {$xmlFile} already created today! Everything it's alright.", false);
            } else {
                // Если дата сегодняшняя, но запуск не из крона, то продолжаем работу над картой сайта
                echo "Warning! File {$xmlFile} have current date and skip in cron";
            }
        } else {
            // Если карта сайта в два раза старше указанного значения в поле
            // "Максимальное время существования версии промежуточного файла"
            // и временный файл сбора ссылок обновлялся последний раз более 12 часов назад, то
            // отсылаем соответствующее уведомление
            $countHourForNotify = $config['existence_time_file'] * 2;
            $existenceTimeFile = $countHourForNotify * 60 * 60;
            $tmpFile = $config['site_root'] . $config['tmp_file'];
            if (file_exists($tmpFile)
                && time() - filemtime($xmlFile) > $existenceTimeFile
                && time() - filemtime($tmpFile) > 43200) {
                $msg = 'Карта сайта последний раз обновлялась более ' . $countHourForNotify . ' часов(а) назад.';
                $notify->sendEmail($msg);
            }
        }
    }

    public function parse($url, $content)
    {
        // Тут делать ничего не надо, всё делает основной скрипт
    }

    public function save()
    {
        // Тут делать ничего не надо, всё делает основной скрипт
    }

    /**
     * Создание xml файла с картой сайта
     */
    public function finish()
    {
        $config = $this->crawler->getConfig();

        $this->compare();

        $lastDate = date('Y-m-d\TH:i:s') . substr(date("O"), 0, 3) . ":" . substr(date("O"), 3);

        $ret = '';
        foreach ($this->crawler->checked as $k => $v) {
            $ret .= '<url>';
            $ret .= sprintf('<loc>%s</loc>', $this->xmlEscape($k));
            // Временно без даты последнего изменения
            /*
            if (isset($url['lastmod'])) {
                if (is_numeric($url['lastmod'])) {
                    $ret[] = sprintf(
                        '<lastmod>%s</lastmod>',
                        $url['lastmod_dateonly'] ?
                        date('Y-m-d', $url['lastmod']):
                        date('Y-m-d\TH:i:s', $url['lastmod']) .
                        substr(date("O", $url['lastmod']), 0, 3) . ":" .
                        substr(date("O", $url['lastmod']), 3)
                    );
                } elseif (is_string($url['lastmod'])) {
                    $ret[] = sprintf('<lastmod>%s</lastmod>', $url['lastmod']);
                }
            }
            */
            if (isset($config['change_freq'])) {
                $ret .= sprintf(
                    '<changefreq>%s</changefreq>',
                    $config['change_freq']
                );
            }
            if (isset($config['priority'])) {
                $priorityStr = sprintf('<priority>%s</priority>', '%01.1f');
                if (isset($config['seo_urls'][$k])) {
                    $priority = $config['seo_urls'][$k];
                } else {
                    $priority = $config['priority'];
                }
                $ret .= sprintf($priorityStr, $priority);
            }
            $ret .= '</url>';
        }

        $ret = /** @lang XML */
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9'
            . ' https://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">'
            . "<!-- Last update of sitemap {$lastDate} -->\n"
            . $ret
            . '</urlset>';

        $xmlFile = $config['site_root'] . $config['sitemap_file'];
        $fp = fopen($xmlFile, 'wb');
        fwrite($fp, $ret);
        fclose($fp);

        $tmp = $config['site_root'] . $config['tmp_file'];
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }

    /**
     * Преобразования специальных символов для xml файла карты сайта в HTML сущности
     *
     * @param string $str Ссылка для обработки
     * @return string Обработанная ссылка
     */
    public function xmlEscape($str)
    {
        $trans = [];
        if (!isset($trans)) {
            $trans = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES);
            foreach ($trans as $key => $value) {
                $trans[$key] = '&#' . ord($key) . ';';
            }
            // dont translate the '&' in case it is part of &xxx;
            $trans[chr(38)] = '&amp;'; // chr(38) = '&'
        }
        // Возвращается ссылка, в которой символы &,",<,>  заменены на HTML сущности
        return preg_replace("/&(?![A-Za-z]{0,4}\w{2,3};|#[\d]{2,4};)/", "&#38;", strtr($str, $trans));
    }

    /**
     * Поиск изменений в новой карте сайта и отчёте о перелинковке, относительно предыдущего результата
     */
    protected function compare()
    {
        $config = $this->crawler->getConfig();
        
        // Карта сайта
        $file = $config['site_root'] . $config['old_sitemap'];
        list($oldUrl, $oldExternal) = file_exists($file) ? unserialize(file_get_contents($file), ['allowed_classes' => false]) : [[], []];

        $new = $this->crawler->checked;
        $external = $this->crawler->external;

        // Сохраним новый массив ссылок, что бы в следующий раз взять его как старый
        file_put_contents($file, serialize(array($new, $external)));

        $text = '';
        $modifications = false;
        $add = $del = $addExternal = $delExternal = [];

        if (empty($oldUrl)) {
            $modifications = true;
            $text = "Добавлены ссылки (первичная генерация карты)\n";
            foreach ($new as $k => $v) {
                $text .= $k;
                $text .= "\n";
            }
        } else {
            // Находим добавленные страницы
            $add = array_diff_key($new, $oldUrl);
            if (!empty($add)) {
                $modifications = true;
                $text .= "Добавлены ссылки\n";
                foreach ($add as $k => $v) {
                    $text .= $k;
                    $text .= "\n";
                }
            } else {
                $text .= "Ничего не добавлено\n";
            }

            // Находим удаленные страницы
            $del = array_diff_key($oldUrl, $new);
            if (!empty($del)) {
                $modifications = true;
                $text .= "Удалены ссылки \n";
                foreach ($del as $k => $v) {
                    $text .= $k;
                    $text .= "\n";
                }
            } else {
                $text .= "Ничего не удалено\n";
            }
        }

        if (empty($oldExternal)) {
            $modifications = true;
            $text .= "\nДобавлены внешние ссылки(первичная генерация карты):\n";
            foreach ($external as $k => $v) {
                $text .= "{$k} на странице {$v}\n";
            }
        } else {
            // Определяем новые внешние ссылки
            $addExternal = array_diff_key($external, $oldExternal);
            if (!empty($addExternal)) {
                $modifications = true;
                $text .= "\nДобавлены внешние ссылки:\n";
                foreach ($addExternal as $k => $v) {
                    $text .= "{$k} на странице {$v}\n";
                }
            } else {
                $text .= "\nНет новых внешних ссылок\n";
            }

            $delExternal = array_diff_key($oldExternal, $external);
            if (!empty($delExternal)) {
                $modifications = true;
                $text .= "\nУдалены внешние ссылки:\n";
                foreach ($delExternal as $k => $v) {
                    $text .= "{$k} на странице {$v}\n";
                }
            } else {
                $text .= "\nНет удаленных внешних ссылок";
            }
        }

        $notify = $this->crawler->getNotify();
        $notify->sendEmail($text);
        if ($modifications && !empty($config['email_json'])) {
            // Формируем json формат данных для отправки на почту, хранящую информацию о работе карт сайта
            $log = array(
                'add' => array_keys($add),
                'del' => array_keys($del),
                'add_external' => $addExternal,
                'del_external' => $delExternal,
            );
            $log = json_encode($log);
            $notify->sendEmail($log, $config['email_json'], $this->crawler->host . ' sitemap result');
        }
    }
}
