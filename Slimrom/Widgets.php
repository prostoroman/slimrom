<?php

/**
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS;

class Widgets extends Item
{
    protected $title = 'Widgets';
    protected $tableName = 'b_widgets';
    protected $alias = 'widgets';
    protected $startUrl = '/widgets';
    protected $adminUrl = '/admin/widgets';
    protected $bcms;

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function defaultAction()
    {
        $req = $this->bcms->request();

        $items = $this->get_many();
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

            $redirectUrl = '/admin/'.$this->alias.'/edit/'.$item->id;

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
            $redirectUrl = '/admin/'.$this->alias.'/edit/'.$item->id;
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
            $item->set_expr('date_created', "NOW()"); // sqlite datetime('now')
        }

        $item->title = $req->post('title');
        $item->content = $req->post('content');
        $item->date_expire = $req->post('date_expire');

        $item->is_active = $req->post('is_active') ? 1 : 0;

        $item->save();

        $this->bcms->translations->update($item->id(), $this->alias);

        $this->bcms->flash('success', 'Item saved');

        return $item;
    }

    public function get_many($onlyActive = false)
    {
        $items = \ORM::for_table($this->tableName);

        if ($onlyActive) {
            $items = $items->where('is_active', 1);
        }
        $items = $items->order_by_desc('date_created');
        $items =  $items->find_array();

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
        }

        if ($this->bcms->request()->isAjax()) {
            echo $this->bcms['translate']('Item deleted');
        } else {
            $this->bcms->flash('info', 'Item deleted');
            $this->goHome();
        }
    }

    public function getContent($id)
    {
        $item = $this->get($id);

        if (!$item) {
            return false;
        }

        $item = $item->as_array();
        if ($item['is_active'] == 0) {
            return '';
        }

      //Translate
      $item = $this->bcms->translations->translate($item, $this->alias, $this->bcms->locale);

        return $item['content'];
    }
}
