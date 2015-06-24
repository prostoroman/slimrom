<?php

namespace BCMS;

class Item
{
    protected $tableName = 'undefined';
    protected $title = 'undefined';
    protected $alias = 'undefined';
    protected $startUrl = 'undefined';
    protected $adminUrl = 'undefined';

    protected $bcms;

    public function __construct(\Slim\Slim $di)
    {
        $this->bcms = $di;
    }

    public function defaultAction()
    {
        $items = $this->menuTree();

        $this->bcms->view()->appendData(array('items' => $items));
        $this->bcms->render('@admin/'.$this->alias.'/list.twig');
    }

     /**
     * Find pages by url-parts array
     *
     * @param array $parts parts of url
     * @return object
     */

    public function findByUrl($parts = array())
    {
        $url = '/'.implode('/', $parts);

        $item = $this->getByUrl($url);

        if (empty($item)) {
            $this->bcms->notFound();
        }

        if ($item->redirect_url) {
            $this->bcms->redirect($item->redirect_url);
        }

        $template = $item->template ? $item->template : 'default.twig';

        $this->bcms->render('pages/'.$template, array('page' => $item));
    }

 /**
  * Find one page by URL
  * @param  string $url
  * @return ORM item
  */

    public function getByUrl($url)
    {
        $item = \ORM::for_table($this->tableName)->where('url', $url)->find_one();

        return empty($item) ? false : $item;
    }

    public function get($id)
    {
        $item = \ORM::for_table($this->tableName)->find_one($id);

        return $item;
    }

    public function add()
    {
        $req = $this->bcms->request();

        if ($req->isGet()) {
            $items = $this->menuTree();
            $templates = $this->templates();

            $this->bcms->view()->appendData(array('items' => $items, 'templates' => $templates));
            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $this->save();
            $this->goHome();
        }
    }

    public function edit($id)
    {
        $req = $this->bcms->request();
        $uri = $req->getResourceUri();

        if ($req->isGet()) {
            $item = $this->get($id);

            if (!$item) {
                $this->bcms->notFound();
            }

            $item = $item->as_array();
            $items = $this->menuTree();
            $templates = $this->templates();

            $view = $this->bcms->view();
            $view->appendData(array('items' => $items, 'templates' => $templates, 'item' => $item));

            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $this->save($id);
            $this->bcms->redirect($this->adminUrl.'/edit/'.$id);
        }
    }

    public function save($id = null)
    {
        $req = $this->bcms->request();

        if (!is_null($id)) {
            $item = $this->get($id);

            if (!$item) {
                $this->bcms->flash('error', 'Item is not found');
            }
            $item->set_expr('date_changed', "now()");
        } else {
            $item = \ORM::for_table($this->tableName)->create();
            $item->set_expr('date_created', "NOW()"); // sqlite datetime('now')
            $item->set_expr('date_changed', "now()");
        }

        if ($id and (!$req->post('name') or !$req->post('slug')) and $item->url != '/') {
            $this->bcms->flash('error', 'Name or url is not defined');
            $this->goHome();
        }

        if ($id > 0 and $id == $req->post('parent_id')) {
            $this->bcms->flash('error', 'Page can not be self parent');
            $this->goHome();
        }

        $item->parent_id = $req->post('parent_id') ? $req->post('parent_id') : 0;
        $item->is_visible = $req->post('is_visible') ? 1 : 0;
        $item->name = $req->post('name');
        $item->slug = (!$req->post('slug') && $item->url != '/') ? $this->slugify($req->post('name')) : $req->post('slug');
        $item->text = $req->post('text');
        $item->template = $req->post('template');
        $item->title = $req->post('title') ? $req->post('title') : $req->post('name');
        $item->keywords = $req->post('keywords');
        $item->description = $req->post('description');
        $item->redirect_url = $req->post('redirect_url');
        $item->order = $req->post('order') ? $req->post('order') : \ORM::for_table($this->tableName)->where('parent_id', $item->parent_id)->max('order');

        $checkUrlDuplicate = \ORM::for_table($this->tableName)
                         ->where('parent_id', $item->parent_id)
                         ->where('slug', $item->slug);
        if (!is_null($id)) {
            $checkUrlDuplicate = $checkUrlDuplicate->where_not_equal('id', $id);
        }

        $checkUrlDuplicate = $checkUrlDuplicate->find_one();

        if (!empty($checkUrlDuplicate)) {
            $this->bcms->flash('error', 'Item with the same URL already exists');
            $this->goHome();
        }

        $item->save();

        $this->generateUrls($item->parent_id);
        $this->fixOrder($item->parent_id);
        $this->hasChilds();

        $this->bcms->flash('success', 'Item saved');
    }

    protected function templates()
    {
        $templates = array();

        foreach (glob($this->bcms->templates_dir."/*.twig") as $filename) {
            $templates[] = basename($filename);
        }

        return $templates;
    }

    public function menu($level = 1)
    {
        if ($level == 1) {
            return $this->menu1();
        }

        $req = $this->bcms->request();
        $url = $req->getResourceUri();
        $url = str_replace($this->startUrl, '', $url);

        if ($url === '/' and $level > 1) {
            return;
        }

        $urlParts = explode('/', $url);

        $currentLevel = count($urlParts) - 1;

        if ($level - $currentLevel > 1 && $url !== '/') {
            return;
        }

        $sliced = array_slice($urlParts, 0, $level);
        $baseUrl = implode('/', $sliced);

        $basePage = \ORM::for_table($this->tableName)->select('id')->where('url', $baseUrl)->find_one();

        if (!$basePage) {
            return;
        }

        $items = \ORM::for_table($this->tableName)
                ->select_many('id', 'slug', 'name', 'url', 'has_childs')
                ->where('parent_id', $basePage->id)
                ->where('is_visible', 1)
                ->order_by_asc('order')
                ->find_array();

        foreach ($items as $key => $item) {
            $item['active'] = 0;
            if (strpos($url, $item['url']) !== false) {
                $item['active'] = 1;
            } //$item->url == $url
            $item[$key]['url'] = $this->startUrl.$item['url'];
            $items[$key] = $this->bcms->translations->translate($items[$key], $this->alias, $this->bcms->locale);
        }

        return $items;
    }

    public function menu1()
    {
        $homepage = \ORM::for_table($this->tableName)->select('id')->where('slug', '')->find_one();

        $items = \ORM::for_table($this->tableName)
                ->select_many('id', 'url', 'name', 'has_childs')
                ->where('parent_id', $homepage->id)
                ->where('is_visible', 1)
                ->order_by_asc('order')
                ->find_many();

        $request = $this->bcms->request();
        $url = $request->getResourceUri();

        foreach ($items as $item) {
            if ($item->url == '/' && $url == '/') {
                $item->is_active = 1;
            } elseif ($item->url !== '/' && strpos($url, $item->url) !== false) {
                $item->is_active = 1;
            }
        }

        return $items;
    }

    public function menuTree($parent_id = 0, $level = 0, $only_visible = 0)
    {
        $level++;

        $request = $this->bcms->request();
        $url = $request->getResourceUri();

        $items = \ORM::for_table($this->tableName)
             ->select_many('id', 'name', 'is_visible', 'url', 'has_childs', 'order', 'date_changed')
             ->where('parent_id', $parent_id);

        if ($only_visible) {
            $items = $items->where('is_visible', 1);
        }

        $items = $items->order_by_asc('order')->find_array();
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            $isActive = '';

            if ($items[$i]['url'] == '/' && $url == '/') {
                $items[$i]['active'] = true;
            }
            if ($items[$i]['url'] !== '/' && strpos($url.'/', $items[$i]['url'].'/') !== false) {
                $items[$i]['active'] = true;
            }

            if ($items[$i]['id'] > 0 && $items[$i]['has_childs']) {
                $items[$i]['childs'] = $this->menuTree($items[$i]['id'], $level);
            }

            $items[$i]['level'] = $level;

            $items[$i]['url'] = $this->startUrl.$items[$i]['url'];

            // Translate
            $items[$i] = $this->bcms->translations->translate($items[$i], $this->alias, $this->bcms->locale);

        }

        return $items;
    }

    public function generateUrls($parent_id = 0)
    {
        if ($parent_id) {
            $items = \ORM::for_table($this->tableName)->select('id')->select('slug')->select('parent_id')->where('parent_id', $parent_id)->find_many();
        } else {
            $items = \ORM::for_table($this->tableName)->select('id')->select('slug')->select('parent_id')->find_many();
        }

        foreach ($items as $item) {
            if ($item->id > 0) {
                $item->url = '/'.$this->build_url($item->slug, $item->parent_id);
                $item->save();
          //echo $item->slug . ' &rarr; ' . $item->url . '<br />';
            }
        }
    }

    public function movePage($order = 0, $parent_id = 0)
    {
        $item = \ORM::for_table($this->tableName)
             ->select_many('id', 'order', 'parent_id')
             ->where('parent_id', $parent_id)
             ->where('order', $order)
             ->find_one();
        if ($item) {
            $item->order = $item->order+1;
            $this->movePage($item->order, $item->parent_id);
        }
    }

    public function fixOrder($parent_id = 0)
    {
        $items = \ORM::for_table($this->tableName)
             ->select_many('id', 'name', 'url', 'has_childs', 'order', 'date_changed')
             ->where('parent_id', $parent_id)
             ->order_by_asc('order')
             ->find_many();

        $countPages = count($items);

        for ($i = 0; $i < $countPages; $i++) {
            $items[$i]->order = $i + 1;
            $items[$i]->save();

       //echo $items[$i]->name.' - '.$items[$i]->order.'<br>';

       if ($items[$i]->id > 0 && $items[$i]->has_childs) {
           $this->fixOrder($items[$i]->id);
       }
        }
    }

    public function moveUp($id)
    {
        $this->move($id, 'up');
        $this->goHome();
    }

    public function moveDown($id)
    {
        $this->move($id, 'down');
        $this->goHome();
    }

    protected function move($id, $where = 'up')
    {
        $item = \ORM::for_table($this->tableName)
             ->select_many('id', 'parent_id', 'order')
             ->where('id', $id)
             ->find_one();

        if ($where == 'up') {
            if ($item->order < 2) {
                return false;
            }
            $nextOrder = $item->order - 1;
        } elseif ($where == 'down') {
            $maxOrder = \ORM::for_table($this->tableName)
                      ->where('parent_id', $item->parent_id)
                      ->max('order');

            if ($item->order == $maxOrder) {
                return false;
            }
            $nextOrder = $item->order + 1;
        }

        $neighbor = \ORM::for_table($this->tableName)
             ->select_many('id', 'parent_id', 'order')
             ->where('parent_id', $item->parent_id)
             ->where('order', $nextOrder)
             ->find_one();

        if (!$neighbor) {
            return false;
        }

        $neighbor->order = $item->order;
        $neighbor->save();

        $item->order = $nextOrder;
        $item->save();

        return true;
    }

    public function hasChilds()
    {
        $items = \ORM::for_table($this->tableName)
                ->select_many('id', 'slug', 'parent_id')
                ->find_many();

        foreach ($items as $item) {
            $childs = \ORM::for_table($this->tableName)
                   ->select_many('id', 'slug', 'parent_id')
                   ->where('parent_id', $item->id)
                   ->find_many();

            if (!$childs) {
                $item->has_childs = false;
                $item->save();
            } else {
                $item->has_childs = true;
                $item->save();
            }
        }
    }

    public function delete($id)
    {
        $item = $this->get($id);

        $countChilds = \ORM::for_table($this->tableName)->where('parent_id', $id)->count();

        if (!$item) {
            $this->bcms->flash('error', 'Not found');
        }

        if (!$id) {
            $this->bcms->flash('error', 'You can\'t delete this item');
        } elseif ($item and $countChilds) {
            $this->bcms->flash('error', 'You can\'t delete item wich has childs');
        } elseif ($item) {
            $item->delete();
            $this->fixOrder($item->parent_id);
            $this->bcms->flash('info', 'Item deleted');
        }

        if ($this->bcms->request()->isAjax()) {
            echo 'Item deleted';
        } else {
            $this->goHome();
        }
    }

    public function delete_many(array $ids)
    {
        foreach ($ids as $id) {
            $this->delete($id);
        }

        $this->goHome();
    }

// Build URL path for a page
private function build_url($item_url, $item_parent_id)
{
    $items_url[0] = $item_url;
    $n = 1;
    $cur_parent_id = $item_parent_id;

    while ($item = \ORM::for_table($this->tableName)
              ->select('id')
              ->select('slug')
              ->select('name')
              ->select('parent_id')
              ->where('id', $cur_parent_id)
              ->find_one()) {
        if ($item->id == 0) {
            break;
        }

        $items_url[$n] = $item->slug;
        $n++;
        $cur_parent_id = $item->parent_id;
    }

    $url = "";
    for ($n = $n-1; $n >= 0; $n--) {
        $url .= $items_url[$n]."/";
    }

    $url = preg_replace("/\/$/", "", $url);

    return $url;
}

    public function goHome()
    {
        $this->bcms->redirect($this->adminUrl);
    }

    public function slugify($string)
    {
        $string = str_replace('quot', '', trim($string));
        $string = preg_replace('/[^\pL\pN\pZs@-]+/u', '', $string);

        $converter = array(
     'а' => 'a',   'б' => 'b',   'в' => 'v',
     'г' => 'g',   'д' => 'd',   'е' => 'e',
     'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
     'и' => 'i',   'й' => 'y',   'к' => 'k',
     'л' => 'l',   'м' => 'm',   'н' => 'n',
     'о' => 'o',   'п' => 'p',   'р' => 'r',
     'с' => 's',   'т' => 't',   'у' => 'u',
     'ф' => 'f',   'х' => 'h',   'ц' => 'c',
     'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sh',
     'ь' => "",  'ы' => 'y',   'ъ' => "",
     'э' => 'e',   'ю' => 'yu',  'я' => 'ya',

     'А' => 'A',   'Б' => 'B',   'В' => 'V',
     'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
     'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
     'И' => 'I',   'Й' => 'Y',   'К' => 'K',
     'Л' => 'L',   'М' => 'M',   'Н' => 'N',
     'О' => 'O',   'П' => 'P',   'Р' => 'R',
     'С' => 'S',   'Т' => 'T',   'У' => 'U',
     'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
     'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'SH',
     'Ь' => "",  'Ы' => 'Y',   'Ъ' => "",
     'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
     ' ' => '-',
    );

        return substr(strtr($string, $converter), 0, 200);
    }

    public function getAdminUrl()
    {
        return $this->adminUrl;
    }

    public function getTitle()
    {
        return $this->title;
    }
}

/*
CREATE TABLE IF NOT EXISTS `b_products_categories` (
`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
`parent_id` bigint(20) unsigned NULL DEFAULT NULL,
`name` varchar(255) NOT NULL,
`slug` varchar(255) NOT NULL,
`url` varchar(255) DEFAULT NULL,
`text` longtext,
`text_backup` longtext,
`title` varchar(255) NOT NULL,
`keywords` varchar(255) NOT NULL,
`order` int(10) unsigned DEFAULT NULL,
`is_visible` tinyint(1) DEFAULT '1',
`redirect_url` varchar(255) DEFAULT NULL,
`template` varchar(255) DEFAULT NULL,
`date_created` datetime NOT NULL,
`date_changed` datetime NOT NULL,
`has_childs` tinyint(1) DEFAULT NULL,
PRIMARY KEY (`id`),
FULLTEXT KEY `text` (`text`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
*/
