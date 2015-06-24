<?php

/**
* OptionsController
*
* Class for working with options
* @version 1.0
*/

namespace BCMS;

class Options extends CRUD
{
    protected $options = array();
    protected $db_table = '';

    public function __construct($di)
    {
        $this->bcms = $di;
        $this->db_table = 'b_options';

        $options = \ORM::for_table($this->db_table)->find_array();
        foreach ($options as $option) {
            $this->options[$option['name']] = array('value' => $option['value'], 'description' => $option['description']);
        }
    }

    public function defaultAction()
    {
        $bcms = $this->bcms;
        $req = $bcms->request;

        if ($req->isGet()) {
            $page['title'] = 'Options';
            $this->bcms->view->appendData(array('options' => $this->options));
            $bcms->render('@admin/options/options.twig');
        } elseif ($req->isPost()) {
            $postVars = $req->post();

            if (empty($postVars)) {
                $bcms->flash('info', 'Nothing changed.');
            } else {
                foreach ($postVars as $key => $value) {
                    $option = $this->edit($key, $value);
                }

                $bcms->flash('success', 'Options saved');
            }

            $bcms->redirect('/admin/options');
        }
    }

    public function __get($name)
    {
        $option = $this->options[$name];

        return $option['value'];
    }

    public function __isset($name)
    {
        if (array_key_exists($name, $this->options)) {
            return true;
        }

        return false;
    }

    public function add()
    {
        $bcms = $this->bcms;
      // Get request object
      $req = $bcms->request();

        if (!$req->post('name')) {
            $bcms->flash('error', 'Option name is required');
        } else {
            $option = \ORM::for_table($this->db_table)->create();
            $option->name = $req->post('name');
            $option->value = $req->post('value');
            $option->description = $req->post('description');
            $option->save();
            $bcms->flash('success', 'Option is added.');
        }

        $bcms->redirect('/admin/Options');
    }

    public function edit($name, $value)
    {
        if (!$name) {
            return;
        }
        $option = \ORM::for_table($this->db_table)->where('name', $name)->find_one();
        $option->value = $value;
        $option->save();
    }

    public function all()
    {
        return $this->options;
    }

    public function delete($name)
    {
        $bcms = $this->bcms;

        $option = \ORM::for_table($this->db_table)->where('name', $name)->find_one();

        $result = $option->delete();

        $bcms->flash('success', 'Option is deleted.');
        $bcms->redirect('/admin/Options');
    }
}
