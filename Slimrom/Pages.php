<?php

/**
* Pages
*
* Class for working with pages
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS;

class Pages extends Item
{
    protected $title = 'Pages';
    protected $tableName = 'b_pages';
    protected $alias = 'pages';
    protected $startUrl = '';
    protected $adminUrl = '/admin/pages';

    public function breadcrumbs($url)
    {
        $breadcrumbs = array();

        $item = \ORM::for_table($this->tableName)->where('url', $url)->find_one();

        if (!$item) {
            return false;
        }

        $breadcrumbs[] = $item->as_array();

        while ($item = \ORM::for_table($this->tableName)->where('id', $item->parent_id)->find_one()) {
            $breadcrumbs[] = $item->as_array();
        }
        $breadcrumbs = array_reverse($breadcrumbs);

        return empty($breadcrumbs) ? false : $breadcrumbs;
    }

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

        $item = $item->as_array();

      //Translate
      $item = $this->bcms->translations->translate($item, $this->alias, $this->bcms->locale);

        $template = $item['template'] ? $item['template'] : 'default.twig';

        $this->bcms->render('pages/'.$template, array('page' => $item));
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

            $translations = $this->bcms->translations->get($item['id'], $this->alias);
            $item['translations'] = $translations;

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

        $this->bcms->translations->update($item->id(), $this->alias);

        $this->bcms->flash('success', 'Item saved');
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

            // Translate
            $items[$i] = $this->bcms->translations->translate($items[$i], $this->alias, $this->bcms->locale);

            $items[$i]['active'] = false;

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
        }

        return $items;
    }
}
