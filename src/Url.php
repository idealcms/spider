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
 * Получение и анализ страницы на ссылки
 *
 * @package Ideal\Spider
 */
class Url
{
    /** @var string Ссылка из мета-тега base, если он есть на странице */
    private $base = '';

    /** @var array Массив параметров curl для получения заголовков и html кода страниц */
    private $options = array(
        CURLOPT_RETURNTRANSFER => true, //  возвращать строку, а не выводить в браузере
        CURLOPT_VERBOSE => false, // вывод дополнительной информации (?)
        CURLOPT_HEADER => true, // включать заголовки в вывод
        CURLOPT_ENCODING => "", // декодировать запрос используя все возможные кодировки
        CURLOPT_AUTOREFERER => true, // автоматическая установка поля referer в запросах, перенаправленных Location
        CURLOPT_CONNECTTIMEOUT => 4, // кол-во секунд ожидания при соединении (мб лучше CURLOPT_CONNECTTIMEOUT_MS)
        CURLOPT_TIMEOUT => 4, // максимальное время выполнения функций cURL функций
        CURLOPT_FOLLOWLOCATION => false, // не идти за редиректами
        CURLOPT_MAXREDIRS => 0, // максимальное число редиректов
        64 => false, // CURLOPT_SSL_VERIFYPEER не проверять ssl-сертификат
        81 => 0, // CURLOPT_SSL_VERIFYHOST не проверять ssl-сертификат
    );

    /**
     * Метод для получения html-кода страницы по адресу $k в основном цикле
     *
     * @param string $k Ссылка на страницу для получения её контента
     * @param string $place Страница, на которой получили ссылку (нужна только в случае ошибки)
     * @return string Html-код страницы
     * @throws RuntimeException
     */
    public function getUrl($k, $place)
    {
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

        // Проверяем, не является ли файл тем, в котором не нужно искать ссылки
        $ext = strtolower(pathinfo($k, PATHINFO_EXTENSION));
        if (in_array($ext, array('xls', 'xlsx', 'pdf', 'doc', 'docx'))) {
            return '';
        }

        // Инициализируем CURL для получения содержимого страницы

        $ch = curl_init($k);

        curl_setopt_array($ch, $this->options);

        $res = curl_exec($ch); // получаем html код страницы, включая заголовки

        $info = curl_getinfo($ch); // получаем информацию о запрошенной странице

        // Если произошла ошибка чтения страницы по вине сервера, откладываем станицу в конец списка
        if ($info['http_code'] === 0) {
            throw new \LogicException($info['url']);
        }

        // Если страница недоступна прекращаем выполнение скрипта
        if ($info['http_code'] !== 200) {
            throw new RuntimeException("Страница {$k} недоступна. Статус: {$info['http_code']}. Переход с {$place}");
        }

        // Если произошёл редирект, не превышающий заданное кол-во редиректов,
        // возвращаем адрес, на который идёт редирект
        if ($info['redirect_count'] > 0) {
            throw new \LogicException($info['url']);
        }

        // Если страница имеет слишком малый вес прекращаем выполнение скрипта
        if ($info['size_download'] < 1024) {
            throw new RuntimeException("Страница {$k} пуста. Размер страницы: {$info['size_download']} байт. Переход с {$place}");
        }

        // Если размер страницы больше 3 МБ, то не анализируем контент
        if ($info['size_download'] > 3145728) {
            return '';
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); // получаем размер header'а

        curl_close($ch);

        $res = mb_substr($res, $header_size); // вырезаем html код страницы

        return $res;
    }

    /**
     * Достраивание обрабатываемой ссылки до абсолютной
     *
     * @param string $website Адрес сайта
     * @param string $link Обрабатываемая ссылка
     * @param string $current Текущая страница с которой получена ссылка
     * @return string  Возвращается абсолютная ссылка
     */
    public function getAbsoluteUrl($website, $link, $current)
    {
        // Закодированные амперсанды возвращаем к стандартному виду
        $link = str_replace('&amp;', '&', $link);

        // Раскодируем ссылку, чтобы привести её к единому формату хранения в списке
        $link = urldecode($link);

        $len = mb_strlen($link);
        if (($len > 1) && (mb_substr($link, -1) === ' ')) {
            // Если последний символ — пробел, то сообщаем об ошибке
            throw new RuntimeException("На странице {$current} неправильная ссылка, оканчивающаяся на пробел: '{$link}'");
        }
        if ($len > 1 && preg_match('/^\s/', $link)) {
            // Если ссылка начинается с пробельного символа, то сообщаем об ошибке
            throw new RuntimeException("На странице {$current} неправильная ссылка, начинающаяся на пробел: '{$link}'");
        }

        // Если ссылка начинается с '//', то добавляем к ней протокол
        if (strpos($link, '//') === 0) {
            $link = parse_url($website, PHP_URL_SCHEME) . ':' . $link;
        }

        if (strpos($link, 'http') === 0) {
            // Если ссылка начинается с http, то абсолютизировать её не надо
            $url = parse_url($link);
            if (empty($url['path'])) {
                // Если ссылка на главную и в ней отсутствует последний слеш, добавляем его
                $link .= '/';
            }
            return $link;
        }

        // Разбираем текущую ссылку на компоненты
        $url = parse_url($current);

        // Если последний символ в "path" текущей это слэш "/"
        if (mb_substr($url['path'], -1) === '/') {
            // Промежуточная директория равна "path" текущей ссылки без слэша
            $dir = substr($url['path'], 0, -1);
        } else {
            // Устанавливаем родительский элемент
            $dir = dirname($url['path']);

            // Если в $dir - корень сайта, то он должен быть пустым
            $dir = (mb_strlen($dir) === 1) ? '' : $dir;

            // Если ссылка начинается с "?"
            if (strpos($link, '?') === 0) {
                // То обрабатываемая ссылка равна последней части текущей ссылки + сама ссылка
                $link = basename($url['path']) . $link;
            }
        }

        // Если ссылка начинается со слэша
        if (strpos($link, '/') === 0) {
            // Обрезаем слэш
            $link = substr($link, 1);
            // Убираем промежуточный родительский элемент
            $dir = '';
        }

        // Если ссылка начинается с "./"
        if (strpos($link, './') === 0) {
            $link = substr($link, 2);
        } else {
            // До тех пор пока ссылка начинается с "../"
            while (strpos($link, '../') === 0) {
                // Обрезаем "../"
                $link = substr($link, 3);
                // Устанавливаем родительскую директорию равную текущей, но обрезая её с последнего "/"
                $dir = mb_substr($dir, 0, mb_strrpos($dir, '/'));
            }
        }

        // Если задано base - добавляем его
        if ($this->base !== '') {
            // Если base начинается со слэша, то формирем полный адрес до корня сайта
            if (strpos($this->base, '/') === 0) {
                $this->base = $url['scheme'] . '://' . $url['host'] . $this->base;
            }
            // Если base не оканчивается на слэш, то добавляем слэш справа
            if (substr($this->base, -1) !== '/') {
                $this->base .= '/';
            }
            return $this->base . $link;
        }

        // Возвращаем абсолютную ссылку
        return $url['scheme'] . '://' . $url['host'] . $dir . '/' . $link;
    }

    /**
     * Создание ссылки из частей
     *
     * @param array $parsedUrl Массив полученный из функции parse_url
     * @return string Возвращается ссылка, собранная из элементов массива
     */
    public static function unparseUrl($parsedUrl)
    {
        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        $host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
        $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $user = isset($parsedUrl['user']) ? $parsedUrl['user'] : '';
        $pass = isset($parsedUrl['pass']) ? ':' . $parsedUrl['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    /**
     * Установка максимального времени на загрузку страницы
     *
     * @param $loadTimeout
     */
    public function setLoadTimeout($loadTimeout)
    {
        $this->options[CURLOPT_TIMEOUT] = $this->options[CURLOPT_CONNECTTIMEOUT] = $loadTimeout;
    }

    /**
     * Парсинг ссылок из обрабатываемой страницы
     *
     * @param string $content Обрабатываемая страницы
     * @return array Список полученных ссылок
     */
    public function parseLinks(&$content)
    {
        // Получение значения тега "base", если он есть
        preg_match('/<.*base[\s]+href=["\'](.*)["\'].*>/i', $content, $base);
        if (isset($base[1])) {
            $this->base = $base[1];
        }

        // Удаляем некорректные UTF-8 символы, которые могут быть из-за криворуких программистов
        $tmpContent = mb_convert_encoding($content, 'UTF-8', 'UTF-8');;

        // Удаление js-кода
        $tmpContent = (string)preg_replace("/<script(.*)<\/script>/iusU", '', $tmpContent);

        // Если контент был не в utf-8, то пытаемся конвертировать в нужную кодировку и повторяем замену
        if (!$tmpContent && preg_last_error() === PREG_BAD_UTF8_ERROR) {
            $content = iconv("cp1251", "UTF-8", $content);
            $content = preg_replace("/<script(.*)<\/script>/iusU", '', $content);
        } else {
            $content = $tmpContent;
        }

        return $this->getLinksFromText($content);
    }

    /**
     * Получение всех ссылок из html-кода
     *
     * @param string $text html-код для парсинга
     * @return array
     */
    public function getLinksFromText($text)
    {
        // Получаем содержимое всех тегов <a>
        preg_match_all('/<a (.*)>/isU', $text, $urls);

        if (empty($urls[1])) {
            return [];
        }

        // Выдёргиваем атрибуты
        foreach ($urls[1] as $url) {
            $url = ' ' . $url . ' ';
            preg_match_all('/(\w+)=[\'"]([^"\']+)/', $url, $attributes);
            $href = false;
            foreach ($attributes[1] as $key => $name) {
                if ($name === 'href') {
                    $href = $attributes[2][$key];
                    break;
                }
            }
            if ($href === false) {
                // Не удалось получить ссылку, возможно она не в кавычках
                $a = '/(\w+)(=[\'"])([^"\']*)([\'"])/';
                $url = preg_replace($a, '', $url);
                preg_match_all('/(\w+)=([^\S]+)/', $url, $attributes);
                foreach ($attributes[1] as $key => $name) {
                    if ($name === 'href') {
                        $href = $attributes[2][$key];
                        break;
                    }
                }
            }
            if (empty($href) || strpos($href, '#') === 0 || stripos($href, 'javascript:') === 0) {
                // Убираем пустые анкоры, а также анкоры без ссылок и js-код в ссылках
                continue;
            }
            $links[] = $href;
        }

        if (empty($links)) {
            $links = [];
        }

        return $links;
    }

    /**
     * Метод для удаления ненужных GET параметров и якорей из ссылки
     *
     * @param string $url Обрабатываемая ссылка
     * @param $disallowKeys
     * @return string Возвращается ссылка без лишних GET параметров и якорей
     */
    public function cutExcessGet($url, $disallowKeys)
    {
        $paramStart = strpos($url, '?');
        // Если существуют GET параметры у ссылки - проверяем их
        if ($paramStart !== false) {
            foreach ($disallowKeys as $id => $key) {
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

                    $url = self::unparseUrl($link);
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
     * @param $disallowRegexp
     * @return bool
     */
    public function skipUrl($filename, $disallowRegexp)
    {
        if (is_array($disallowRegexp) && count($disallowRegexp) > 0) {
            // Проходимся по массиву регулярных выражений. Если array_reduce вернёт саму ссылку,
            // то подходящего правила в disallow не нашлось и можно эту ссылку добавлять в карту сайта
            $reduce = array_reduce(
                $disallowRegexp,
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
     * Проверка является ли ссылка внешней
     *
     * @param string $link Проверяемая ссылка
     * @param string $current Текущая страница с которой получена ссылка
     * @param string $host Домен, на котором проводится проверка
     * @return boolean true если ссылка внешняя, иначе false
     */
    public function isExternalLink($link, $current, $host)
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

        if ($host === $url['host']) {
            // Хост сайта и хост ссылки совпадают, значит она локальная
            return false;
        }

        if (str_replace('www.', '', $host) === str_replace('www.', '', $url['host'])) {
            // Хост сайта и хост ссылки не совпали, но с урезанием www совпали, значит неправильная ссылка
            throw new RuntimeException("Неправильная абсолютная ссылка: {$link} на странице {$current}");
        }

        return true;
    }

    /**
     * Установка максимального количества редиректов при получении страницы
     *
     * @param int $redirects Максимальное кол-во редиректов
     */
    public function setMaxRedirects($redirects)
    {
        $redirects = (int)$redirects;
        $this->options[CURLOPT_FOLLOWLOCATION] = $redirects !== 0;
        $this->options[CURLOPT_MAXREDIRS] = $redirects;
    }
}