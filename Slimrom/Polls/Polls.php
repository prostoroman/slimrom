<?php

/**
* Pages
*
* Class for working with pages
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS\Polls;

class Polls extends \BCMS\Item
{
    protected $title = 'Polls';
    protected $tableName = 'b_polls';
    protected $alias = 'polls';
    protected $startUrl = '/polls';
    protected $adminUrl = '/admin/polls-polls';
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

            // translations for question
            $translations = $this->bcms->translations->get($item['id'], $this->alias);
            $item['translations'] = $translations;

            $answers = \ORM::for_table('b_polls_answers')
            ->where('poll_id', $item['id'])
            ->order_by_asc('order')
            ->find_array();

            /* translations for answers
            foreach ($answers as $key => $answer) {
                $answers[$key]['translations'] = $this->bcms->translations->get($item['id'], 'answers');
            }
            */

            $view = $this->bcms->view();
            $view->appendData(array('item' => $item, 'answers' => $answers));

            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $req = $this->bcms->request();

            $add_answers = $req->post('add_answers');

            if (!empty($add_answers)) {
                $add_answers = explode(PHP_EOL, $add_answers);
                $c = 0;
                $lastAnswer = \ORM::for_table('b_polls_answers')
                ->where('poll_id', $id)
                ->order_by_desc('order')
                ->find_one();

                if ($lastAnswer) {
                    $c = $lastAnswer->order;
                }

                foreach ($add_answers as $title) {
                    $title = trim($title);

                    if ($title) {
                        $c++;
                        $item = \ORM::for_table('b_polls_answers')->create();
                        $item->title = $title;
                        $item->poll_id = $id;
                        $item->order = $c;
                        $item->save();
                    }
                }
            }

            $answers = $req->post('answers');

            if (!empty($answers)) {
                foreach ($answers as $key => $item) {
                    $answer = \ORM::for_table('b_polls_answers')->find_one($key);
                    if ($answer) {
                        $answer->order = intval($item['order']);
                        $answer->title = $item['title'];
                        $answer->save();
                    }
                }
            }

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
            $item->set_expr('date_created', "NOW()"); // sqlite datetime('now')
        }

        $item->title = $req->post('title');
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
            $this->bcms->translations->delete($id, $this->alias);
        }

        if ($this->bcms->request()->isAjax()) {
            echo 'Item deleted';
        } else {
            $this->bcms->flash('info', 'Item deleted');
            $this->goHome();
        }
    }

    public function deleteanswer($id)
    {
        $id = intval($id);

        $item = \ORM::for_table('b_polls_answers')->find_one($id);
        $item->delete();
    }

    public function vote($id)
    {
        $item = \ORM::for_table('b_polls_answers')->find_one($id);

        if (!$item) {
            return false;
        }

        $bcms = $this->bcms;
        $cookie = $bcms['app']->getCookie('poll_'.$item->poll_id);

        if ($cookie != "voted") {
            $bcms['app']->setCookie('poll_'.$item->poll_id, 'voted', '1000 days');
            $item->votes = $item->votes + 1;
            $item->save();
        }
    }

    public function show($id = 0)
    {
        if (!$id) {
            $item = \ORM::for_table('b_polls')
            ->where('is_active', 1)
            ->order_by_desc('date_created')
            ->find_one();
        } else {
            $item = $this->get($id);
        }

        $poll = $item->as_array();
        $poll = $this->bcms->translations->translate($poll, $this->alias, $this->bcms->locale);

        if (!$item) {
            return false;
        }

        $answers = \ORM::for_table('b_polls_answers')
        ->where('poll_id', $poll['id'])
        ->order_by_asc('order')
        ->find_array();

        $totalVotes = 0;

        foreach ($answers as $answer) {
            $totalVotes = $totalVotes + $answer['votes'];
        }

        foreach ($answers as $key => $answer) {
            if ($totalVotes) {
                $answers[$key]['percent'] = round($answer['votes'] / $totalVotes * 100);
            } else {
                $answers[$key]['percent'] = 0;
            }
            if (!empty($poll['answers']) && !empty($poll['answers'][$answers[$key]['id']]['title'])) {
                $answers[$key]['title'] = $poll['answers'][$answers[$key]['id']]['title'];
            }
        }

        $poll['answers'] = $answers;
        $poll['totalVotes'] = $totalVotes;

        $cookie = $this->bcms->getCookie('poll_'.$poll['id']);

        if ($cookie) {
            $poll['voted'] = true;
        } else {
            $poll['voted'] = false;
        }

        $this->bcms->view()->appendData(array('poll' => $poll));
    }

    public function showMany()
    {
        $items = $this->getMany(true);

        if (!$items) {
            return false;
        }

        $polls = array();

        foreach ($items as $poll) {
            // translate question
            $poll = $this->bcms->translations->translate($poll, $this->alias, $this->bcms->locale);

            $answers = \ORM::for_table('b_polls_answers')
            ->where('poll_id', $poll['id'])
            ->order_by_asc('order')
            ->find_array();

            $totalVotes = 0;

            foreach ($answers as $answer) {
                $totalVotes = $totalVotes + $answer['votes'];
            }

            foreach ($answers as $key => $answer) {
                if ($totalVotes) {
                    $answers[$key]['percent'] = round($answer['votes'] / $totalVotes * 100);
                } else {
                    $answers[$key]['percent'] = 0;
                }
                // translate answers
                if (!empty($poll['answers']) && !empty($poll['answers'][$answers[$key]['id']]['title'])) {
                    $answers[$key]['title'] = $poll['answers'][$answers[$key]['id']]['title'];
                }
            }

            $poll['answers'] = $answers;
            $poll['totalVotes'] = $totalVotes;

            $cookie = $this->bcms->getCookie('poll_'.$poll['id']);

            if ($cookie) {
                $poll['voted'] = true;
            } else {
                $poll['voted'] = false;
            }

            $polls[] = $poll;
        }

        $this->bcms->view()->appendData(array('polls' => $polls));
    }
}
