<?php

declare(strict_types=1);

use Ideal\Spider\Url;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    public function testCaseAbsoluteString(): void
    {
        $url = new Url();

        $website = 'https://example.com/';

        $result = $url->getAbsoluteUrl($website, '?page=2', 'https://example.com/test.html');
        $this->assertEquals('https://example.com/test.html?page=2', $result);

        $result = $url->getAbsoluteUrl($website, 'simple.html', 'https://example.com/test.html');
        $this->assertEquals('https://example.com/simple.html', $result);

        $result = $url->getAbsoluteUrl($website, '/simple.html', 'https://example.com/lvl/test.html');
        $this->assertEquals('https://example.com/simple.html', $result);

        $result = $url->getAbsoluteUrl($website, 'simple.html', 'https://example.com/lvl/test.html');
        $this->assertEquals('https://example.com/lvl/simple.html', $result);

        $result = $url->getAbsoluteUrl($website, '/about/news/?year=2009&amp;PAGEN_1=2', 'https://example.com/lvl/test.html');
        $this->assertEquals('https://example.com/about/news/?year=2009&PAGEN_1=2', $result);

        //$result = $crawler->mock('getUrl', array("'https://example/test.txt'", "'http://example.com/lvl/test.html'"));
        //$this->assertEquals('this is', $result);
    }

    public function testCutExcessGet(): void
    {
        $url = new Url();

        $result = $url->cutExcessGet(
            'https://example.com/about/news/?q=test&PAGEN_1=2&page=10',
            ['page', 'test', 'news'],
        );

        $this->assertEquals('https://example.com/about/news/?q=test&PAGEN_1=2', $result);
    }

    public function testParseLinks(): void
    {
        $url = new Url();

        $content = '<a href="/about/news/?year=2009&amp;PAGEN_1=2">Конец</a>';

        $result = $url->parseLinks($content);

        $this->assertEquals('/about/news/?year=2009&amp;PAGEN_1=2', $result[0] ?? null);
    }

//    protected function setUp(): void
//    {
//        parent::setUp();
//    }
}
