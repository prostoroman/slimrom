<?php
/*

  The PHP SimpleImage class - v2

    By Cory LaViska for A Beautiful Site, LLC. (http://www.abeautifulsite.net/)

  License:

    This software is dual-licensed under the GNU General Public License and
    the MIT License and is copyright A Beautiful Site, LLC.

*/

namespace BCMS\Gallery;

class PhotoManager
{
    protected $sizes =  array('small' => 200, 'medium_square' => 250, 'medium' => 600);
    protected $tableName = 'undefined';
    protected $alias = 'undefined';
    protected $startUrl = 'undefined';

    protected $bcms;

    public function __construct(\Slim\Slim $di)
    {
        $this->bcms = $di;
    }

    public function makeThumb($photo, $size = 'small')
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

    public function orient_image($file_path)
    {
        $exif = @exif_read_data($file_path, 0, true);
        if ($exif === false) {
            return false;
        }

        if (empty($exif['IFD0'])) {
            return false;
        }

        $orientation = intval(@$exif['IFD0']['Orientation']);
        if ($orientation < 2 || $orientation > 8) {
            return false;
        }

        $image = imagecreatefromjpeg($file_path);
        switch ($orientation) {
            case 2:
                $image = $this->imageflip(
                    $image,
                    defined('IMG_FLIP_VERTICAL') ? IMG_FLIP_VERTICAL : 2
                );
                break;
            case 3:
                $image = imagerotate($image, 180, 0);
                break;
            case 4:
                $image = $this->imageflip(
                    $image,
                    defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1
                );
                break;
            case 5:
                $image = $this->imageflip(
                    $image,
                    defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1
                );
                $image = imagerotate($image, 270, 0);
                break;
            case 6:
                $image = imagerotate($image, 270, 0);
                break;
            case 7:
                $image = $this->imageflip(
                    $image,
                    defined('IMG_FLIP_VERTICAL') ? IMG_FLIP_VERTICAL : 2
                );
                $image = imagerotate($image, 270, 0);
                break;
            case 8:
                $image = imagerotate($image, 90, 0);
                break;
            default:
                return false;
        }
        $success = imagejpeg($image, $file_path);
        // Free up memory (imagedestroy does not delete files):
        imagedestroy($image);

        return $success;
    }

    public function imageflip($image, $mode)
    {
        if (function_exists('imageflip')) {
            return imageflip($image, $mode);
        }
        $new_width = $src_width = imagesx($image);
        $new_height = $src_height = imagesy($image);
        $new_img = imagecreatetruecolor($new_width, $new_height);
        $src_x = 0;
        $src_y = 0;
        switch ($mode) {
            case '1': // flip on the horizontal axis
                $src_y = $new_height - 1;
                $src_height = -$new_height;
                break;
            case '2': // flip on the vertical axis
                $src_x  = $new_width - 1;
                $src_width = -$new_width;
                break;
            case '3': // flip on both axes
                $src_y = $new_height - 1;
                $src_height = -$new_height;
                $src_x  = $new_width - 1;
                $src_width = -$new_width;
                break;
            default:
                return $image;
        }
        imagecopyresampled(
            $new_img,
            $image,
            0,
            0,
            $src_x,
            $src_y,
            $new_width,
            $new_height,
            $src_width,
            $src_height
        );
        // Free up memory (imagedestroy does not delete files):
        imagedestroy($image);

        return $new_img;
    }
}
