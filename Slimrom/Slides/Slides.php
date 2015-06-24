<?php

/**
* Pages
*
* Class for working with pages
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS\Slides;

class Slides extends \BCMS\Item
{
    protected $title = 'Slides';
    protected $tableName = 'b_slides';
    protected $alias = 'slides';
    protected $startUrl = '/slides';
    protected $adminUrl = '/admin/slides-slides';
    protected $bcms;

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function defaultAction()
    {
        $req = $this->bcms->request();

        $items = $this->getMany();
        $this->bcms->view()->appendData(array('items' => $items));

        $this->bcms->render('@admin/'.$this->alias.'/list.twig');
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
            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $item = $this->save();

            $redirectUrl = $this->adminUrl.'/edit/'.$item->id;

            $this->bcms->redirect($redirectUrl);
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

            $item = $item->as_array();

            $translations = $this->bcms->translations->get($item['id'], $this->alias);
            $item['translations'] = $translations;

            $view = $this->bcms->view();

            $view->appendData(array('item' => $item));

            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $req = $this->bcms->request();

            $item = $this->save($id);
            $redirectUrl = $this->adminUrl.'/edit/'.$item->id;
            $this->bcms->redirect($redirectUrl);
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
        } else {
            $item = \ORM::for_table($this->tableName)->create();
        }

        $item->title = $req->post('title');
        $item->content = $req->post('content');
        $item->order = $req->post('order') ? $req->post('order') : \ORM::for_table($this->tableName)->max('order') + 1;
        $item->is_active = $req->post('is_active') ? 1 : 0;

        $item->save();

        $this->bcms->translations->update($item->id(), $this->alias);

        $this->bcms->flash('success', 'Item saved');

        return $item;
    }

    public function getMany($onlyActive = false)
    {
        $items = \ORM::for_table($this->tableName);

        if ($onlyActive) {
            $items = $items->where('is_active', 1);
        }
        $items = $items->order_by_asc('order');
        $items =  $items->find_array();

        foreach ($items as $key => $item) {
            $items[$key] = $this->bcms->translations->translate($items[$key], $this->alias, $this->bcms->locale);
        }

        if (!$items) {
            return false;
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

    public function getContent($id)
    {
        $item = $this->get($id);
        $item = $item->as_array();

        $item = $this->bcms->translations->translate($item, $this->alias, $this->bcms->locale);

        if (!$item) {
            return false;
        }

        return $item->content;
    }
}
