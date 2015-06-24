<?php

/**
* Pagination
*
* generates pagination
* @version 1.0
*/

namespace BCMS;

class Pagination
{
    protected $bcms;
    public $start = 0;
    public $limit = 10;
    public $page = 1;
    public $query;
    public $count;
    public $total;

    public function __construct($di)
    {
        $this->bcms = $di;

        $req = $this->bcms->request();

        $this->setQuery();

        $this->page = intval($req->get('p'));
        $this->page = $this->page ? $this->page : 1;

        $this->start = ($this->page - 1) * $this->limit;
    }

    private function setQuery()
    {
        $q = $_GET;

        if (!empty($q['p'])) {
            unset($q['p']);
        }

        $curQueryStr = http_build_query($q);
        $curQueryStr = $curQueryStr ? '?'.$curQueryStr.'&' : '?';

        $this->query = $curQueryStr;
    }

    public function setLimit($limit)
    {
        $this->limit = $limit;
        $this->start = ($this->page - 1) * $this->limit;
    }

    public function setCount($count)
    {
        $this->count = $count;
        $this->total = ceil($this->count/$this->limit);
        $this->addToView();
    }

    public function addToView()
    {
        $pagination = array(
                          'total' => $this->total,
                          'current' => $this->page,
                          'query' => $this->query,
                        );
        $this->bcms->view()->appendData(array('pagination' => $pagination));
    }
}
