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

class Gallery extends \BCMS\Item
{
    protected $title = 'Gallery';
    protected $tableName = 'b_gallery';
    protected $alias = 'gallery';
    protected $startUrl = '/media/photo';
    protected $adminUrl = '/admin/gallery-gallery';
    protected $perPage = 30;
    protected $bcms;

    protected $sizes =  array('small' => 65, 'medium_square' => 250, 'medium' => 700);

    public function __construct(\Slim\Slim $di)
    {
        $this->bcms = $di;
    }

    public function defaultAction()
    {
        $req = $this->bcms->request();
        $categoryId = $req->get('category_id');
        $this->bcms->view()->appendData(array('selectedCategory' => $categoryId));

        $items = $this->getMany($categoryId, 0);
        $this->bcms->view()->appendData(array('items' => $items));

        $categories = $this->bcms->gallerycategories->menuTree();
        $this->bcms->view()->appendData(array('categories' => $categories));

        if ($this->bcms->request()->isAjax()) {
            $this->bcms->render('@admin/'.$this->alias.'-select.twig');
        } else {
            $this->bcms->render('@admin/'.$this->alias.'/list.twig');
        }
    }

    public function findByUrl($parts = array())
    {
        $slug = $parts;

        if (count($parts) > 1) {
            $slug = array_pop($parts);
        }

        $category_url = '/'.implode('/', $parts);
        $category = $this->bcms->gallerycategories->getByUrl($category_url);
        //print_r($category);

        if ($category) {
            $category_id = $category->id;
        }

        $slug = implode('', $slug);

        $item = \ORM::for_table($this->tableName)
         ->where('slug', $slug);

        if ($category) {
            $item = $item->where('category_id', $category_id);
        } else {
            $item = $item->where('category_id', 0);
        }

        $item = $item->find_one();

        if (empty($item)) {
            $this->bcms->notFound();
        }

        $photos = \ORM::for_table('b_gallery_photos')
        ->where('gallery_id', $item['id'])
        ->order_by_asc('order')
        ->find_array();

        $item = $this->bcms->translations->translate($item, $this->alias, $this->bcms->locale);

        $template = 'photos.twig';

        $this->bcms->render('pages/'.$template, array('page' => $item, 'gallery' => $item, 'photos' => $photos));
    }

    public function get($id)
    {
        $item = \ORM::for_table($this->tableName)->where('id', $id)->find_one();

        return $item;
    }

    public function getPhoto($id)
    {
        $id = intval($id);
        $item = \ORM::for_table('b_gallery_photos')->where('id', $id)->find_one($id);

        if (!$item) {
            return false;
        }

        $item = $item->as_array();

        return $item;
    }

    public function add()
    {
        $req = $this->bcms->request();

        if ($req->isGet()) {
            $categories = $this->bcms->gallerycategories->menuTree();

            $view = $this->bcms->view();
            $view->appendData(array('categories' => $categories));

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

            $categories = $this->bcms->gallerycategories->menuTree();
            $item = $item->as_array();

            $photos = \ORM::for_table('b_gallery_photos')
            ->where('gallery_id', $item['id'])
            ->order_by_asc('order')
            ->find_array();

            $view = $this->bcms->view();
            $view->appendData(array('item' => $item, 'categories' => $categories, 'photos' => $photos));

            $this->bcms->render('@admin/'.$this->alias.'/form.twig');
        } elseif ($req->isPost()) {
            $req = $this->bcms->request();

            $photos = $req->post('photos');

            if (!empty($photos)) {
                foreach ($photos as $key => $item) {
                    $photo = \ORM::for_table('b_gallery_photos')->find_one($key);
                    if ($photo) {
                        $photo->order = intval($item['order']);
                        $photo->description = $item['description'];
                        $photo->save();
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

        $item->description = $req->post('description');
        $item->text = $req->post('text');
        $item->title = $req->post('title') ? $req->post('title') : $req->post('name');
        $item->keywords = $req->post('keywords');

        $item->url = $this->buildUrl($item->slug, $item->category_id);

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

        $this->bcms->translations->update($item->id(), $this->alias);

        $this->bcms->flash('success', 'Item saved');

        return $item;
    }

    public function getMany($categoryId = 0, $only_visible = 1, $pages = true, $max = 0)
    {
        $items = \ORM::for_table($this->tableName);

        if (is_array($categoryId)) {
            $items = $items->where_in('category_id', $categoryId);
        } elseif ($categoryId > 0) {
            $items = $items->where('category_id', $categoryId);
        } elseif ($categoryId == 0) {
            $items = $items->where('category_id', 0);
        }

        // Search
        $query = $this->bcms->request()->get('query');
        $query = preg_replace('/[^A-Za-zА-Яа-я0-9-.]/', '', $query);

        if ($query) {
            $items = $items->where_like('name', '%'.$query.'%');
            $this->bcms->view()->appendData(array('query' => $query));
        }

        // Ordering
        $sortBy = $this->bcms->request()->get('sort_by');

        if ($sortBy) {
            $items = $items->order_by_asc($sortBy);
            $this->bcms->view()->appendData(array('sort_by' => $sortBy));
        }

        if ($pages) {
            //Pagination
            $pagination = new \BCMS\Pagination($this->bcms);
            $pagination->setCount($items->count());

            $items =  $items->limit($this->perPage)->offset($pagination->start);
        }

        $items = $items->order_by_desc('date_created');
        $items =  $items->find_array();

        if (!$items) {
            return false;
        }
        for ($i = 0; $i < count($items); $i++) {
            $items[$i]['url'] = $this->startUrl.$items[$i]['url'];

            $firstPhoto = \ORM::for_table('b_gallery_photos')
            ->where('gallery_id', $items[$i]['id'])
            ->order_by_asc('order')
            ->find_one();

            if (is_object($firstPhoto)) {
                $items[$i]['photo'] = $firstPhoto->as_array();
            }

            //$this->startUrl.$items[$i]['url'];
            $items[$i] = $this->bcms->translations->translate($items[$i], $this->alias, $this->bcms->locale);
        }

        return $items;
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
            $photos = \ORM::for_table('b_gallery_photos')->where('gallery_id', $item['id'])->order_by_asc('order')->find_array();

            foreach ($photos as $photo) {
                $this->deletephoto($photo['id']);
            }

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

   // Build URL path for a page
   private function buildUrl($item_url, $category_id)
   {
       $category = \ORM::for_table('b_gallery_categories')
                  ->select('id')
                  ->select('url')
                  ->select('name')
                  ->where('id', $category_id)
                  ->find_one();
       if (!$category) {
           $url = '/'.$item_url; //$this->startUrl . '/' .
       } else {
           $url = $category->url.'/'.$item_url;
       }

       return $url;
   }

    public function generateUrls($id = 0)
    {
        $this->bcms->flash('success', 'URL товаров обновлены');
        $items = \ORM::for_table($this->tableName)->select('id')->select('slug')->select('category_id');

        if ($id) {
            $items = $items->where('category_id', $id);
        }

        $items = $items->find_many();

        foreach ($items as $item) {
            $item->url = $this->buildUrl($item->slug, $item->category_id);
            $item->save();
         //echo $item->url.'<br />';
        }
    }

    public function fixSlugs()
    {
        $items = \ORM::for_table($this->tableName)->select('id')->select('slug')->select('category_id');
        $items = $items->find_many();

        foreach ($items as $item) {
            $item->slug = $this->slugify($item->slug);
            $item->save();
         //echo $item->url.'<br />';
        }
    }

    public function createDescriptions()
    {
        $items = \ORM::for_table($this->tableName)->find_many();

        foreach ($items as $item) {
            $text = $item->text;
            $desc = strstr($text, '<table', true);
            $text = str_replace($desc, '', $text);
            $item->description = $desc;
            $item->text = $text;
            $item->save();
        }
    }

    public function copyPhotos()
    {
        set_time_limit(0);
        $items = \ORM::for_table($this->tableName)->find_many();

        foreach ($items as $item) {
            if (!strstr($item->photo, 'http://')) {
                continue;
            }
            $dir = 'upload'.dirname($item->url);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $dest = $dir.'/'.$item->slug.'.jpg';

            echo $dest.'<br>';
            if (copy($item->photo, $dest)) {
                $item->photo = '/'.$dest;
                $item->save();
            }
        }
    }

    public function special($where = 'is_bestseller')
    {
        $items = \ORM::for_table($this->tableName); //->select_many('id', 'name', 'slug', 'url', 'price', 'photo', 'is_visible')

      if ($where == 'is_bestseller') {
          $items = $items->where('is_bestseller', 1);
      } elseif ($where == 'is_recommend') {
          $items = $items->where('is_recommend', 1);
      }

        $items = $items->where('is_visible', 1);
        $items =  $items->order_by_asc('order')->find_array();

        for ($i = 0; $i < count($items); $i++) {
            $items[$i]['url'] = $this->startUrl.$items[$i]['url'];
        }

        return $items;
    }

    public function goHome()
    {
        $this->bcms->redirect($this->adminUrl);
    }

    public function related($id)
    {
        $similarItemsIds = array();
        $similarItems = null;
        $proverkaPX = array();
        $proverkaPX[] = 15370;

        $item = \ORM::for_table($this->tableName)
         ->select_many(array('id', 'similar', 'category_id'))
         ->where('id', $id)
         ->find_one();

        if (!empty($item->similar)) {
            $similarItemsIds = explode(', ', $item->similar);
        }

        if (in_array($item->category_id, array(475, 476, 477, 484))) {
            $similarItemsIds = array_merge($proverkaPX, $similarItemsIds);
        }

        if (!empty($similarItemsIds)) {
            $similarItems = \ORM::for_table($this->tableName)
            ->where_in('id', $similarItemsIds)
            ->order_by_desc('category_id')
            ->find_array();
        }

        if (!$similarItems) {
            return false;
        }
        for ($i = 0; $i < count($similarItems); $i++) {
            $similarItems[$i]['url'] = $this->startUrl.$similarItems[$i]['url'];
        }

        return $similarItems;
    }

    public function similar($id)
    {
        $scatter = 3000;

        $item = \ORM::for_table($this->tableName)
         ->where('id', $id)
         ->find_one();

        $similarItems = \ORM::for_table($this->tableName)
         ->where('category_id', $item->category_id)
         ->where_lt('price', $item->price + $scatter)
         ->where_gt('price', $item->price - $scatter)
         ->where_not_equal('id', $item->id)
         ->limit(9)
         ->find_array();

        if (!$similarItems) {
            return false;
        }
        for ($i = 0; $i < count($similarItems); $i++) {
            $similarItems[$i]['url'] = $this->startUrl.$similarItems[$i]['url'];
        }

        return $similarItems;
    }

    public function search($query)
    {
        $query = mb_strtolower(trim($query), 'UTF-8');

        if (strlen($query) < 3) {
            return false;
        }

        $items = \ORM::for_table('b_gallery_photos')
      ->where_raw('lower(`description`) LIKE ?', '%'.$query.'%')->find_array();
      //where_like('description', '%'.$query.'%')->find_array();

      return $items;
    }

    public function searchGallerys($query)
    {
        $query = mb_strtolower(trim($query), 'UTF-8');

        if (strlen($query) < 3) {
            return false;
        }

        $items = \ORM::for_table('b_gallery')
      ->where_raw('lower(`name`) LIKE ?', '%'.$query.'%')->find_array();

        if (!$items) {
            return false;
        }
        for ($i = 0; $i < count($items); $i++) {
            $items[$i]['url'] = $this->startUrl.$items[$i]['url'];

            $lastPhoto = \ORM::for_table('b_gallery_photos')->where('gallery_id', $items[$i]['id'])->order_by_desc('order')->find_one();

            if (is_object($lastPhoto)) {
                $items[$i]['photo'] = $lastPhoto->as_array();
            }

            $this->startUrl.$items[$i]['url'];
        }

        return $items;
    }

    private function makeThumb($photo, $size = 'small')
    {
        if (!$photo) {
            return false;
        }

        $sizes = $this->sizes;

        $absolutePath = $this->bcms['home_dir'];
        $sourceFilePath = $absolutePath.$photo;
        $sourceFileName = basename($sourceFilePath);

        if (!file_exists($sourceFilePath)) {
            $this->bcms->flash('error', 'Check filename, system can not access it');

            return false;
        }

        $targetDir = dirname($sourceFilePath).'/'.$size;
        $targetFilePath = $targetDir.'/'.$sourceFileName;
        $targetFileRelativePath = dirname($photo).'/'.$size.'/'.$sourceFileName;

        try {
            $img = new \BCMS\SimpleImage($sourceFilePath);

         //Check dir is exists
         if (!file_exists($targetDir)) {
             if (!mkdir($targetDir)) {
                 $this->bcms->flash('error', 'Can not create folder '.$targetDir);
             }
         }
         //if dir exists check is writeble
         elseif (!is_writable($targetDir)) {
             $this->bcms->flash('error', $targetDir.' is not writeable.');
         }

            if ($size === 'small') {
                //$img->smart_crop($sizes[$size])->save($targetFilePath);
            $img->best_fit($sizes[$size], $sizes[$size])->save($targetFilePath);
            } elseif ($size === 'medium') {
                $img->best_fit($sizes[$size], $sizes[$size])->save($targetFilePath);
            } elseif ($size === 'medium_square') {
                $img->smart_crop($sizes[$size])->save($targetFilePath);
            }

            return $targetFileRelativePath;
        } catch (Exception $e) {
            $this->bcms->flash('error', $e->getMessage());
        }
    }

    public function upload()
    {
        //set_time_limit(180);

      $urls = array();
        $req = $this->bcms->request();
        $message = '';

        if (isset($_POST['liteUploader_id']) && $_POST['liteUploader_id'] == 'uploadPhotos') {
            $counter = 0;

            foreach ($_FILES['uploadPhotos']['error'] as $key => $error) {
                $counter++;

                if ($error == UPLOAD_ERR_OK) {
                    $tmp_filename = $_FILES['uploadPhotos']['tmp_name'][$key];
                    $filename = $_FILES['uploadPhotos']['name'][$key];

                    $photoManager = new \BCMS\Gallery\PhotoManager($this->bcms);
                  //$photoManager->orient_image($tmp_filename);

                  $path_parts = pathinfo($filename);
                  //$filename = md5($path_parts['filename']).'.'.$path_parts['extension'];
                  $filename = uniqid().'.'.$path_parts['extension'];
                    $secret_filename = uniqid().'.'.$path_parts['extension'];

                    $gallery_id = $req->post('gallery_id');

                    $dir = $this->bcms->home_dir.'/upload/images/gallery/'.$gallery_id.'/';

                    if (!file_exists($dir)) {
                        mkdir($dir, 0777, true);
                    }

                    $uploadedUrl = $dir.$filename;

                    $log = $this->bcms->getLog();
                    $log->info('Uploading => '.$uploadedUrl);

                    move_uploaded_file($tmp_filename, $uploadedUrl);

                    $file = '/upload/images/gallery/'.$gallery_id.'/'.$filename;
                    $secret_file = '/upload/images/gallery/'.$gallery_id.'/'.$secret_filename;

                    $log = $this->bcms->getLog();

                    $photo = \ORM::for_table('b_gallery_photos')->create();

                    $photo->gallery_id = $gallery_id;

                  // Проверяем есть ли уже фото, если да, то получаем максимальное значение порядка фотографий
                  $lastPhoto = \ORM::for_table('b_gallery_photos')->where('gallery_id', $gallery_id)->order_by_desc('order')->find_one();

                    $order = 0;

                    if ($lastPhoto) {
                        $order = intval($lastPhoto->order);
                    }

                    $order = $order + $counter;

                    $photo->photo = $file;
                    $photo->photo_small = $photoManager->makeThumb($file, 'small');
                    $photo->photo_medium = $photoManager->makeThumb($file, 'medium');

                    rename($dir.$filename, $dir.$secret_filename);
                    $photo->photo = $secret_file;

                    $photo->order = $order;
                    $photo->set_expr('date_created', "NOW()");
                    $photo->save();

                    $urls[] = $photo->photo_small;

                    $message  = 'Файл '.$filename.' успешно загружен';
                }
            }
        }
        $this->bcms->response()->header('Content-Type', 'application/json');
        echo json_encode(
              array(
                      'message' => $message,
                      'urls' => $urls,
              )
      );

        $log = $this->bcms->getLog();
        $log->info(print_r($_POST, true));
    }

    public function deletephoto($id)
    {
        $id = intval($id);
        $bcms = $this->bcms;

        $photo = \ORM::for_table('b_gallery_photos')->find_one($id);
        if ($photo) {
            if (file_exists($this->bcms->home_dir.$photo->photo)) {
                @unlink($this->bcms->home_dir.$photo->photo);
            }

            if (file_exists($this->bcms->home_dir.$photo->photo_small)) {
                @unlink($this->bcms->home_dir.$photo->photo_small);
            }

            if (file_exists($this->bcms->home_dir.$photo->photo_medium)) {
                @unlink($this->bcms->home_dir.$photo->photo_medium);
            }

            $photo->delete();

            echo 'Фото удалено';
        }
    }

    public function getPhotosByIds($ids)
    {
        $photos = \ORM::for_table('b_gallery_photos')->where_in('id', $ids)->order_by_asc('order')->find_array();

        return $photos;
    }
}
