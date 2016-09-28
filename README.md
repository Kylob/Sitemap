# use BootPress\Sitemap\Component as Sitemap;

[![Packagist][badge-version]][link-packagist]
[![License MIT][badge-license]](LICENSE.md)
[![HHVM Tested][badge-hhvm]][link-travis]
[![PHP 7 Supported][badge-php]][link-travis]
[![Build Status][badge-travis]][link-travis]
[![Code Climate][badge-code-climate]][link-code-climate]
[![Test Coverage][badge-coverage]][link-coverage]

The BootPress Sitemap Component generates the sitemap[...].xml files by saving all of your website's links, and keeping track of any updates.  Any 404 pages are removed as they come to our attention (via ``$page->send(404)``).  It also has convenience methods for adding or removing multiple pages at once, and  searching the entire site or any parts thereof.  The only heads up is to watch what you ``Sitemap::add()`` to the database.  Listing or product pages that have constantly changing or updated content are not good candidates for the sitemap.  It will screw up your searches, and you only want Google to index real content pages anyways.

## Installation

Add the following to your ``composer.json`` file.

``` bash
{
    "require": {
        "bootpress/sitemap": "^1.0"
    }
}
```

## Example Usage

``` php
<?php

use BootPress\Page\Component as Page;
use BootPress\Sitemap\Component as Sitemap;

$page = Page::html();

if ($xml = Sitemap::page()) {
    $page->send($xml);
}

$html = '<p>Content</p>';

Sitemap::add('pages', $html);

$page->send($page->display($html));
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[badge-version]: https://img.shields.io/packagist/v/bootpress/sitemap.svg?style=flat-square&label=Packagist
[badge-license]: https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square
[badge-hhvm]: https://img.shields.io/badge/HHVM-Tested-8892bf.svg?style=flat-square
[badge-php]: https://img.shields.io/badge/PHP%207-Supported-8892bf.svg?style=flat-square
[badge-travis]: https://img.shields.io/travis/Kylob/Sitemap/master.svg?style=flat-square
[badge-code-climate]: https://img.shields.io/codeclimate/github/Kylob/Sitemap.svg?style=flat-square
[badge-coverage]: https://img.shields.io/codeclimate/coverage/github/Kylob/Sitemap.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/bootpress/sitemap
[link-travis]: https://travis-ci.org/Kylob/Sitemap
[link-code-climate]: https://codeclimate.com/github/Kylob/Sitemap
[link-coverage]: https://codeclimate.com/github/Kylob/Sitemap/coverage
