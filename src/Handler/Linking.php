<?php
/**
 * Ideal CMS SiteSpider (https://idealcms.ru/)
 * @link      https://github.com/idealcms/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2020 Ideal CMS (https://idealcms.ru)
 * @license   https://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Spider\Handler;


use Ideal\Spider\Url;

/**
 * Подсчёт перелинковки страниц сайта (сколько страниц из контента ссылаются на каждую страницу)
 *
 * Работает только если в настройках включена опция is_radar
 * Выцепляет из страницы текст, ограниченный тегами <!--start_content_off--> и <!--end_content_off-->
 * ищет в нём ссылки и подсчитывает перелинковку.
 *
 * @package Ideal\Spider
 */
class Linking extends HandlerAbstract
{
    /** @var array Массив ссылок из области отслеживаемой радаром с подсчётом количества */
    private $radarLinks = [];

    /**
     * Проверка доступности временных файлов и времени последнего сохранения промежуточного файла ссылок
     */
    public function load()
    {
        $config = $this->crawler->getConfig();

        if (empty($config['is_radar'])) {
            return;
        }

        $tmpRadarFile = $config['site_root'] . $config['tmp_radar_file'];

        // Если существует файл хранения временных данных отчёта о перелинковке
        if ($this->checkTmpFile($tmpRadarFile)) {
            $arr = file_get_contents($tmpRadarFile);
            $this->radarLinks = unserialize($arr, ['allowed_classes' => false]);
        }
    }

    public function parse($url, $content)
    {
        $config = $this->crawler->getConfig();

        if (!$config['is_radar']) {
            return;
        }

        // Получаем список ссылок из области отмеченной радаром
        $radarLinks = $this->parseRadarLinks($content);

        // Добавляем ссылки из радарной области в массив $this->radarLinks
        $this->addRadarLinks($radarLinks, $url);
    }

    public function save()
    {
        $config = $this->crawler->getConfig();

        if ($config['is_radar'] && is_array($this->radarLinks) && count($this->radarLinks) > 0) {
            // Сохранение данных для отчёта о перелинковке во временный файл
            $result = serialize($this->radarLinks);
            $tmp_radar_file = $config['site_root'] . $config['tmp_radar_file'];
            $fp = fopen($tmp_radar_file, 'wb');
            fwrite($fp, $result);
            fclose($fp);
        }
    }

    /**
     * Обработка полученных ссылок, добавление в массив отчёта о перелинковке
     *
     * @param array $radarLinks Массив ссылок на обработку
     * @param string $current Текущая страница
     */
    private function addRadarLinks($radarLinks, $current)
    {
        $config = $this->crawler->getConfig();
        $urlModel = new Url();

        foreach ($radarLinks as $radarLink => $count) {
            // Убираем анкоры без ссылок и js-код в ссылках
            if (strpos($radarLink, '#') === 0 || stripos($radarLink, 'javascript:') === 0) {
                continue;
            }

            if ($urlModel->isExternalLink($radarLink, $current, $this->crawler->host)) {
                // Пропускаем ссылки на другие сайты
                continue;
            }

            // Абсолютизируем ссылку
            $link = $urlModel->getAbsoluteUrl($config['website'], $radarLink, $current);

            // Убираем лишние GET параметры из ссылки
            $link = $urlModel->cutExcessGet($link, $config['disallow_key']);

            if ($urlModel->skipUrl($link, $config['disallow_regexp'])) {
                // Если ссылку не нужно добавлять, переходим к следующей
                continue;
            }

            if (!isset($this->radarLinks[$link])) {
                $this->radarLinks[$link] = $count;
            } else {
                $this->radarLinks[$link] += $count;
            }
        }
    }

    /**
     * Парсинг ссылок из области радара
     *
     * @param string $content Обрабатываемая страницы
     * @return array Список полученных ссылок с количеством упоминания их в области радара
     */
    public function parseRadarLinks($content)
    {
        $urlModel = new Url();
        $radarLinks = [];
        // Удаляем области контента не попадающие в радар
        $content = preg_replace("/<!--start_content_off-->(.*)<!--end_content_off-->/iusU", '', $content);

        // Получаем области контента попадающие в радар
        preg_match_all("/<!--start_content-->(.*)<!--end_content-->/iusU", $content, $radarContent);
        if ($radarContent && isset($radarContent[1]) && is_array($radarContent[1]) && !empty($radarContent[1])) {
            foreach ($radarContent[1] as $radarContentPart) {
                $radarLinks[] = $urlModel->getLinksFromText($radarContentPart);
            }
            $radarLinks = array_merge(...$radarLinks);
        }
        $radarLinks = array_count_values($radarLinks);
        return $radarLinks;
    }

    /**
     * Поиск изменений в новой карте сайта и отчёте о перелинковке, относительно предыдущего результата
     */
    public function finish()
    {
        $config = $this->crawler->getConfig();
        $notify = $this->crawler->getNotify();

        if (empty($config['is_radar'])) {
            return;
        }

        // Отправляем отчёт о перелинковке
        $radarFile = $config['site_root'] . $config['old_radar_file'];
        $oldRadar = file_exists($radarFile) ? unserialize(file_get_contents($radarFile), ['allowed_classes' => false]) : '';

        // Сохраним новый массив ссылок для отчёта о перелинковке, что бы в следующий раз взять его как старый
        file_put_contents($radarFile, serialize($this->radarLinks));

        if (!$this->radarLinks) {
            $notify->sendEmail(
                'Отчёт о перелинковке не может быть составлен, возможно не установлен радар.',
                '',
                $this->crawler->host . ' - перелинковка'
            );
            return;
        }

        $modifications = false;
        $diffText = '';
        arsort($this->radarLinks);
        // Если отчёт о перелинковке уже составлялся, то ищем разницу с текущим состоянием
        if (empty($oldRadar)) {
            $modifications = true;
        } else {
            arsort($oldRadar);
            $newRadar = $this->radarLinks;
            // Проверяем, были ли добавлены новые ссылки в радарную область
            $add = array_diff_key($newRadar, $oldRadar);
            if (!empty($add)) {
                $modifications = true;
                $diffText .= "Новые ссылки в области радара\n";
                foreach ($add as $k => $v) {
                    unset($newRadar[$k]);
                    $diffText .= "{$k} - {$v}\n";
                }
            }
            // Проверяем, были ли удалены ссылки из радарной области
            $del = array_diff_key($oldRadar, $newRadar);
            if (!empty($del)) {
                $modifications = true;
                if (!empty($diffText)) {
                    $diffText .= "\n";
                }
                $diffText .= "Удалённые ссылки из области радара\n";
                foreach ($del as $k => $v) {
                    unset($oldRadar[$k]);
                    $diffText .= "{$k}\n";
                }
            }
            // Проверяем, было ли изменено количество входящих ссылок из радарной области
            $diff = array_diff_assoc($newRadar, $oldRadar);
            if (!empty($diff)) {
                $modifications = true;
                if (!empty($diffText)) {
                    $diffText .= "\n";
                }
                $diffText .= "Изменено количество входящих ссылок на следующие страницы\n";
                foreach ($diff as $k => $v) {
                    $diffText .= "{$k} - было ({$oldRadar[$k]}) стало ($newRadar[$k])\n";
                }
            }
        }
        if ($modifications) {
            $radarLinksReport = '';
            $radarLinksSeoReport = '';
            foreach ($this->radarLinks as $key => $value) {
                if (isset($this->config['seo_urls'][$key])) {
                    $seoLinkString = "{$key} - {$value} (приоритет - {$config['seo_urls'][$key]})\n";
                    $radarLinksSeoReport .= $seoLinkString;
                }
                $radarLinksReport .= "{$key} - {$value}\n";
            }
            if ($radarLinksSeoReport) {
                $radarLinksReport = "Ссылки с заданным приоритетом:\n{$radarLinksSeoReport}\nВсе ссылки:\n"
                    . $radarLinksReport;
            }
            if ($diffText) {
                $radarLinksReport = "{$diffText}\n{$radarLinksReport}";
            }
            $notify->sendEmail($radarLinksReport, '', '{{host}} - перелинковка');
        }
        unlink($config['site_root'] . $config['tmp_radar_file']);
    }
}
