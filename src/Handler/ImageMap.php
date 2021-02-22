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
 * Создание карты изображений
 *
 * @package Ideal\Spider
 */
class ImageMap extends HandlerAbstract
{
    /** @var array Список картинок на каждой странице сайта */
    protected $images = [];

    /** @inheritDoc */
    public function load()
    {
        $config = $this->crawler->getConfig();

        $tmpFile = $config['site_root'] . $config['tmp_imagemap_file'];

        // Если существует файл хранения временных данных отчёта о перелинковке
        if ($this->checkTmpFile($tmpFile)) {
            $arr = file_get_contents($tmpFile);
            $this->images = unserialize($arr, ['allowed_classes' => false]);
        }
    }

    /** @inheritDoc */
    public function parse($url, $content)
    {
        $config = $this->crawler->getConfig();
        $urlModel = new Url();

        // Выцепляем из контента все теги img
        preg_match_all('/<img[^>]+>/i', $content, $result);
        if (empty($result[0])) {
            return;
        }

        $this->images[$url] = [];
        foreach ($result[0] as $imgHtml) {
            // Выцепляем из тега img его атрибуты
            preg_match_all('/(alt|title|src)=("[^"]*")/i', $imgHtml, $resultImg);
            if (empty($resultImg[1])) {
                continue; // атрибутов нет - переходим к следующей картинке
            }
            // Строим массив с атрибутами картинки
            $img = [];
            foreach ($resultImg[1] as $k => $attr) {
                $img[$attr] = trim($resultImg[2][$k], '"\'');
            }
            // Проверяем, не является ли картинка data:image
            if (mb_strpos($img['src'], 'data:image') === 0) {
                continue;
            }
            // Проверяем, находится ли картинка на сайте
            if ($urlModel->isExternalLink($img['src'], $url, $this->crawler->host)) {
                continue; // внешние картинки в карту не добавляем
            }
            $img['src'] = $urlModel->getAbsoluteUrl($config['website'], $img['src'], $url);
            // Проверяем, не нужно ли исключить картинку
            if ($urlModel->skipUrl($img['src'], $config['disallow_img_regexp'])) {
                continue;
            }
            $this->images[$url][] = $img;
        }
        if (empty($this->images[$url])) {
            unset($this->images[$url]);
        }
    }

    /** @inheritDoc */
    public function save()
    {
        $config = $this->crawler->getConfig();

        if (is_array($this->images) && count($this->images) > 0) {
            // Сохранение данных о собранных картинках во временный файл
            $result = serialize($this->images);
            $tmpFile = $config['site_root'] . $config['tmp_imagemap_file'];
            file_put_contents($tmpFile, $result);
        }
    }

    /** @inheritDoc */
    public function finish()
    {
        $xml = '';
        foreach ($this->images as $url => $images) {
            $xml .= '<url><loc>' . $url .'</loc>' . "\n";
            foreach ($images as $image) {
                $xml .= "<image:image>\n" . '<image:loc>' . $image['src'] . "</image:loc>\n";
                if (!empty($image['alt'])) {
                    $xml .= '<image:title>' . $image['alt'] . "</image:title>\n";
                }
                if (!empty($image['title']) && (empty($image['alt']) || $image['alt'] !== $image['title'])) {
                    $xml .= '<image:caption>' . $image['title'] . "</image:caption>\n";
                }
                $xml .= "</image:image>\n";
            }
            $xml .= "</url>\n";
        }
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $xml = /** @lang XML */
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n"
            . $xml . '</urlset>';

        $config = $this->crawler->getConfig();
        file_put_contents($config['site_root'] . $config['imagemap_file'], $xml);

        $tmp = $config['site_root'] . $config['tmp_imagemap_file'];
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
}
