<?php

/**
*
* @author Roman Lokhov <roman@bs1.ru>
* @version 1.0
*/

namespace BCMS;

class Translations
{
    protected $title = 'Translations';
    protected $tableName = 'b_translations';
    protected $alias = 'translations';
    protected $sizes =  array('small' => 80, 'medium_square' => 250, 'medium' => 700);

    protected $bcms;

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function update($item_id, $item_type)
    {
        $req = $this->bcms->request();

        // Delete translations
        $deleteTranslations = \ORM::for_table('b_translations')
          ->where('item_type', $item_type)
          ->where('item_id', $item_id)
          ->delete_many();

        $translations = $req->post('translations');

        foreach ($translations as $lang => $trans) {
            foreach ($trans as $key => $value) {
                if ($key == 'photo' && !empty($value)) {
                        $trans['photo_small'] = $this->makeThumb($value, 'small');
                        $trans['photo_medium'] = $this->makeThumb($value, 'medium');
                        $trans['photo_medium_square'] = $this->makeThumb($value, 'medium_square');
                }
            }

            $translation = \ORM::for_table('b_translations')->create();
            $translation->item_id = $item_id;
            $translation->item_type = $item_type;
            $translation->lang = $lang;
            $translation->data = json_encode($trans);
            $translation->save();
        }
    }

    public function get($item_id, $item_type)
    {
        $translations = \ORM::for_table('b_translations')
          ->where('item_type', $item_type)
          ->where('item_id', $item_id)
          ->find_array();

        $items = array();

        if (!$translations) {
            return false;
        }
        foreach ($translations as $translation) {
            $items[$translation['lang']] = json_decode($translation['data'], true);
        }

        return $items;
    }

    public function delete($item_id, $item_type)
    {
        $translations = \ORM::for_table('b_translations')
          ->where('item_type', $item_type)
          ->where('item_id', $item_id)
          ->find_many();

        foreach ($translations as $item) {
            $item->delete();
        }
    }

    public function translate($item, $item_type, $language)
    {
        if ($language == 'en') {
            return $item;
        }

        $req = $this->bcms->request();

        $translation = \ORM::for_table('b_translations')
          ->where('item_type', $item_type)
          ->where('item_id', $item['id'])
          ->where('lang', $language)
          ->find_one();

        if (!$translation) {
            return $item;
        }

        $replaces = json_decode($translation->data, true);

        if (!is_array($replaces)) {
            return $item;
        }

        foreach ($replaces as $k => $v) {
            if ($v) {
                $item[$k] = $v;
            }
        }

        return $item;
    }
    private function makeThumb($photo, $size = 'small')
    {
        if (!$photo) {
            return false;
        }

        $sizes = $this->sizes;

        $absolutePath = $this->bcms->home_dir;
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
            } elseif (!is_writable($targetDir)) {
                $this->bcms->flash('error', $targetDir.' is not writeable.');
            }

            if ($size === 'small') {
                $img->smart_crop($sizes[$size])->save($targetFilePath);
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
}
