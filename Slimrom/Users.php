<?php

/**
* UsersController
*
* Class for working with users
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS;

class Users extends Item
{
    protected $title = 'Users';
    protected $tableName = 'b_users';
    protected $alias = 'users';
    protected $adminUrl = '/admin/users';
    protected $perPage = 30;

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function defaultAction()
    {
        $bcms = $this->bcms;
        $req = $bcms->request();
        $view = $bcms->view();
        $users = $this->getMany();
        $this->bcms->view()->appendData(array('users' => $users));
        $bcms->render('@admin/'.$this->alias.'/list.twig');
    }

    public function get($id)
    {
        $item = \ORM::for_table($this->tableName)->find_one($id);
        //->join('b_users_profiles', array($this->tableName.'.id', '=', 'b_users_profiles.user_id'))

        if (!$item) {
            return false;
        }

        return $item;
    }

    public function getUserVideos($id)
    {
        $userVideos = array();
        $rels = \ORM::for_table('b_users_videos')->where('user_id', $id)->find_array();

        foreach ($rels as $rel) {
            $userVideos[] = $rel['video_id'];
        }

        return $userVideos;
    }

    public function getUserPhotos($id)
    {
        $userItems = array();
        $rels = \ORM::for_table('b_users_photos')->where('user_id', $id)->find_array();

        foreach ($rels as $rel) {
            $userItems[] = $rel['photo_id'];
        }

        return $userItems;
    }

    public function getMany()
    {
        $req = $this->bcms->request();

        $items = \ORM::for_table($this->tableName);

        if ($req->get('group')) {
            $group = $req->get('group');
            $items = $items->where_in('group', $group);
            $this->bcms->view()->appendData(array('currentGroup' => $group));
        }

          // Search
          $query = $req->get('query');

        if ($query) {
            $items = $items->where_like('email', "%$query%");
            $this->bcms->view()->appendData(array('query' => $query));
        }

        // Ordering
        if ($req->get('order_by')) {
            $orderBy = $req->get('order_by');
            $items = $items->order_by_asc($orderBy);
            $this->bcms->view()->appendData(array('order_by' => $orderBy));
        } else {
            $items = $items->order_by_desc('date_created');
        }

        $items = $items->left_outer_join('b_users_profiles', array($this->tableName.'.id', '=', 'b_users_profiles.user_id'));

        //Pagination
        $pagination = new Pagination($this->bcms);
        $pagination->setLimit($this->perPage);
        $pagination->setCount($items->count());

        $items =  $items->limit($this->perPage)->offset($pagination->start);
        $items =  $items->find_array();

        if (!$items) {
            return false;
        }

        return $items;
    }

    public function add()
    {
        $req = $this->bcms->request();

        if ($req->isGet()) {
            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $item = $this->save();
            $this->bcms->redirect('/admin/'.$this->alias);
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

            $profile = \ORM::for_table('b_users_profiles')->where('user_id', $item['id'])->find_one();
            if ($profile) {
                $profile = $profile->as_array();
                $item = array_merge($item, $profile);
            }

            $this->bcms->view()->appendData(array('item' => $item));
            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $item = $this->save($id);
            $this->bcms->redirect('/admin/'.$this->alias);
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
            $item->set_expr('date_created', "now()");
            $item->email = $req->post('email');
        }

        if ($req->post('username')) {
            $item->username = $req->post('username');
        }

        if ($req->post('password')) {
            $item->password = sha1($req->post('password'));
        }
        $item->group = $req->post('group');
        $item->status = $req->post('status');

        $item->save();

        $this->saveProfile($item->id());

        $this->bcms->flash('success', 'Item saved');

        return $item;
    }

    public function saveProfile($userId)
    {
        $req = $this->bcms->request();

        $profile = \ORM::for_table('b_users_profiles')->where('user_id', $userId)->find_one();
        if (!$profile) {
            $profile = \ORM::for_table('b_users_profiles')->create();
            $profile->user_id = $userId;
        }
        $profile->firstname = $req->post('firstname');
        $profile->lastname = $req->post('lastname');
        $profile->photo = $req->post('photo');
        $profile->balance = $req->post('balance');
        $profile->save();
    }

    public function getProfile($userId)
    {
        $profile = \ORM::for_table('b_users_profiles')->where('user_id', $userId)->find_one();

        return $profile;
    }

    public function delete($id)
    {
        $item = $this->get($id);

        if ($item) {
            $item->delete();
        }

        $this->bcms->flash('info', 'Item deleted');
        $this->goHome();
    }

    public function block($id)
    {
        $item = $this->get($id);

        if ($item) {
            $item->status = 0;
            $item->save();
        }

        $this->bcms->flash('info', 'Item blocked');
    }

    public function unblock($id)
    {
        $item = $this->get($id);

        if ($item) {
            $item->status = 1;
            $item->save();
        }

        $this->bcms->flash('info', 'Item unblocked');
    }

    public function bulk()
    {
        $req = $this->bcms->request();
        $params = $req->params();

        $items = array_keys($params['items']);
        $action = $params['action'];

        foreach ($items as $item) {
            if ($action === 'delete') {
                $this->delete($item);
            }
            if ($action === 'block') {
                $this->block($item);
            }
            if ($action === 'unblock') {
                $this->unblock($item);
            }
        }

        $this->goHome();
    }

    public function goHome()
    {
        $this->bcms->redirect('/admin/'.$this->alias);
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
