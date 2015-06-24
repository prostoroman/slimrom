<?php

/**
* Pages
*
* Class for working with pages
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS\News;

class News extends \BCMS\Item
{
    protected $title = 'News';
    protected $tableName = 'b_news';
    protected $alias = 'news';
    protected $startUrl = '/news';
    protected $adminUrl = '/admin/news-news';
    protected $perPage = 30;
    protected $bcms;

    protected $sizes =  array('small' => 80, 'medium_square' => 250, 'medium' => 700);

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function defaultAction()
    {
        $req = $this->bcms->request();
        $categoryId = $req->get('category_id');
        $this->bcms->view->appendData(array('selectedCategory' => $categoryId));

        $items = $this->getMany($categoryId, 0);
        $this->bcms->view->appendData(array('items' => $items));

        $newscategories = new \BCMS\News\Categories($this->bcms);
        $categories = $newscategories->menuTree();
        $this->bcms->view->appendData(array('categories' => $categories));

        if ($this->bcms->request()->isAjax()) {
            $this->bcms->render('@admin/'.$this->alias.'-select.twig');
        } else {
            $this->bcms->render('@admin/'.$this->alias.'/list.twig');
        }
    }

   /**
   * Find pages by url-parts array
   *
   * @param array $parts parts of url
   * @return object
   */

    public function findByUrl($parts = array())
    {
        $slug = array_pop($parts);

        $category_url = '/'.implode('/', $parts);
        $category = $this->bcms->newscategories->getByUrl($category_url);

        if ($category) {
            $category_id = $category->id;
        }

        $item = \ORM::for_table($this->tableName)
         ->where('slug', $slug);

        if ($category) {
            $item = $item->where('category_id', $category_id);
        }

        $item = $item->find_one();

        if (empty($item)) {
            return false;
            $this->bcms->notFound();
        }

        $item = $item->as_array();

        $item = $this->bcms->translations->translate($item, $this->alias, $this->bcms->locale);

        $template = 'news.twig';

        $this->bcms->render('pages/'.$template, array('page' => $item, 'newsitem' => $item));
    }

    public function get($id)
    {
        $item = \ORM::for_table($this->tableName)->where('id', $id)->find_one();

        return $item;
    }

    public function add()
    {
        $req = $this->bcms->request();

        if ($req->isGet()) {
            $newscategories = new \BCMS\News\Categories($this->bcms);
            $categories = $newscategories->menuTree();

            $view = $this->bcms->view;
            $view->appendData(array('categories' => $categories));

            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $item = $this->save();

            $this->bcms->redirect($this->adminUrl);
        }
    }

    public function edit($id)
    {
        $req = $this->bcms->request();

        if ($req->isGet()) {
            $item = $this->get($id);

            if (!$item) {
                $this->bcms->notFound();
            }

            $newscategories = new \BCMS\News\Categories($this->bcms);
            $categories = $newscategories->menuTree();
            $item = $item->as_array();

            $translations = $this->bcms->translations->get($item['id'], $this->alias);
            $item['translations'] = $translations;

            $view = $this->bcms->view;
            $view->appendData(array('item' => $item, 'categories' => $categories));

            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $item = $this->save($id);
            $this->bcms->redirect($this->adminUrl);
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
            $item->set_expr('date_changed', "NOW()");
        }

        if (!$req->post('name')) {
            $this->bcms->flash('error', 'Name or slug is not defined');
            $this->goHome();
        }

        $item->category_id = $req->post('category_id'); // ? $req->post('category_id') : 0

        $item->name = $req->post('name');
        $item->slug = $req->post('slug') ? $req->post('slug') : $this->slugify($item->name);

        $item->photo = $req->post('photo');

        if ($item->photo) {
            $item->photo_small = $this->makeThumb($item->photo, 'small');
            $item->photo_medium = $this->makeThumb($item->photo, 'medium');
            $item->photo_medium_square = $this->makeThumb($item->photo, 'medium_square');
        }

        $item->description = $req->post('description');
        $item->text = $req->post('text');
        $item->title = $req->post('title') ? $req->post('title') : $req->post('name');
        $item->keywords = $req->post('keywords');

        $item->url = $this->buildUrl($item->slug, $item->category_id);

        $checkUrlDuplicate = \ORM::for_table($this->tableName)
                           ->where('category_id', $item->category_id)
                           ->where('slug', $item->slug);
        if (!is_null($id)) {
            $checkUrlDuplicate = $checkUrlDuplicate->where_not_equal('id', $id);
        }

        $checkUrlDuplicate = $checkUrlDuplicate->find_one();

        if (!empty($checkUrlDuplicate) && $item->slug) {
            $this->bcms->flash('error', 'Item with the same URL already exists');
            $this->goHome();
        }

        $item->save();

        $this->bcms->translations->update($item->id(), $this->alias);

        $this->bcms->flash('success', 'Item saved');

        return $item;
    }

    public function getMany($categoryId = 0, $only_visible = 1, $pages = true)
    {
        $items = \ORM::for_table($this->tableName);

        if (is_array($categoryId)) {
            $items = $items->where_in('category_id', $categoryId);
        } elseif ($categoryId > 0) {
            $items = $items->where('category_id', $categoryId);
        }

        // Search
        $query = $this->bcms->request()->get('query');

        if ($query) {
            $items = $items->where_like('name', '%'.$query.'%');
            $this->bcms->view->appendData(array('query' => $query));
        }

        // Ordering
        $sortBy = $this->bcms->request()->get('sort_by');

        if ($sortBy) {
            $items = $items->order_by_asc($sortBy);
            $this->bcms->view->appendData(array('sort_by' => $sortBy));
        }

        if ($pages) {
            //Pagination
            $pagination = new \BCMS\Pagination($this->bcms);
            $pagination->setLimit($this->perPage);
            $pagination->setCount($items->count());

            $items =  $items->limit($this->perPage)->offset($pagination->start);
        }

        $items = $items->order_by_desc('date_created');

        $items =  $items->find_array();

        if (!$items) {
            return false;
        }
        for ($i = 0; $i < count($items); $i++) {
            $items[$i]['url'] = $this->startUrl.$items[$i]['url'];
            $items[$i] = $this->bcms->translations->translate($items[$i], $this->alias, $this->bcms->locale);
        }

        return $items;
    }

    public function latest($limit)
    {
        if (!$limit) {
            $limit = 3;
        }
        $items = \ORM::for_table($this->tableName);
        $items = $items->limit($limit)->offset(0);
        $items = $items->order_by_desc('date_created');
        $items = $items->find_array();

        if (!$items) {
            return false;
        }
        for ($i = 0; $i < count($items); $i++) {
            $items[$i]['url'] = $this->startUrl.$items[$i]['url'];
            $items[$i] = $this->bcms->translations->translate($items[$i], $this->alias, $this->bcms->locale);
        }

        return $items;
    }

    public function delete($id)
    {
        $item = $this->get($id);

        if ($item) {
            $item->delete();
            $this->bcms->translations->delete($id, $this->alias);
        }

        if ($this->bcms->request()->isAjax()) {
            echo 'Item deleted';
        } else {
            $this->bcms->flash('info', 'Item deleted');
            $this->goHome();
        }
    }

    // Build URL path for a page
    private function buildUrl($item_url, $category_id)
    {
        $category = \ORM::for_table('b_news_categories')
                  ->select('id')
                  ->select('url')
                  ->select('name')
                  ->where('id', $category_id)
                  ->find_one();
        if (!$category) {
            $url = '/'.$item_url; //$this->startUrl . '/' .
        } else {
            $url = $category->url.'/'.$item_url;
        }

        return $url;
    }

    public function generateUrls($id = 0)
    {
        $this->bcms->flash('success', 'URL товаров обновлены');
        $items = \ORM::for_table($this->tableName)->select('id')->select('slug')->select('category_id');

        if ($id) {
            $items = $items->where('category_id', $id);
        }

        $items = $items->find_many();

        foreach ($items as $item) {
            $item->url = $this->buildUrl($item->slug, $item->category_id);
            $item->save();
         //echo $item->url.'<br />';
        }
    }

    public function fixSlugs()
    {
        $items = \ORM::for_table($this->tableName)->select('id')->select('slug')->select('category_id');
        $items = $items->find_many();

        foreach ($items as $item) {
            $item->slug = $this->slugify($item->slug);
            $item->save();
         //echo $item->url.'<br />';
        }
    }

    public function goHome()
    {
        $this->bcms->redirect($this->adminUrl);
    }


    public function search($query)
    {
        $page = array('title' => $this->bcms->translate('Search query').' "'.$query.'"');

        $query = trim($query);

        if (strlen($query) < 3) {
            $this->bcms->render('pages/search.twig', array('page' => $page, 'products' => null));

            return false;
        }

        $items = \ORM::for_table($this->tableName)
         ->select_many('id', 'name', 'photo', 'url', 'price')
         ->where_like('name', '%'.$query.'%');

        //Pagination
        $pagination = new Pagination($this->bcms);
        $pagination->setCount($items->count());

        $items = $items->limit($pagination->limit)->offset($pagination->start)->find_array();

        for ($i = 0; $i < count($items); $i++) {
            $items[$i]['url'] = $this->startUrl.$items[$i]['url'];
        }

        if ($this->bcms->request()->isAjax()) {
            $suggestions = array();

            foreach ($items as $item) {
                $suggestions[] = $item['name'];
            }

            $response = array('query' => $query, 'suggestions' => $suggestions);

            $responseJSON = json_encode($response);
            exit($responseJSON);
        } else {
            if (1 === count($items)) {
                $this->bcms->redirect($items[0]['url']);
            }

            $this->bcms->render('pages/search.twig', array('page' => $page, 'products' => $items));
        }

        return $items;
    }

    private function makeThumb($photo, $size = 'small')
    {
        if (!$photo) {
            return false;
        }

        $sizes = $this->sizes;

        $absolutePath = $this->bcms->home_dir;
        $sourceFilePath = $absolutePath.$photo;
        $sourceFileName = basename($sourceFilePath);

        if (!file_exists($sourceFilePath)) {
            $this->bcms->flash('error', 'Check filename, system can not access it');

            return false;
        }

        $targetDir = dirname($sourceFilePath).'/'.$size;
        $targetFilePath = $targetDir.'/'.$sourceFileName;
        $targetFileRelativePath = dirname($photo).'/'.$size.'/'.$sourceFileName;

        try {
            $img = new \BCMS\SimpleImage($sourceFilePath);

            //Check dir is exists
            if (!file_exists($targetDir)) {
                if (!mkdir($targetDir)) {
                    $this->bcms->flash('error', 'Can not create folder '.$targetDir);
                }
            } elseif (!is_writable($targetDir)) {
                $this->bcms->flash('error', $targetDir.' is not writeable.');
            }

            if ($size === 'small') {
                $img->smart_crop($sizes[$size])->save($targetFilePath);
            } elseif ($size === 'medium') {
                $img->best_fit($sizes[$size], $sizes[$size])->save($targetFilePath);
            } elseif ($size === 'medium_square') {
                $img->smart_crop($sizes[$size])->save($targetFilePath);
            }

            return $targetFileRelativePath;
        } catch (Exception $e) {
            $this->bcms->flash('error', $e->getMessage());
        }
    }
}
