<?php

/**
* Pages
*
* Class for working with pages
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS\Gallery;

class Categories extends \BCMS\Pages
{
    protected $title = 'Categories';
    protected $tableName = 'b_gallery_categories';
    protected $alias = 'gallerycategories';
    protected $startUrl = '/media/photo';
    protected $adminUrl = '/admin/gallery-categories';

    public function findByUrl($parts = array())
    {
        $url = '/'.implode('/', $parts);

        $item = $this->getByUrl($url);

        if (empty($item)) {
            return false;
        }
      //Redirection
      if ($item->redirect_url) {
          $this->bcms->redirect($item->redirect_url);
      }

        $this->bcms->view()->appendData(array('currentCategory' => $item));

      //photos
      $photos = $this->bcms->gallery->getMany($item->id, 1);

      //photos from sub
      if (empty($photos)) {
          $subCategories = \ORM::for_table($this->tableName)
                           ->select('id')
                           ->where('parent_id', $item->id)
                           ->find_array();

          if (!empty($subCategories)) {
              foreach ($subCategories as $c) {
                  $cats[] = $c['id'];
              }
              $photos = $this->bcms->gallery->getMany($cats);
          }
      }

        $template = $item->template ? $item->template : 'photos.twig';

        $this->bcms->render('pages/'.$template, array('page' => $item, 'photos' => $photos));
    }

    public function getByUrl($url)
    {
        $item = \ORM::for_table($this->tableName)->where('url', $url)->find_one();

        return empty($item) ? false : $item;
    }

    public function delete($id)
    {
        $this->deleteRecursive($id);
        $this->goHome();
    }

    public function deleteRecursive($id)
    {
        $item = $this->get($id);

      //Delete category photos
      $photos = \ORM::for_table('b_gallery')->where('category_id', $id)->find_many();
        foreach ($photos as $item) {
            $item->delete();
            $this->bcms->flash('info', 'Product '.$item->id.' deleted.');
        }

        $item->delete();
        $this->fixOrder($item->parent_id);
        $this->bcms->flash('info', 'Item '.$item->id.' deleted.');

        $subCategories = \ORM::for_table($this->tableName)->select('id')->where('parent_id', $id)->find_array();

        foreach ($subCategories as $subCat) {
            $this->deleteRecursive($subCat['id']);
        }
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
                  ->find_many();

        foreach ($items as $item) {
            if (strpos($url, $item->url) !== false) {
                $item->is_active = 1;
            } //$item->url == $url
         $item->url = $this->startUrl.$item->url;
            $item->photosCount =  \ORM::for_table('b_gallery')->where('category_id', $item->id)->where('is_visible', 1)->count();
        }

        return $items;
    }
    public function save($id = null)
    {
        $req = $this->bcms->request();

        if (!is_null($id)) {
            $item = $this->get($id);

            if (!$item) {
                $this->bcms->flash('error', 'Item is not found;');
            }
            $item->set_expr('date_changed', "now()");
        } else {
            $item = \ORM::for_table($this->tableName)->create();
            $item->set_expr('date_created', "NOW()"); // sqlite datetime('now')
         $item->set_expr('date_changed', "now()");
        }

        if ($id and (!$req->post('name') or !$req->post('slug')) and $item->url != '/') {
            $this->bcms->flash('error', 'Name or url is not defined.');
            $this->goHome();
        }

        $item->parent_id = $req->post('parent_id') ? $req->post('parent_id') : 0;
        $item->is_visible = $req->post('is_visible') ? 1 : 0;
        $item->name = $req->post('name');
        $item->slug = (!$req->post('slug') && $item->url != '/') ? $req->post('name') : $req->post('slug');
        $item->text = $req->post('text');
        $item->template = $req->post('template');
        $item->title = $req->post('title') ? $req->post('title') : $req->post('name');
        $item->keywords = $req->post('keywords');
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
            $this->bcms->flash('error', 'Item with the same URL already exists.');
            $this->goHome();
        }

        $item->save();

        $this->generateUrls($item->parent_id);
        $this->bcms->gallery->generateUrls();
        $this->fixOrder($item->parent_id);
        $this->hasChilds();

        $this->bcms->flash('success', 'Success! Item is saved.');
    }

    public function getAll()
    {
        $items = \ORM::for_table($this->tableName)->find_array();

        return $items;
    }
}
