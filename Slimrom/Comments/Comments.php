<?php

/**
* Pages
*
* Class for working with pages
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS\Comments;

class Comments extends \BCMS\Item
{
    protected $title = 'Comments';
    protected $tableName = 'b_comments';
    protected $alias = 'comments';
    protected $startUrl = '/comments';
    protected $adminUrl = '/admin/comments-comments';
    protected $perPage = 50;
    protected $bcms;

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function defaultAction()
    {
        $req = $this->bcms->request();
        $categoryId = $req->get('category_id');
        $this->bcms->view->appendData(array('selectedCategory' => $categoryId));

        $items = $this->get_many($categoryId, 0);
        $this->bcms->view->appendData(array('items' => $items));

        $this->bcms->render('@admin/'.$this->alias.'/list.twig');
    }

   /**
   * Find pages by url-parts array
   *
   * @param array $parts parts of url
   * @return object
   */

    public function findByUri($uri)
    {
        $items = \ORM::for_table($this->tableName)
            ->select($this->tableName.'.*')
            ->select('b_users.email', 'user_email')
            ->select('b_users_profiles.firstname', 'user_firstname')
            ->select('b_users_profiles.lastname', 'user_lastname')
            ->select('b_users_profiles.photo', 'user_photo')
            ->join('b_users', array($this->tableName.'.user_id', '=', 'b_users.id'))
            ->join('b_users_profiles', array($this->tableName.'.user_id', '=', 'b_users_profiles.user_id'))
            ->where('uri', $uri)
            //->where('is_approved', 1)
            ->order_by_asc('date_created')
            ->find_array();

        if (empty($items)) {
            return false;
            $this->bcms->notFound();
        }

        return $items;
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

            $item = $item->as_array();

            $this->bcms->view->appendData(array('item' => $item));
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

        $item->url = $this->build_url($item->slug, $item->category_id);

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

        $this->bcms->flash('success', 'Item saved');

        return $item;
    }

    public function get_many($userId = 0, $only_visible = 1, $pages = true)
    {
        $items = \ORM::for_table($this->tableName)
                ->select($this->tableName.'.*')
                ->select('b_users.email', 'user_email')
                ->select('b_users_profiles.firstname', 'user_firstname')
                ->select('b_users_profiles.lastname', 'user_lastname')
                ->select('b_users_profiles.photo', 'user_photo')
                ->join('b_users', array($this->tableName.'.user_id', '=', 'b_users.id'))
                ->join('b_users_profiles', array($this->tableName.'.user_id', '=', 'b_users_profiles.user_id'));



        $userId = $this->bcms->request->get('user_id');

        if ($userId) {
            $items = $items->where('user_id', $userId);
        }

        // Search
        $query = $this->bcms->request->get('query');

        if ($query) {
            $items = $items->where_like('content', '%'.$query.'%');
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

        return $items;
    }

    public function saveCommentContent()
    {
        $req = $this->bcms->request;
        $id = intval($req->post('pk'));

        if (!is_null($id)) {
            $item = $this->get($id);

            if (!$item) {
                $this->bcms->response->status(400);
                $this->bcms->stop('Item not found');
            }
        }

        $item->content = $req->post('value');
        $item->save();

        echo $item->content;
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
            echo 'Item deleted';
        } else {
            $this->bcms->flash('info', 'Item deleted');
            $this->goHome();
        }
    }

    public function goHome()
    {
        $this->bcms->redirect($this->adminUrl);
    }
}
