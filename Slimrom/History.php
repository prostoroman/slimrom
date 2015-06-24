<?php

/**
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS;

class History
{
    protected $title = 'Activity';
    protected $tableName = 'b_history';
    protected $alias = 'history';
    protected $startUrl = '/';
    protected $adminUrl = '/admin/history';
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

    public function add(array $data)
    {
        $item = \ORM::for_table($this->tableName)->create();
        $item->set_expr('date_created', "NOW()");

        $item->title = $data['title'];
        $item->link = $data['link'];
        $item->where = $data['where'];
        $item->css_class = $data['css_class'];

        $item->save();
    }

    public function get_many($day = null)
    {
        $items = \ORM::for_table($this->tableName);

        // Search
        $query = $this->bcms->request->get('query');
        $query = filter_var($query, FILTER_SANITIZE_STRING);

        if ($query) {
            $items = $items->where_like('title', '%'.$query.'%');
            $this->bcms->view->appendData(array('query' => $query));
        }
        // Where
        $where = $this->bcms->request->get('where');
        $where = filter_var($where, FILTER_SANITIZE_STRING);

        if ($where) {
            $items = $items->where_like('where', $where.'%');
            $this->bcms->view->appendData(array('where' => $where));
        }
        // day
        if (!$day) {
            $day = $this->bcms->request->get('day');
            $day = filter_var($day, FILTER_SANITIZE_STRING);
        }

        if ($day) {
            $items = $items->where_gte('date_created', $day);
            $nextDayTime = strtotime($day) + 60*60*24;
            $items = $items->where_lt('date_created', date('Y-m-d', $nextDayTime));
            $this->bcms->view->appendData(array('day' => $day));
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

        return $item->content;
    }
    public function getTitle()
    {
        return $this->title;
    }
}
