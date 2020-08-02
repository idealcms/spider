<?php


namespace Ideal\Sitemap;


use RuntimeException;

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

        // Если страница недоступна прекращаем выполнение скрипта
        if ($info['http_code'] !== 200) {
            throw new RuntimeException("Страница {$k} недоступна. Статус: {$info['http_code']}. Переход с {$place}");
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

        $res = substr($res, $header_size); // вырезаем html код страницы

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

        // Удаление js-кода
        $tmpContent = (string)preg_replace("/<script(.*)<\/script>/iusU", '', $content);

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
    protected function getLinksFromText($text)
    {
        // Получаем содержимое всех тегов <a>
        preg_match_all('/<a (.*)>/isU', $text, $urls);

        if (empty($urls[1])) {
            return array();
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
            $links = array();
        }

        return $links;
    }

    /**
     * Парсинг ссылок из области радара
     *
     * @param string $content Обрабатываемая страницы
     * @return array Список полученных ссылок с количеством упоминания их в области радара
     */
    public function parseRadarLinks($content)
    {
        $radarLinks = [];
        // Удаляем области контента не попадающие в радар
        $content = preg_replace("/<!--start_content_off-->(.*)<!--end_content_off-->/iusU", '', $content);

        // Получаем области контента попадающие в радар
        preg_match_all("/<!--start_content-->(.*)<!--end_content-->/iusU", $content, $radarContent);
        if ($radarContent && isset($radarContent[1]) && is_array($radarContent[1]) && !empty($radarContent[1])) {
            foreach ($radarContent[1] as $radarContentPart) {
                $radarLinks[] = $this->getLinksFromText($radarContentPart);
            }
            $radarLinks = array_merge(...$radarLinks);
        }
        $radarLinks = array_count_values($radarLinks);
        return $radarLinks;
    }
}