<?php

namespace Ideal\Sitemap;

use Exception;

class Crawler
{
    /** @var array Массив проверенных ссылок */
    private $checked = array();

    /** @var array Массив для данных из конфига */
    public $config = array();

    /** @var string Переменная содержащая адрес главной страницы сайта */
    private $host;

    /** @var  array Массив НЕпроверенных ссылок */
    private $links = array();

    /** @var array Массив внешних ссылок */
    private $external = array();

    /** @var array Массив ссылок из области отслеживаемой радаром с подсчётом количества */
    private $radarLinks = array();

    /** @var bool Флаг необходимости кэширования echo/print */
    public $ob = false;

    /** @var float Время начала работы скрипта */
    private $start;

    /** @var bool Флаг необходимости сброса ранее собранных страниц */
    private $clearTemp = false;

    /** @var string Статус запуска скрипта. Варианты cron|test */
    public $status = 'cron';

    /** @var Url Класс для работы с html-страницей */
    protected $urlModel;

    /** @var Notify Класс для работы с html-страницей */
    protected $notify;

    /** @var array Список предупреждений, формируемый при разборе страниц */
    protected $warnings = [];

    /**
     * Инициализация счетчика времени работы скрипта, вызов метода загрузки конфига,
     * определение хоста, вызов методов проверки существования карты сайта и загрузки временных
     * данных (при их наличии), запуск метода основного цикла скрипта.
     * @param array $config Настройки сбора карты сайта
     * @throws Exception
     */
    public function __construct($config)
    {
        // Время начала работы скрипта
        $this->start = microtime(1);

        // Буферизация вывода нужна только для отправки сообщений при запуске через cron
        // При тестовых запусках вручную, скрипт обычно запускают из той же папки, где он лежит
        // При запусках через cron, скрипт никогда не запускается из той же папки
        $this->ob = !file_exists(basename($_SERVER['PHP_SELF']));

        // Проверяем статус запуска - тестовый или по расписанию
        $argv = !empty($_SERVER['argv']) ? $_SERVER['argv'] : array();
        if (isset($_GET['w']) || (in_array('w', $argv, true))) {
            // Если задан GET-параметр или ключ w в командной строке — это принудительный запуск,
            // письма о нём слать не надо
            $this->status = 'test';
            $this->ob = false;
        }
        // Проверяем надобность сброса ранее собранных страниц
        if (isset($_GET['с']) || (in_array('с', $argv, true))) {
            $this->clearTemp = true;
        }
        // Инициализируем модель для работы с html-страницей
        $this->urlModel = new Url();
        // Считываем настройки для создания карты сайта
        $this->loadConfig($config);
    }

    /**
     * Загрузка данных из конфига и из промежуточных файлов
     * @throws Exception
     */
    public function loadData()
    {
        // Установка максимального времени на загрузку страницы
        $this->urlModel->setLoadTimeout($this->config['load_timeout']);

        // Проверка существования файла sitemap.xml и его даты
        $this->prepareSiteMapFile();

        // Загружаем данные, собранные на предыдущих шагах работы скрипта
        $this->loadParsedUrls();

        // Проверка доступности и времени последнего сохранения промежуточного файла ссылок
        $this->loadRadarData();

        // Уточняем время, доступное для обхода ссылок $this->config['script_timeout']
        $count = count($this->links) + count($this->checked);
        if ($count > 1000) {
            $this->config['recording'] = ($count / 1000) * 0.05 + $this->config['recording'];
        }
        $this->config['script_timeout'] -= $this->config['recording'];

        if ((count($this->links) === 0) && (count($this->checked) === 0)) {
            // Если это самое начало сканирования, добавляем в массив для сканирования первую ссылку
            $this->links[$this->config['website']] = 0;
        }
    }

    /**
     * Загрузка конфига в переменную $this->config
     * @param $config
     * @throws Exception
     */
    protected function loadConfig($config)
    {
        $this->notify = new Notify();

        // Проверяем наличие конфигурации
        if (empty($config)) {
            // Конфигурационный файл нигде не нашли :(
            $this->notify->stop('Configuration not set!');
        }

        $this->config = $config;

        if (!isset($this->config['existence_time_file'])) {
            $this->config['existence_time_file'] = 25;
        }

        $tmp = parse_url($this->config['website']);

        $this->host = $tmp['host'];
        $this->notify->setData($tmp['host'], $this->config['email_notify']);

        if (!isset($tmp['path'])) {
            $tmp['path'] = '/';
        }
        $this->config['website'] = $tmp['scheme'] . '://' . $tmp['host'] . $tmp['path'];

        if (empty($this->config['site_root'])) {
            if (empty($_SERVER['DOCUMENT_ROOT'])) {
                // Обнаружение корня сайта, если скрипт запускается из стандартного места в Ideal CMS
                $self = $_SERVER['PHP_SELF'];
                $path = substr($self, 0, strpos($self, 'Ideal') - 1);
                $this->config['site_root'] = dirname($path);
            } else {
                $this->config['site_root'] = $_SERVER['DOCUMENT_ROOT'];
            }
        }

        // Массив значений по умолчанию
        $default = array(
            'script_timeout' => 60,
            'load_timeout' => 10,
            'delay' => 1,
            'old_sitemap' => '/images/map-old.part',
            'tmp_file' => '/images/map.part',
            'tmp_radar_file' => '/tmp/radar.part',
            'old_radar_file' => '/tmp/radar-old.part',
            'site_root' => '',
            'sitemap_file' => '/sitemap.xml',
            'crawler_url' => '/',
            'change_freq' => 'weekly',
            'priority' => 0.8,
            'time_format' => 'long',
            'disallow_key' => '',
            'disallow_regexp' => '',
            'seo_urls' => '',
            'is_radar' => '1',
        );
        foreach ($default as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }

        // Строим массивы для пропуска GET-параметров и URL по регулярным выражениям
        $this->config['disallow_key'] = explode("\n", $this->config['disallow_key']);
        $this->config['disallow_regexp'] = explode("\n", $this->config['disallow_regexp']);

        // Строим массив страниц с изменённым приоритетом
        $this->config['seo_urls'] = explode("\n", $this->config['seo_urls']);
        $seo = array();
        foreach ($this->config['seo_urls'] as $v => $k) {
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
     * Проверка доступности временных файлов и времени последнего сохранения промежуточного файла ссылок
     * @throws Exception
     */
    protected function loadRadarData()
    {
        if (empty($this->config['is_radar'])) {
            return;
        }

        $tmpRadarFile = $this->config['site_root'] . $this->config['tmp_radar_file'];

        if (file_exists($tmpRadarFile)) {
            if (!is_writable($tmpRadarFile)) {
                $this->notify->stop("Временный файл {$tmpRadarFile} недоступен для записи!");
            }
        } elseif ((file_put_contents($tmpRadarFile, '') === false)) {
            // Файла нет и создать его не удалось
            $this->notify->stop("Не удалось создать временный файл {$tmpRadarFile}!");
        } else {
            unlink($tmpRadarFile);
        }

        // Если существует файл хранения временных данных отчёта о перелинковке
        if (file_exists($tmpRadarFile)) {
            $arr = file_get_contents($tmpRadarFile);
            $this->radarLinks = unserialize($arr, ['allowed_classes' => false]);
        }
    }

    /**
     * Проверка наличия, доступности для записи и актуальности xml-файла карты сайта
     * @throws Exception
     */
    protected function prepareSiteMapFile()
    {
        $xmlFile = $this->config['site_root'] . $this->config['sitemap_file'];

        // Проверяем существует ли файл и доступен ли он для чтения и записи
        if (file_exists($xmlFile)) {
            if (!is_readable($xmlFile)) {
                $this->notify->stop("File {$xmlFile} is not readable!");
            }
            if (!is_writable($xmlFile)) {
                $this->notify->stop("File {$xmlFile} is not writable!");
            }
        } else if ((file_put_contents($xmlFile, '') === false)) {
            // Файла нет и создать его не удалось
            $this->notify->stop("Couldn't create file {$xmlFile}!");
        } else {
            // Удаляем пустой файл, т.к. пустого файла не должно быть
            unlink($xmlFile);
            return;
        }

        // Проверяем, обновлялась ли сегодня карта сайта
        if (date('d:m:Y', filemtime($xmlFile)) === date('d:m:Y')) {
            if ($this->status === 'cron') {
                $this->notify->stop("Sitemap {$xmlFile} already created today! Everything it's alright.", false);
            } else {
                // Если дата сегодняшняя, но запуск не из крона, то продолжаем работу над картой сайта
                echo "Warning! File {$xmlFile} have current date and skip in cron";
            }
        } else {
            // Если карта сайта в два раза старше указанного значения в поле
            // "Максимальное время существования версии промежуточного файла"
            // и временный файл сбора ссылок обновлялся последний раз более 12 часов назад, то
            // отсылаем соответствующее уведомление
            $countHourForNotify = $this->config['existence_time_file'] * 2;
            $existenceTimeFile = $countHourForNotify * 60 * 60;
            $tmpFile = $this->config['site_root'] . $this->config['tmp_file'];
            if (file_exists($tmpFile)
                && time() - filemtime($xmlFile) > $existenceTimeFile
                && time() - filemtime($tmpFile) > 43200) {
                $msg = 'Карта сайта последний раз обновлялась более ' . $countHourForNotify . ' часов(а) назад.';
                $this->notify->sendEmail($msg);
            }
        }
    }

    /**
     * Метод для загрузки распарсенных данных из временных файлов
     * @throws Exception
     */
    protected function loadParsedUrls()
    {
        $tmpFile = $this->config['site_root'] . $this->config['tmp_file'];
        $tmpRadarFile = $this->config['site_root'] . $this->config['tmp_radar_file'];

        if (file_exists($tmpFile)) {
            if (!is_writable($tmpFile)) {
                $this->notify->stop("Временный файл {$tmpFile} недоступен для записи!");
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
            $this->notify->stop("Не удалось создать временный файл {$tmpFile} для карты сайта!");
        } else {
            unlink($tmpFile);
        }

        // Если существует файл хранения временных данных сканирования,
        // Данные разбиваются на 2 массива: пройденных и непройденных ссылок
        if (file_exists($tmpFile)) {
            $arr = file_get_contents($tmpFile);
            $arr = unserialize($arr, ['allowed_classes' => false]);

            $this->links = empty($arr[0]) ? array() : $arr[0];
            $this->checked = empty($arr[1]) ? array() : $arr[1];
            $this->external = empty($arr[2]) ? array() : $arr[2];
        }
    }

    /**
     * Метод для сохранения распарсенных данных во временный файл
     */
    protected function saveParsedUrls()
    {
        $result = array(
            $this->links,
            $this->checked,
            $this->external
        );

        $result = serialize($result);

        $tmp_file = $this->config['site_root'] . $this->config['tmp_file'];

        $fp = fopen($tmp_file, 'wb');

        fwrite($fp, $result);

        fclose($fp);
    }

    /**
     * Метод сохраняющий данные для отчёта о перелинковке во временный файл
     */
    protected function saveParsedRadarLinks()
    {
        $result = serialize($this->radarLinks);
        $tmp_radar_file = $this->config['site_root'] . $this->config['tmp_radar_file'];
        $fp = fopen($tmp_radar_file, 'wb');
        fwrite($fp, $result);
        fclose($fp);
    }

    /**
     * Метод основного цикла для сборки карты сайта и парсинга товаров
     * @throws Exception
     */
    public function run()
    {
        // Загружаем конфигурационные данные
        $this->loadData();

        // Список страниц, которые не удалось прочитать с первого раза
        $broken = array();

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

            /**
             * // handle lastmod
             * $res['lastmod'] = $lastmod;
             *
             * // format timestamp appropriate to settings
             * if ($res['lastmod'] != '') {
             * if ($this->config['time_format'] == 'short') {
             * $res['lastmod'] = $this->getDateTimeISO_short($res['lastmod']);
             * } else {
             * $res['lastmod'] = $this->getDateTimeISO($res['lastmod']);
             * }
             * }
             */

            // todo переделываем в единый метод получения данных по url
            // Получаем код ответа - если не 200 - сообщаем менеджеру и переходим к другой странице
            // Если мало ссылок - сообщаем менеджеру и переходим к другой странице
            // todo возможность навешивать свои классы-обработчики контента страницы (перелинковка, карта изображений)

            // Получаем контент страницы
            try {
                $content = $this->urlModel->getUrl($k, $this->links[$k]);
            } catch (Exception $e) {
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
                    $this->notify->stop("Сбой при чтении страницы {$k}\nПолучен следующий контент:\n{$content}");
                }
                $value = $this->links[$k];
                unset($this->links[$k]);
                $this->links[$k] = $broken[$k] = $value;
            }

            // Добавляем ссылки в массив $this->links
            $this->addLinks($urls, $k);

            if ($this->config['is_radar']) {
                // Получаем список ссылок из области отмеченной радаром
                $radarLinks = $this->urlModel->parseRadarLinks($content);

                // Добавляем ссылки из радарной области в массив $this->radarLinks
                $this->addRadarLinks($radarLinks, $k);
            }

            // Добавляем текущую ссылку в массив пройденных ссылок
            $this->checked[$k] = 1;

            // И удаляем из массива непройденных
            unset($this->links[$k]);

            $time = microtime(1);
        }

        if ($this->config['is_radar'] && is_array($this->radarLinks) && count($this->radarLinks) > 0) {
            $this->saveParsedRadarLinks();
        }

        if (count($this->links) > 0) {
            $this->saveParsedUrls();
            $message = "\nВыход по таймауту\n"
                . 'Всего пройденных ссылок: ' . count($this->checked) . "\n"
                . 'Всего непройденных ссылок: ' . count($this->links) . "\n"
                . 'Затраченное время: ' . ($time - $this->start) . "\n\n"
                . "Everything it's alright.\n\n";
            $this->notify->stop($message, false);
        }

        if (count($this->checked) < 2) {
            $this->notify->sendEmail("Попытка записи в sitemap вместо списка ссылок:\n" . print_r($this->checked, true));
            $this->notify->stop('В sitemap доступна только одна ссылка на запись');
        }

        $this->compare();

        $xmlFile = $this->saveSiteMap();

        $time = microtime(1);

        echo "\nSitemap successfuly created and saved to {$xmlFile}\n"
            . 'Count of pages: ' . count($this->checked) . "\n"
            . 'Time: ' . ($time - $this->start);
    }

    /**
     * Преобразования специальных символов для xml файла карты сайта в HTML сущности
     *
     * @param string $str Ссылка для обработки
     * @return string Обработанная ссылка
     */
    public function xmlEscape($str)
    {
        $trans = array();
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
        // Карта сайта
        $file = $this->config['site_root'] . $this->config['old_sitemap'];
        list($oldUrl, $oldExternal) = file_exists($file) ? unserialize(file_get_contents($file), ['allowed_classes' => false]) : [[], []];

        $new = $this->checked;
        $external = $this->external;

        // Сохраним новый массив ссылок, что бы в следующий раз взять его как старый
        file_put_contents($file, serialize(array($new, $external)));

        $text = '';
        $modifications = false;
        $add = array();
        $del = array();
        $addExternal = array();
        $delExternal = array();

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

        $this->notify->sendEmail($text);
        if ($modifications && !empty($this->config['email_json'])) {
            // Формируем json формат данных для отправки на почту, хранящую информацию о работе карт сайта
            $log = array(
                'add' => array_keys($add),
                'del' => array_keys($del),
                'add_external' => $addExternal,
                'del_external' => $delExternal,
            );
            $log = json_encode($log);
            $this->notify->sendEmail($log, $this->config['email_json'], $this->host . ' sitemap result');
        }

        // Отправляем отчёт о перелинковке
        if ($this->config['is_radar']) {
            $radarFile = $this->config['site_root'] . $this->config['old_radar_file'];
            $oldRadar = file_exists($radarFile) ? unserialize(file_get_contents($radarFile), ['allowed_classes' => false]) : '';

            // Сохраним новый массив ссылок для отчёта о перелинковке, что бы в следующий раз взять его как старый
            file_put_contents($radarFile, serialize($this->radarLinks));

            if (!$this->radarLinks) {
                $this->notify->sendEmail(
                    'Отчёт о перелинковке не может быть составлен, возможно не установлен радар.',
                    '',
                    $this->host . ' - перелинковка'
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
                        $seoLinkString = "{$key} - {$value} (приоритет - {$this->config['seo_urls'][$key]})\n";
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
                $this->notify->sendEmail($radarLinksReport, '', '{{host}} - перелинковка');
            }
            unlink($this->config['site_root'] . $this->config['tmp_radar_file']);
        }
    }

    /**
     * Метод создания xml файла с картой сайта
     *
     * @return string Имя файла, в который сохраняется карта сайта
     */
    protected function saveSiteMap()
    {
        $lastDate = date('Y-m-d\TH:i:s') . substr(date("O"), 0, 3) . ":" . substr(date("O"), 3);

        $ret = '';
        foreach ($this->checked as $k => $v) {
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
            if (isset($this->config['change_freq'])) {
                $ret .= sprintf(
                    '<changefreq>%s</changefreq>',
                    $this->config['change_freq']
                );
            }
            if (isset($this->config['priority'])) {
                $priorityStr = sprintf('<priority>%s</priority>', '%01.1f');
                if (isset($this->config['seo_urls'][$k])) {
                    $priority = $this->config['seo_urls'][$k];
                } else {
                    $priority = $this->config['priority'];
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

        $xmlFile = $this->config['site_root'] . $this->config['sitemap_file'];
        $fp = fopen($xmlFile, 'wb');
        fwrite($fp, $ret);
        fclose($fp);

        $tmp = $this->config['site_root'] . $this->config['tmp_file'];
        if (file_exists($tmp)) {
            unlink($tmp);
        }

        return $xmlFile;
    }

    /**
     * Обработка полученных ссылок, добавление в очередь новых ссылок
     *
     * @param array $urls Массив ссылок на обработку
     * @param string $current Текущая страница
     * @throws Exception
     */
    private function addLinks($urls, $current)
    {
        foreach ($urls as $url) {
            if ($this->isExternalLink($url, $current)) {
                $this->external[$url] = $current;
                // Пропускаем ссылки на другие сайты
                continue;
            }

            // Абсолютизируем ссылку
            $link = $this->urlModel->getAbsoluteUrl($this->config['website'], $url, $current);

            // Убираем лишние GET параметры из ссылки
            $link = $this->cutExcessGet($link);

            if ($this->skipUrl($link)) {
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
     * Обработка полученных ссылок, добавление в массив отчёта о перелинковке
     *
     * @param array $radarLinks Массив ссылок на обработку
     * @param string $current Текущая страница
     * @throws Exception
     */
    private function addRadarLinks($radarLinks, $current)
    {
        foreach ($radarLinks as $radarLink => $count) {
            // Убираем анкоры без ссылок и js-код в ссылках
            if (strpos($radarLink, '#') === 0 || stripos($radarLink, 'javascript:') === 0) {
                continue;
            }

            if ($this->isExternalLink($radarLink, $current)) {
                // Пропускаем ссылки на другие сайты
                continue;
            }

            // Абсолютизируем ссылку
            $link = $this->urlModel->getAbsoluteUrl($this->config['website'], $radarLink, $current);

            // Убираем лишние GET параметры из ссылки
            $link = $this->cutExcessGet($link);

            if ($this->skipUrl($link)) {
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
     * Проверка является ли ссылка внешней
     *
     * @param string $link Проверяемая ссылка
     * @param string $current Текущая страница с которой получена ссылка
     * @return boolean true если ссылка внешняя, иначе false
     * @throws Exception
     */
    protected function isExternalLink($link, $current)
    {
        // Если ссылка на приложение - пропускаем её
        if (preg_match(',^(ftp://|mailto:|news:|javascript:|telnet:|callto:|tel:|skype:),i', $link)) {
            return true;
        }

        if (strpos($link, 'http') !== 0 && strpos($link, '//') !== 0) {
            // Если ссылка не начинается с http или '//', то она точно не внешняя, все варианты мы исключили
            return false;
        }

        $url = parse_url($link);

        // До 5.4.7 в path выводится весь адрес
        if (!isset($url['host'])) {
            list(, , $url['host']) = explode('/', $url);
        }

        if ($this->host === $url['host']) {
            // Хост сайта и хост ссылки совпадают, значит она локальная
            return false;
        }

        if (str_replace('www.', '', $this->host) === str_replace('www.', '', $url['host'])) {
            // Хост сайта и хост ссылки не совпали, но с урезанием www совпали, значит неправильная ссылка
            $this->notify->stop("Неправильная абсолютная ссылка: {$link} на странице {$current}");
        }

        return true;
    }

    /**
     * Метод для удаления ненужных GET параметров и якорей из ссылки
     *
     * @param string $url Обрабатываемая ссылка
     * @return string Возвращается ссылка без лишних GET параметров и якорей
     */
    protected function cutExcessGet($url)
    {
        $paramStart = strpos($url, '?');
        // Если существуют GET параметры у ссылки - проверяем их
        if ($paramStart !== false) {
            foreach ($this->config['disallow_key'] as $id => $key) {
                if (empty($key)) {
                    continue;
                }
                // Разбиваем ссылку на части
                $link = parse_url($url);

                if (isset($link['query'])) {
                    // Разбиваем параметры
                    parse_str($link['query'], $parts);

                    foreach ($parts as $k => $v) {
                        // Если параметр есть в исключениях - удаляем его из массива
                        if ($k === $key) {
                            unset($parts[$k]);
                        }
                    }
                    // Собираем оставшиеся параметры в строку
                    $query = http_build_query($parts);
                    // Заменяем GET параметры оставшимися
                    $link['query'] = $query;

                    $url = Url::unparseUrl($link);
                }
            }
        }
        // Если в сслыке есть '#' якорь, то обрезаем его
        if (strpos($url, '#') !== false) {
            $url = substr($url, 0, strpos($url, '#'));
        }
        // Если последний символ в ссылке '&' - обрезаем его
        while (substr($url, strlen($url) - 1) === "&") {
            $url = rtrim($url, '&');
        }
        // Если последний символ в ссылке '?' - обрезаем его
        while (substr($url, strlen($url) - 1) === "?") {
            $url = rtrim($url, '?');
        }
        return $url;
    }

    /**
     * Проверяем, нужно исключать этот URL или не надо
     * @param $filename
     * @return bool
     */
    protected function skipUrl($filename)
    {
        // Отрезаем доменную часть
        $filename = substr($filename, strpos($filename, '/') + 1);

        if (is_array($this->config['disallow_regexp']) && count($this->config['disallow_regexp']) > 0) {
            // Проходимся по массиву регулярных выражений. Если array_reduce вернёт саму ссылку,
            // то подходящего правила в disallow не нашлось и можно эту ссылку добавлять в карту сайта
            $tmp = $this->config['disallow_regexp'];
            $reduce = array_reduce(
                $tmp,
                static function ($res, $rule) {
                    if ($res === 1 || preg_match($rule, $res)) {
                        return 1;
                    }
                    return $res;
                },
                $filename
            );
            if ($filename !== $reduce) {
                // Сработало одно из регулярных выражений, значит ссылку нужно исключить
                return true;
            }
        }

        // Ни одно из правил не сработало, значит страницу исключать не надо
        return false;
    }

    /**
     * Отправка уведомлений, если были ошибки при разборе страниц
     */
    public function __destruct()
    {
        if (!empty($this->warnings)) {
            $this->notify->sendEmail(
                implode("\n\n", $this->warnings),
                '',
                $this->host . ' sitemap error'
            );
        }
    }
}
