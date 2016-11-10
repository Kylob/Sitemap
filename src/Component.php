<?php

namespace BootPress\Sitemap;

use BootPress\Page\Component as Page;
use BootPress\Asset\Component as Asset;
use BootPress\SQLite\Component as SQLite;
use BootPress\Hierarchy\Component as Hierarchy;
use Symfony\Component\HttpFoundation\Response;

class Component
{
    /** @var object The ``$page->file('Sitemap.db')``'s \BootPress\SQLite\Component instance. */
    public $db;

    /** @var int[] Category id's that we keep track of to conserve on unnecessary queries. */
    private $ids;

    /** @var mixed[] Prepared statements that need to be closed out. */
    private $stmt = array();

    /** @var bool Whether or not we're in the middle of a transaction. */
    private $transaction = false;

    /**
     * Opens the Sitemap database.
     *
     * @example
     *
     * ```php
     * $sitemap = new Sitemap();
     * ```
     */
    public function __construct()
    {
        $page = Page::html();
        $this->db = new SQLite($page->file('Sitemap.db'));
        if ($this->db->created) {
            $this->db->fts->create('search', array( // snippet order of preference
                'description',
                'content',
                'title',
                'path',
                'keywords',
            ), 'porter');
            $this->db->create('sitemap', array(
                'docid' => 'INTEGER PRIMARY KEY',
                'category_id' => 'INTEGER NOT NULL DEFAULT 0',
                'path' => 'TEXT UNIQUE COLLATE NOCASE',
                'info' => 'TEXT NOT NULL DEFAULT ""',
                'image' => 'TEXT DEFAULT NULL',
                'content' => 'TEXT NOT NULL DEFAULT ""',
                'hash' => 'TEXT NOT NULL DEFAULT ""',
                'updated' => 'INTEGER NOT NULL DEFAULT 0',
                'deleted' => 'INTEGER NOT NULL DEFAULT 0',
            ), 'category_id');
            $this->db->create('categories', array(
                'id' => 'INTEGER PRIMARY KEY',
                'category' => 'TEXT UNIQUE COLLATE NOCASE',
                'parent' => 'INTEGER NOT NULL DEFAULT 0',
                'level' => 'INTEGER NOT NULL DEFAULT 0',
                'lft' => 'INTEGER NOT NULL DEFAULT 0',
                'rgt' => 'INTEGER NOT NULL DEFAULT 0',
            ));
        }
    }

    /**
     * Wraps up any pending transactions, and closes the database connection.  Always ``unset($sitemap)`` when you are done using it.
     *
     * @example
     *
     * ```php
     * unset($sitemap);
     * ```
     */
    public function __destruct()
    {
        foreach ($this->stmt as $action => $tables) {
            foreach ($tables as $stmt) {
                $this->db->close($stmt);
            }
        }
        if (isset($this->stmt['insert']['categories'])) {
            $hier = new Hierarchy($this->db, 'categories');
            $hier->refresh('category');
        }
        if ($this->transaction) {
            $this->db->exec('COMMIT');
        }
        $this->db->connection()->close();
    }

    /**
     * Generate the sitemap.xml files, and remove 404 pages as they appear.  Made static to save you the hassle of opening and closing the database.
     *
     * @param int $limit   The number of links to display per page.
     * @param int $expires The number of seconds you would like to cache the response.
     *
     * @return mixed Either ``false`` or a \Symfony\Component\HttpFoundation\Response for you to send.
     *
     * @example
     *
     * ```php
     * if ($xml = Sitemap::page()) {
     *     $page->send($xml);
     * }
     * ```
     */
    public static function page($limit = 10000, $expires = 0)
    {
        $page = Page::html();
        if (preg_match('/^sitemap(\-(?P<category>[a-z-]+)(\-(?P<num>[0-9]+))?)?\.xml$/i', $page->url['path'], $xml)) {
            $last_modified = 0;
            if (!empty($xml['category'])) {
                $category = strtolower($xml['category']);
                $num = (isset($xml['num'])) ? (int) $xml['num'] + 1 : 1;
                $page->enforce('sitemap-'.$category.($num > 1 ? '-'.$num : '').'.xml');
                $sitemap = new self();
                $xml = array();
                $offset = ($num > 1) ? (($num - 1) * $limit) - 1 : 0;
                if ($stmt = $sitemap->db->query(array(
                    'SELECT s.path, s.updated',
                    'FROM sitemap AS s',
                    'INNER JOIN categories AS c ON s.category_id = c.id',
                    'WHERE c.category LIKE ?',
                    'ORDER BY s.path ASC',
                    'LIMIT '.$offset.', '.$limit,
                ), $category.'%', 'row')) {
                    while (list($path, $updated) = $sitemap->db->fetch($stmt)) {
                        $last_modified = max($last_modified, $updated);
                        if (!empty($path)) {
                            $path .= $page->url['suffix'];
                        }
                        $xml[] = "\t".'<url>';
                        $xml[] = "\t\t".'<loc>'.$page->url['base'].$path.'</loc>';
                        $xml[] = "\t\t".'<lastmod>'.date('Y-m-d', $updated).'</lastmod>';
                        $xml[] = "\t".'</url>';
                    }
                    $sitemap->db->close($stmt);
                }
                unset($sitemap);
                if (empty($xml)) {
                    return new Response('', 404);
                }
                $xml = array(
                    '<?xml version="1.0" encoding="UTF-8"?>',
                    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
                        implode("\n", $xml),
                    '</urlset>',
                );
            } else {
                $page->enforce('sitemap.xml');
                $xml = array();
                $sitemap = new self();
                $hier = new Hierarchy($sitemap->db, 'categories');
                $categories = $hier->level(0, array('lft', 'rgt', 'category AS name'));
                foreach ($categories as $id => $cat) {
                    if ($row = $sitemap->db->row(array(
                        'SELECT MAX(s.updated) AS updated, COUNT(*) AS count',
                        'FROM categories AS c',
                        'INNER JOIN sitemap AS s ON c.id = s.category_id',
                        'WHERE c.lft BETWEEN '.$cat['lft'].' AND '.$cat['rgt'],
                    ), '', 'assoc')) {
                        $last_modified = max($last_modified, $row['updated']);
                        $updated = date('Y-m-d', $row['updated']);
                        for ($i = 1; $i <= $row['count']; $i += $limit) {
                            $num = ceil($i / $limit);
                            $num = ($num > 1) ? '-'.$num : '';
                            $xml[] = "\t".'<sitemap>';
                            $xml[] = "\t\t".'<loc>'.$page->url['base'].'sitemap-'.$cat['name'].$num.'.xml</loc>';
                            $xml[] = "\t\t".'<lastmod>'.$updated.'</lastmod>';
                            $xml[] = "\t".'</sitemap>';
                        }
                    }
                }
                unset($hier, $sitemap);
                if (empty($xml)) {
                    return new Response('', 404);
                }
                $xml = array(
                    '<?xml version="1.0" encoding="UTF-8"?>',
                    '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
                        implode("\n", $xml),
                    '</sitemapindex>',
                );
            }

            return Asset::dispatch('xml', array($last_modified => implode("\n", $xml), 'expires' => $expires));
        } else {
            $page->filter('response', function ($page, $response, $type) {
                if ($type == 'html') {
                    $sitemap = new Component();
                    $sitemap->delete($page->url['path']);
                    unset($sitemap);
                }
            }, array(404));

            return false;
        }
    }

    /**
     * Include the current page in the sitemap if it is an HTML page, and has no query string.  Made static to save you the hassle of opening and closing the database.
     *
     * Use this method when you want to "set it, and forget it".  When adding (and updating) **$content** dynamically, then some of your links may be a bit outdated as we can only update them when they are accessed.  If this is unacceptable to you, then use the '**reset**', '**upsert**', and '**delete**' methods to keep everything up-to-date.  Using the static add method would be equivalent to the following code:
     *
     * ```php
     * if (empty($page->url['query'])) {
     *     $sitemap = new Sitemap();
     *     $sitemap->upsert($category, array_merge(array(
     *         'path' => $page->url['path'],
     *         'title' => $page->title,
     *         'description' => $page->description,
     *         'keywords' => $page->keywords,
     *         'image' => $page->image,
     *         'content' => $content,
     *     ), $save));
     *     unset($sitemap);
     * }
     * ```
     *
     * @param string $category To group related links.
     * @param string $content  The main body of your page.
     * @param array  $save     Any additional information that you consider to be important, and would like to include with your search results.
     *
     * @example
     *
     * ```php
     * Sitemap::add('pages', $html);
     * ```
     */
    public static function add($category, $content, array $save = array())
    {
        $page = Page::html();
        if ($page->url['format'] != 'html') {
            return;
        }
        $page->filter('response', function ($page, $response) use ($category, $content, $save) {
            if (empty($page->url['query'])) {
                $sitemap = new Component();
                $sitemap->upsert($category, array_merge(array(
                    'path' => $page->url['path'],
                    'title' => $page->title,
                    'description' => $page->description,
                    'keywords' => $page->keywords,
                    'image' => $page->image,
                    'content' => $content,
                ), $save));
                unset($sitemap);
            }
        }, array('html', 200));
    }

    /**
     * Reset a **$category** before ``$this->upsert()``ing everything in it, so that you can ``$this->delete()`` any missing links after.  Always ``unset($sitemap)`` explicitly when doing this.
     *
     * @param string $category The sitemap section you are working on.
     *
     * @example
     *
     * ```php
     * $sitemap = new Sitemap;
     * $sitemap->reset('pages');
     * // $sitemap->upsert('pages', ...); // everything
     * $sitemap->delete(); // the missing 'pages'
     * unset($sitemap); // wrap up the database, and close the connection
     * ```
     */
    public function reset($category)
    {
        if ($ids = $this->db->ids('SELECT id FROM categories WHERE category LIKE ?', $category.'%')) {
            $this->db->exec('UPDATE sitemap SET deleted = 1 WHERE category_id IN('.implode(', ', $ids).')');
        }
    }

    /**
     * Upsert a page into the Sitemap.  Always ``unset($sitemap)`` explicitly when doing this.
     *
     * When upserting everything into a **$category**, you can ``$this->reset($category)`` beforehand, then ``$this->delete()`` any missing **$category** links afterwards.
     *
     * @param string   $category The sitemap section you are working on.
     * @param string[] $save     An array of data to save for each link:
     *
     *   - '**updated**' => A timestamp integer (if known).
     *   - '**path**' => Of the url, without any suffix.
     *   - '**title**' => Of the page.
     *   - '**description**' => The meta description.
     *   - '**keywords**' => A comma-separated list of tags.
     *   - '**image**' => An image url.
     *   - '**content**' => The main content section of the page.
     *     - We ``strip_tags()`` in house for searching, but deliver the original content with your search results.
     *   - '**...**' => Anything else you deem appropriate.
     *
     * @example
     *
     * ```php
     * $sitemap = new Sitemap;
     * $sitemap->upsert('pages', array(
     *     'path' => 'beautiful-terrible-storm',
     *     'title' => 'I Watched The Storm, So Beautiful Yet Terrific',
     *     'image' => 'http://example.com/storm.jpg',
     *     'content' => '<p>It was amazing.</p>',
     * ));
     * unset($sitemap); // wrap up the database, and close the connection
     * ```
     */
    public function upsert($category, array $save)
    {
        $sitemap = array();
        $save['category'] = $category;
        $updated = (isset($save['updated']) && is_numeric($save['updated'])) ? abs($save['updated']) : time();
        unset($save['updated']);
        foreach (array('category', 'path', 'title', 'description', 'keywords', 'image', 'content') as $value) {
            $sitemap[$value] = (isset($save[$value])) ? $save[$value] : '';
            unset($save[$value]);
        }
        $sitemap['info'] = serialize($save);
        $sitemap['hash'] = md5(implode('', $sitemap));
        $sitemap['updated'] = $updated;
        $sitemap['deleted'] = 0;
        extract($sitemap);
        if ($row = $this->exec('SELECT', 'sitemap', $path)) {
            if ($row['hash'] != $hash || $row['deleted'] = 1) {
                if ($row['hash'] == $hash) {
                    $updated = $row['updated']; // keep the former
                }
                $this->exec('UPDATE', 'search', array($path, $title, $description, $keywords, strip_tags($content), $row['docid']));
                $this->exec('UPDATE', 'sitemap', array($this->id($category), $path, $info, $image, $content, $hash, $updated, $deleted, $row['docid']));
            }
        } else {
            $docid = $this->exec('INSERT', 'search', array($path, $title, $description, $keywords, strip_tags($content)));
            $this->exec('INSERT', 'sitemap', array($docid, $this->id($category), $path, $info, $image, $content, $hash, $updated, $deleted));
        }
    }

    /**
     * Delete a specific **$path** (if specified), or everything that was not ``$this->upsert()``ed after ``$this->reset()``ing your sitemap category.  Always ``unset($sitemap)`` explicitly when doing this.
     *
     * @param string $path
     *
     * @example
     *
     * ```php
     * $sitemap = new Sitemap;
     * $sitemap->delete('beautiful-terrible-storm');
     * unset($sitemap); // wrap up the database, and close the connection
     * ```
     */
    public function delete($path = null)
    {
        if ($path) {
            if ($row = $this->exec('SELECT', 'sitemap', $path)) {
                $this->exec('DELETE', 'search', $row['docid']);
                $this->exec('DELETE', 'sitemap', $row['docid']);
            }
        } else {
            if ($stmt = $this->db->query('SELECT docid FROM sitemap WHERE deleted = ?', 1, 'assoc')) {
                while ($row = $this->db->fetch($stmt)) {
                    $this->exec('DELETE', 'search', $row['docid']);
                    $this->exec('DELETE', 'sitemap', $row['docid']);
                }
                $this->db->close($stmt);
            }
        }
    }

    /**
     * Get the total number of search results for a given **$phrase**.
     *
     * @param string $phrase   The search term.
     * @param string $category A specific sitemap section.
     * @param string $where    Adds additional WHERE qualifiers to the query.  Prepend search table fields with an '**s.**', and sitemap table fields with an '**m.**'.
     *
     * @return int The total count.
     *
     * @example
     *
     * ```php
     * if (!$pagination->set()) {
     *   $pagination->total($sitemap->count('words'));
     * }
     * ```
     */
    public function count($phrase, $category = '', $where = '')
    {
        return $this->db->fts->count('search', $phrase, $this->where($category, $where));
    }

    /**
     * Get the search results for a given **$phrase**, from the most relevant to the least.
     *
     * @param string      $phrase   The search term.
     * @param string      $category A specific sitemap section.
     * @param int|string  $limit    If you are not paginating results and only want the top whatever, then this is an integer.  Otherwise just pass the ``$pagination->limit`` '<b> LIMIT offset, length</b>' string.
     * @param int|float[] $weights  An array of importance that you would like to place on the fields searched.  The order is: '**path**', '**title**', '**description**', '**keywords**', and '**content**'.  The default weights are ``array(1,1,1,1,1)``, every field being of equal importance.  If you only want to search the keywords, then you can specify ``array(0,0,0,1,0)``.  Please note that with this arrangement, the most relevant results will be returned first (with the search term being found among the keywords), but all of the other results will also be returned with a rank of 0 if the search term could be found anywhere else.
     * @param string      $where    Adds additional WHERE qualifiers to the query.  Prepend search table fields with an '**s.**', and sitemap table fields with an '**m.**'.
     *
     * @return array An associative array of results.
     *
     * @example
     *
     * ```php
     * foreach ($sitemap->search('words', '', $pagination->limit) as $row) {
     *     print_r($row);
     * }
     * ```
     */
    public function search($phrase, $category = '', $limit = '', array $weights = array(), $where = '')
    {
        $page = Page::html();
        $fields = array('s.path', 's.title', 's.description', 's.keywords', 's.content AS search', 'c.category', 'm.path AS url', 'm.info', 'm.image', 'm.updated', 'm.content');
        $weights = array_slice(array_pad($weights, 5, 1), 0, 5);
        $search = $this->db->fts->search('search', $phrase, $limit, $this->where($category, $where), $fields, $weights);
        foreach ($search as $key => $row) {
            $row['words'] = $this->db->fts->offset($row, array('description', 'search', 'title', 'path', 'keywords'));
            if (!empty($row['url'])) {
                $row['url'] .= $page->url['suffix'];
            }
            $row['url'] = $page->url['base'].$row['url'];
            foreach (unserialize($row['info']) as $info => $value) {
                if (!isset($row[$info])) {
                    $row[$info] = $value;
                }
            }
            unset($row['search'], $row['info']);
            $row['snippet'] = strip_tags($row['snippet'], '<b>');
            $search[$key] = $row;
        }

        return $search;
    }

    /**
     * Once your search results are in, if you would like to know the specific word(s) which made a given page relevant, you may obtain them through this method. 
     *
     * @param string $phrase The original search term.
     * @param int    $docid  The sitemap's docid which is returned with every search result.
     *
     * @return array The unique search words found which made the **$phrase** relevant.
     *
     * @example
     *
     * ```php
     * print_r($sitemap->words('words', $row['docid']));
     * ```
     */
    public function words($phrase, $docid)
    {
        return $this->db->fts->words('search', $phrase, $docid);
    }

    /**
     * Adds the category and additional parameters to a query string.
     * 
     * @param string $category
     * @param string $and
     * 
     * @return string
     */
    private function where($category, $and = '')
    {
        if (!empty($category)) {
            $sql = array();
            foreach ((array) $category as $like) {
                $sql[] = "c.category LIKE '{$like}%'";
            }
            $category = 'AND '.implode(' OR ', $sql);
        }

        return trim('INNER JOIN sitemap AS m INNER JOIN categories AS c WHERE s.docid = m.docid AND m.category_id = c.id '.$category.' '.$and);
    }

    /**
     * Converts a category string into it's id.
     * 
     * @param string $category
     * 
     * @return int
     */
    private function id($category)
    {
        if (is_null($this->ids)) {
            $this->ids = array();
            $categories = $this->db->all('SELECT category, id FROM categories', '', 'assoc');
            foreach ($categories as $row) {
                $this->ids[$row['category']] = $row['id'];
            }
        }
        if (!isset($this->ids[$category])) {
            $parent = 0;
            $previous = '';
            foreach (explode('/', $category) as $path) {
                if (!isset($this->ids[$previous.$path])) {
                    $this->ids[$previous.$path] = $this->exec('INSERT', 'categories', array($previous.$path, $parent));
                }
                $parent = $this->ids[$previous.$path];
                $previous .= $path.'/';
            }
        }

        return $this->ids[$category];
    }

    /**
     * @param string       $action The type of query.
     * @param string       $table  The table to take $action on..
     * @param string|array $values The appropriate values for a given query.
     * 
     * @return array|false|int
     */
    private function exec($action, $table, $values)
    {
        $action = strtolower($action);
        if (!isset($this->stmt[$action][$table])) {
            if ($this->transaction === false && $action != 'select') {
                $this->transaction = true;
                $this->db->exec('BEGIN IMMEDIATE');
            }
            switch ($action) {
                case 'select':
                    if ($table == 'sitemap') {
                        $stmt = $this->db->prepare('SELECT docid, hash, updated, deleted FROM sitemap WHERE path = ?', 'assoc');
                    }
                    break;
                case 'insert':
                    if ($table == 'search') {
                        $stmt = $this->db->insert('search', array('path', 'title', 'description', 'keywords', 'content'));
                    } elseif ($table == 'sitemap') {
                        $stmt = $this->db->insert('sitemap', array('docid', 'category_id', 'path', 'info', 'image', 'content', 'hash', 'updated', 'deleted'));
                    } elseif ($table == 'categories') {
                        $stmt = $this->db->insert('categories', array('category', 'parent'));
                    }
                    break;
                case 'update':
                    if ($table == 'search') {
                        $stmt = $this->db->update('search', 'docid', array('path', 'title', 'description', 'keywords', 'content'));
                    } elseif ($table == 'sitemap') {
                        $stmt = $this->db->update('sitemap', 'docid', array('category_id', 'path', 'info', 'image', 'content', 'hash', 'updated', 'deleted'));
                    }
                    break;
                case 'delete':
                    if ($table == 'search') {
                        $stmt = $this->db->prepare('DELETE FROM search WHERE docid = ?');
                    } elseif ($table == 'sitemap') {
                        $stmt = $this->db->prepare('DELETE FROM sitemap WHERE docid = ?');
                    }
                    break;
            }
            $this->stmt[$action][$table] = (isset($stmt)) ? $stmt : null;
        }
        $result = $this->db->execute($this->stmt[$action][$table], $values);
        if ($action == 'select') {
            $row = $this->db->fetch($this->stmt[$action][$table]);

            return (!empty($row)) ? $row : false;
        } else {
            return $result;
        }
    }
}
