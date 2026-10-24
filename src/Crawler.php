<?php
/**
 * Ideal CMS SiteSpider (https://idealcms.ru/)
 * @link      https://github.com/idealcms/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2020 Ideal CMS (https://idealcms.ru)
 * @license   https://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Spider;

use Ideal\Spider\Exception\MailTransportException;

/**
 * Обходит все страницы сайта, в рамках указанных настроек
 *
 * @package Ideal\Spider
 */
class Crawler
{
    /** @var array Массив проверенных ссылок */
    public array $checked = [];

    /** @var array Массив для данных из конфига */
    public array $config = [];

    /** @var string Переменная содержащая адрес главной страницы сайта */
    public string $host;

    /** @var array Массив НЕПРОВЕРЕННЫХ ссылок */
    private array $links = [];

    /** @var array Массив внешних ссылок */
    public array $external = [];

    /** @var float Время начала работы скрипта */
    private float $start;

    /** @var bool Флаг необходимости сброса ранее собранных страниц */
    private bool $clearTemp;

    /** @var string Статус запуска скрипта. Варианты cron|test */
    public string $status = 'cron';

    /** @var Url Класс для работы с html-страницей */
    protected Url $urlModel;

    /** @var Notify Класс для отправки уведомлений о работе карты сайта */
    protected Notify $notify;

    /** @var array Список предупреждений, формируемый при разборе страниц */
    protected array $warnings = [];

    protected array $handlers;

    /**
     * Инициализация счетчика времени работы скрипта, загрузка конфига,
     * определение хоста, проверка наличия карты сайта и загрузки временных
     * данных (при их наличии), запуск метода основного цикла скрипта.
     *
     * @param array $config Настройки сбора карты сайта
     *
     * @throws MailTransportException
     */
    public function __construct(array $config, $isForce = false, $isClear = false, $isTest = false)
    {
        // Время начала работы скрипта
        $this->start = microtime(1);

        // Проверяем статус запуска - тестовый (без писем) или по расписанию (по умолчанию - cron)
        $this->status = $isTest ? 'test' : 'cron';

        // Проверяем необходимость сброса ранее собранных страниц
        $this->clearTemp = $isClear;

        // Инициализируем модель для работы с html-страницей
        $this->urlModel = new Url();

        // Инициализируем объект для отправки сообщений
        $this->notify = new Notify(
            $config['mailer_dsn'],
            $config['email_from'],
            $config['email_notify'],
            $config['email_json'],
        );

        // Считываем настройки для создания карты сайта
        $this->loadConfig($config);
    }

    /**
     * Загрузка данных из конфига и из промежуточных файлов
     * @throws MailTransportException
     */
    public function loadData(): void
    {
        // Установка максимального времени на загрузку страницы
        $this->urlModel->setLoadTimeout($this->config['load_timeout']);

        // Установка максимального количества редиректов при считывании страницы
        $this->urlModel->setMaxRedirects((int) $this->config['redirects']);

        // Загружаем данные, собранные на предыдущих шагах работы скрипта
        $tmpFile = $this->getTmpFileName();

        // Если существует файл хранения временных данных сканирования,
        // Данные разбиваются на 3 массива: пройденных, не пройденных и внешних ссылок
        if (file_exists($tmpFile)) {
            $arr = file_get_contents($tmpFile);
            $arr = unserialize($arr, ['allowed_classes' => false]);
            $this->links = empty($arr[0]) ? [] : $arr[0];
            $this->checked = empty($arr[1]) ? [] : $arr[1];
            $this->external = empty($arr[2]) ? [] : $arr[2];
        }

        // Инициализируем все обработчики страницы
        $handlers = explode(',', $this->config['handlers']);
        foreach ($handlers as $handlerName) {
            $className = 'Ideal\\Spider\\Handler\\' . trim($handlerName);
            $handler = new $className($this);
            $handler->load();
            $this->handlers[$handlerName] = $handler;
        }

        // Уточняем время, доступное для обхода ссылок $this->config['script_timeout']
        $count = count($this->links) + count($this->checked);
        if ($count > 1000) {
            $this->config['recording'] = ($count / 1000) * 0.05 + $this->config['recording'];
        }
        $this->config['script_timeout'] -= $this->config['recording'];

        if ((count($this->links) === 0) && (count($this->checked) === 0)) {
            // Если это самое начало сканирования, добавляем в массив для сканирования первую ссылку
            $this->links[$this->config['website']] = 0;
            // Проверяем, указан ли файл для безусловного добавления ссылок в карту сайта
            if (!empty($this->config['add_urls_file'])) {
                // Указан файл для безусловного добавления адресов
                $fileName = $this->config['site_root'] . $this->config['add_urls_file'];
                if (file_exists($fileName)) {
                    $urls = [];
                    $file = explode("\n", file_get_contents($fileName));
                    foreach ($file as $line) {
                        $cols = explode("\t", trim($line));
                        $urls[] = trim($cols[0]);
                    }
                    $this->addLinks($urls, $fileName);
                } else {
                    $this->warnings[] = 'Отсутствует указанный файл со ссылками для добавления ' . $fileName;
                }
            }
        }
    }

    /**
     * Загрузка конфига в переменную $this->config
     *
     * @param $config
     *
     * @throws MailTransportException
     */
    protected function loadConfig($config): void
    {
        // Проверяем наличие конфигурации
        if (empty($config)) {
            // Конфигурационный файл нигде не нашли.
            $this->stop('Configuration not set!');
        }

        $this->config = $config;

        if (!isset($this->config['existence_time_file'])) {
            $this->config['existence_time_file'] = 25;
        }

        $tmp = parse_url($this->config['website']);

        if (!isset($tmp['path'])) {
            $tmp['path'] = '/';
        }
        $this->config['website'] = $tmp['scheme'] . '://' . $tmp['host'] . $tmp['path'];

        $this->host = $tmp['host'];

        // Вычисляем полный путь к корню сайта
        if (empty($this->config['site_root'])) {
            // Обнаружение корня сайта, если скрипт запускается из стандартной папки vendor
            if ($vendorPos = strpos(__DIR__, '/vendor')) {
                $this->config['site_root'] = substr(__DIR__, 0, $vendorPos);
            } else {
                $this->stop(
                    'Не могу определить корневую папку сайта, задайте её в настройках site_root',
                );
            }
        } else {
            $this->config['site_root'] = stream_resolve_include_path($this->config['site_root']);
        }

        // Массив значений по умолчанию
        $default = [
            'script_timeout' => 60,
            'load_timeout' => 10,
            'delay' => 1,
            'old_sitemap' => '/tmp/spider/sitemap-old.part',
            'tmp_file' => '/tmp/spider/spider.part',
            'tmp_radar_file' => '/tmp/spider/radar.part',
            'old_radar_file' => '/tmp/spider/radar-old.part',
            'tmp_imagemap_file' => '/tmp/spider/imagemap.part',
            'site_root' => '',
            'sitemap_file' => '/sitemap.xml',
            'imagemap_file' => '/imagemap.xml',
            'crawler_url' => '/',
            'change_freq' => 'weekly',
            'priority' => 0.8,
            'time_format' => 'long',
            'disallow_key' => '',
            'disallow_regexp' => '',
            'disallow_img_regexp' => '',
            'seo_urls' => '',
            'is_radar' => '1',
        ];

        foreach ($default as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }

        // Строим массивы для пропуска GET-параметров и URL по регулярным выражениям
        $this->config['disallow_key'] = explode("\n", $this->config['disallow_key']);
        $this->config['disallow_regexp'] = explode("\n", $this->config['disallow_regexp']);
        $this->config['disallow_img_regexp'] = explode("\n", $this->config['disallow_img_regexp']);

        // Строим массив страниц с изменённым приоритетом
        $this->config['seo_urls'] = explode("\n", $this->config['seo_urls']);
        $seo = [];
        foreach ($this->config['seo_urls'] as $k) {
            $a = explode('=', trim($k));
            $url = trim($a[0]);
            $priority = trim($a[1]);
            $seo[$url] = $priority;
        }
        $this->config['seo_urls'] = $seo;
        // Если среди ссылок с заданным приоритетом нет главной страницы, добавляем её туда,
        // но приоритет оставляем стандартным
        if (!isset($this->config['seo_urls'][$this->config['website']])) {
            $this->config['seo_urls'][$this->config['website']] = $this->config['priority'];
        }
    }

    /**
     * Инициализация и получение имени файла для сохранения промежуточных данных по ссылкам
     * @throws MailTransportException
     */
    protected function getTmpFileName(): string
    {
        $tmpFile = $this->config['site_root'] . $this->config['tmp_file'];
        $tmpRadarFile = $this->config['site_root'] . $this->config['tmp_radar_file'];

        $tmpDir = dirname($tmpFile);
        if (!file_exists($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
        }

        $tmpRadarDir = dirname($tmpRadarFile);
        if (!file_exists($tmpRadarDir) && !mkdir($tmpRadarDir, 0777, true) && !is_dir($tmpRadarDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $tmpRadarDir));
        }

        if (file_exists($tmpFile)) {
            if (!is_writable($tmpFile)) {
                $this->stop("Временный файл $tmpFile недоступен для записи!");
            }

            // Если промежуточный файл ссылок последний раз обновлялся более того количества часов назад,
            // которое указано в настройках, то производим его принудительную очистку.
            $existenceTimeFile = $this->config['existence_time_file'] * 60 * 60;
            if ($this->clearTemp || time() - filemtime($tmpFile) > $existenceTimeFile) {
                unlink($tmpFile);
                unlink($tmpRadarFile);
            }
        } elseif ((file_put_contents($tmpFile, '') === false)) {
            // Файла нет и создать его не удалось
            $this->stop("Не удалось создать временный файл $tmpFile для карты сайта!");
        } else {
            unlink($tmpFile);
        }

        return $tmpFile;
    }

    /**
     * Метод для сохранения распарсенных данных во временный файл
     */
    protected function saveParsedUrls(): void
    {
        $result = [$this->links, $this->checked, $this->external];

        $result = serialize($result);

        $tmp_file = $this->config['site_root'] . $this->config['tmp_file'];

        $fp = fopen($tmp_file, 'wb');

        fwrite($fp, $result);

        fclose($fp);
    }

    /**
     * Метод основного цикла для сборки карты сайта и парсинга товаров
     * @throws MailTransportException
     */
    public function run(): void
    {
        // Загружаем конфигурационные данные
        $this->loadData();

        // Список страниц, которые не удалось прочитать с первого раза
        $broken = [];

        /** Массив checked вида [ссылка] => пометка о том является ли ссылка корректной (1 - да, 0 - нет) */
        $number = count($this->checked) + 1;

        /** Массив links вида [ссылка] => пометка(не играет роли) */
        $time = microtime(1);
        while (count($this->links) > 0) {
            // Если текущее время минус время начала работы скрипта больше чем разница
            // заданного времени работы скрипта - завершаем работу скрипта
            if (($time - $this->start) > $this->config['script_timeout']) {
                break;
            }

            // Делаем паузу между чтением страниц
            usleep(($this->config['delay'] * 1000000));

            // Устанавливаем указатель на 1-й элемент
            reset($this->links);

            // Извлекаем ключ текущего элемента (то есть ссылку)
            $k = key($this->links);

            echo $number++ . '. ' . $k . "\n";

            // Получаем контент страницы
            try {
                $content = $this->urlModel->getUrl($k, $this->links[$k]);
            } catch (\LogicException $e) {
                // Если этот адрес редиректит на другую страницу
                $this->addLinks([$e->getMessage()], $this->links[$k]);
                unset($this->links[$k]);
                continue;
            } catch (\Exception $e) {
                // Если при разборе страницы произошла ошибка - сообщаем пользователю, но продолжаем сбор страниц
                $this->warnings[] = $e->getMessage();
                unset($this->links[$k]);
                continue;
            }

            // Парсим ссылки из контента
            $urls = $this->urlModel->parseLinks($content);

            if (count($urls) < 10) {
                // Если мало ссылок на странице, значит что-то пошло не так и её нужно перечитать повторно
                if (isset($broken[$k])) {
                    // Если и при повторном чтении не удалось получить нормальную страницу, то останавливаемся
                    $this->stop("Сбой при чтении страницы $k\nПолучен следующий контент:\n$content");
                }
                $value = $this->links[$k];
                unset($this->links[$k]);
                $this->links[$k] = $broken[$k] = $value;
            }

            // Добавляем ссылки в массив $this->links
            $this->addLinks($urls, $k);

            // Добавляем текущую ссылку в массив пройденных ссылок
            $this->checked[$k] = 1;

            // И удаляем из массива не пройденных
            unset($this->links[$k]);

            // Навешиваем свои классы-обработчики контента страницы (карта сайта, перелинковка, карта изображений)
            foreach ($this->handlers as $handler) {
                $handler->parse($k, $content);
            }

            $time = microtime(1);
        }

        if (count($this->links) > 0) {
            $this->saveParsedUrls();

            // Сохраняем промежуточные данные дополнительных обработчиков
            foreach ($this->handlers as $handler) {
                $handler->save();
            }

            $message = "\nВыход по таймауту\n"
                . 'Всего пройденных ссылок: ' . count($this->checked) . "\n"
                . 'Всего не пройденных ссылок: ' . count($this->links) . "\n"
                . 'Затраченное время: ' . ($time - $this->start) . "\n\n"
                . "Everything it's alright.\n\n";
            $this->stop($message, false);
        }

        if (count($this->checked) < 2) {
            $this->stop(
                "Попытка записи в sitemap вместо списка ссылок:\n" . print_r($this->checked, true),
            );
        }

        // Сохраняем финальные данные дополнительных обработчиков
        foreach ($this->handlers as $handler) {
            $handler->finish();
        }

        $time = microtime(1);

        echo "\nCrawler successfully finished\n"
            . 'Count of pages: ' . count($this->checked) . "\n"
            . 'Time: ' . ($time - $this->start);
    }

    /**
     * Обработка полученных ссылок, добавление в очередь новых ссылок
     *
     * @param array $urls Массив ссылок на обработку
     * @param string $current Текущая страница
     */
    private function addLinks(array $urls, string $current): void
    {
        foreach ($urls as $url) {
            try {
                if ($this->urlModel->isExternalLink($url, $current, $this->host)) {
                    $this->external[$url] = $current;
                    // Пропускаем ссылки на другие сайты
                    continue;
                }
            } catch (\Throwable $e) {
                // Произошла ошибка при определении внешняя ссылка или нет
                $this->warnings[] = $e->getMessage();
                continue;
            }

            // Абсолютизируем ссылку
            $link = $this->urlModel->getAbsoluteUrl($this->config['website'], $url, $current);

            // Убираем лишние GET параметры из ссылки
            $link = $this->urlModel->cutExcessGet($link, $this->config['disallow_key']);

            if ($this->urlModel->skipUrl($link, $this->config['disallow_regexp'])) {
                // Если ссылку не нужно добавлять, переходим к следующей
                continue;
            }

            if (isset($this->links[$link]) || isset($this->checked[$link])) {
                // Пропускаем уже добавленные ссылки
                continue;
            }

            $this->links[$link] = $current;
        }
    }

    /**
     * Отправка уведомлений, если были ошибки при разборе страниц
     * @throws MailTransportException
     */
    public function __destruct()
    {
        if (!empty($this->warnings)) {
            $this->notify->sendEmail(
                $this->host . ' sitemap error',
                implode("\n\n", $this->warnings),
            );
        }
    }

    /**
     * Настройки скрипта обхода сайта
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Объект для отправки уведомлений
     *
     * @return Notify
     */
    public function getNotify(): Notify
    {
        return $this->notify;
    }

    /**
     * Вывод сообщения и аварийное завершение работы скрипта
     *
     * @param string $message Сообщение для вывода
     * @param bool $sendNotification Флаг обозначающий необходимость отправления сообщения перед остановкой скрипта
     *
     * @throws MailTransportException
     * @throws \RuntimeException
     */
    public function stop(string $message, bool $sendNotification = true): void
    {
        if ($sendNotification) {
            $this->getNotify()->sendEmail(($this->host ?? '') . ' sitemap error', $message);
        }

        throw new \RuntimeException($message);
    }
}
