<?php

/**
* User
*
* Class for working with users
* @version 1.0
*/

namespace BCMS;

use HybridLogic\Validation\Validator;
use HybridLogic\Validation\Rule;

class User
{
    protected $tableName = 'b_users';
    protected $defaultGroup = 'User';
    protected $defaultStatus = 0;
    protected $alias = 'users';
    protected $bcms;
    protected $adminEmails = array('chepinoga@w5kick.com', 'nekrasov@w5kick.com', 'roman@bs1.ru');

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function getAdmins()
    {
        $admins = \ORM::for_table($this->tableName)->where('group', 'Administator')->find_array();

        return $admins;
    }

    public function getByEmail($email)
    {
        $user = \ORM::for_table($this->tableName)->where('email', $email)->find_one();

        return $user;
    }

    public function getByUuid($uuid)
    {
        $user = \ORM::for_table($this->tableName)->where('uuid', $uuid)->find_one();

        return $user;
    }

    public function get($id)
    {
        $id = intval($id);
        $user = \ORM::for_table($this->tableName)->find_one($id);

        return $user;
    }

    public function register($data)
    {
        $data = $this->filter($data);

        if (!$this->validate($data)) {
            return false;
        }

        $checkUser = $this->getByEmail($data['email']);
        if ($checkUser) {
            $this->bcms->flash('error', 'Email already exists');

            return false;
        }

        $user = \ORM::for_table($this->tableName)->create();

        $user->email = $data['email'];
        $user->password = sha1($data['password']);
        $user->confirmation = uniqid();
        $user->group = $this->defaultGroup;
        $user->status = $this->defaultStatus;
        $user->set_expr('date_created', "now()");

        $result = $user->save();

        $profile = \ORM::for_table('b_users_profiles')->create();
        $profile->user_id = $user->id();
        $profile->save();

        $twig = $this->bcms->view()->getEnvironment();

        $this->bcms->history->add(array(
                       'title' => 'Регистрация пользователя '.$user->email,
                       'link' => '/admin/users/edit/'.$user->id(),
                       'where' => '/user/register',
                       'css_class' => '',
                       ));

        $baseUrl = $this->bcms->getBaseUrl;
        $message = $twig->render('user/mail/confirmation-mail.twig', array('user' => $user, 'baseUrl' => $baseUrl));

        $this->sendEmail($user->email, 'W5 Video registration', $message);

        $message_admin = $twig->render('user/mail/confirmation-mail-admin.twig', array('user' => $user, 'baseUrl' => $baseUrl));
        //$this->sendEmail('support@w5kick.tv', 'W5 Video new user', $message_admin);

        return $result;
    }

    public function registerByUuid($uuid) //1A3E33A7-3F1F-473E-B9F2-C5B5FC0ED888
    {
        if (!$uuid) {
            return false;
        }

        $checkUser = $this->getByUuid($uuid);
        if ($checkUser) {
            $this->bcms->flash('error', 'User with uuid already exists');
            return false;
        }

        $user = \ORM::for_table($this->tableName)->create();

        $user->email = $uuid.'@w5kick.tv';
        $user->uuid = $uuid;
        $user->password = sha1(uniqid());
        $user->confirmation = uniqid();
        $user->group = $this->defaultGroup;
        $user->status = $this->defaultStatus;
        $user->set_expr('date_created', "now()");

        $result = $user->save();

        $profile = \ORM::for_table('b_users_profiles')->create();
        $profile->user_id = $user->id();
        $profile->save();

        $twig = $this->bcms->view()->getEnvironment();

        $this->bcms->history->add(array(
                       'title' => 'Регистрация пользователя по UUID '.$user->uuid,
                       'link' => '/admin/users/edit/'.$user->id(),
                       'where' => '/user/register',
                       'css_class' => '',
                       ));

        $baseUrl = $this->bcms->getBaseUrl;
        //$message = $twig->render('user/mail/confirmation-mail.twig', array('user' => $user, 'baseUrl' => $baseUrl));
        //$this->sendEmail($user->email, 'W5 Video registration', $message);
        //$message_admin = $twig->render('user/mail/confirmation-mail-admin.twig', array('user' => $user, 'baseUrl' => $baseUrl));
        //$this->sendEmail('support@w5kick.tv', 'W5 Video new user', $message_admin);

        return $user;
    }

    private function changeCredits($userId, $amount)
    {

        $user = $this->bcms->users->get($userId);

        if (!$user) {
            $this->bcms->flashNow('error', 'User not found');

            return false;
        }

        $profile = $this->bcms->users->getProfile($user->id);

        try {
            // Start a transaction
            \ORM::get_db()->beginTransaction();

            $profile->balance = $profile->balance + $amount;
            $profile->save();
            //print_r($profile->as_array());

            // Commit a transaction
            \ORM::get_db()->commit();

            if ($amount > 0) {
                $operationName = 'Пополнение ';
            } else {
                $operationName = 'Списание со';
            }

            $this->bcms->history->add(array(
                           'title' => $operationName.' счета пользователя '.$user->email.' '.$amount.' кредитов',
                           'link' => '/admin/users/edit/'.$user->id,
                           'where' => '',
                           'css_class' => '',
                           ));

            return true;
        } catch (Exception $e) {
            // An exception has been thrown
            // We must rollback the transaction
            \ORM::get_db()->rollBack();

            return false;
        }
    }

    public function addCredits($userId, $amount)
    {
        $result = $this->changeCredits($userId, $amount);

        return $result;
    }

    public function removeCredits($userId, $amount)
    {
        $result = $this->changeCredits($userId, -1*$amount);

        return $result;
    }

    public function buyVideo($hash)
    {
        $error = false;

        if (!empty($this->bcms->user)) {
            $user = $this->bcms->user;
        } else {
            $error = 'User not found';

            return $error;
        }

        $user = $this->bcms->users->get($user['id']);
        $profile = $this->bcms->users->getProfile($user->id);
        $video = $this->bcms->videos->getByHash($hash);

        if (!$profile) {
            $error = 'User not found';

            return $error;
        }

        if ($profile->balance < $video['price']) {
            $error = 'Not enough credits';

            return $error;
        }

        $result = $this->removeCredits($user->id, $video['price']);

        if (!$result) {
            $error = 'Error while paying credits';

            return $error;
        }

        $rel = \ORM::for_table('b_users_videos')->create();
        $rel->user_id = $user->id;
        $rel->video_id = $video['id'];
        $rel->save();

        $history = array(
                         'title' => 'Пользователь '.$user->email.' купил видео "'.$video['name'].'" за '.$video['price'].' кредитов.',
                         'link' => '/video/'.$hash,
                         'where' => '/user/buy/video',
                         'css_class' => 'success');
        $this->bcms->history->add($history);

        $this->bcms->flash('success', 'Вы купили видео '.$video['name']);

        return $error;
    }

    public function buyPhoto($id)
    {
        $error = false;

        if (!empty($this->bcms->user)) {
            $user = $this->bcms->user;
        } else {
            $error = 'User not found';

            return $error;
        }

        $user = $this->bcms->users->get($user['id']);
        $profile = $this->bcms->users->getProfile($user->id);

        $price = 1;
        $photo = $this->bcms->gallery->getPhoto($id);


        if (!$profile) {
            $error = 'Profile not found';

            return $error;
        }

        if ($profile->balance < $price) {
            $error = 'Not enough credits';

            return $error;
        }

        $result = $this->removeCredits($user->id, $price);

        if (!$result) {
            $error = 'Error while paying credits';

            return $error;
        }

        $rel = \ORM::for_table('b_users_photos')->create();
        $rel->user_id = $user->id;
        $rel->photo_id = $photo['id'];
        $rel->save();

        $this->bcms->flash('success', 'You bought photo');

        $history = array(
                        'title' => 'Пользователь '.$user->email.' купил фото "'.$photo['id'].'" за '.$price.' кредит.',
                        'link' => '/admin/gallery-gallery/edit/'.$photo['gallery_id'],
                        'where' => '/user/buy/photo',
                        'css_class' => 'success', );

        $this->bcms->history->add($history);

        return $error;
    }

    public function activate($userId, $confirmation)
    {
        $user = $this->get($userId);
        if (!$user) {
            $this->bcms->flashNow('error', 'User not found');

            return false;
        }

        if ($user->confirmation !== $confirmation) {
            $this->bcms->flashNow('error', 'Confirmation code is wrong');

            return false;
        }

        $user->confirmation = '';
        $user->status = 1;

        $this->bcms->history->add(array(
                       'title' => 'Пользователь '.$user->email.' активирован',
                       'link' => '/admin/users/edit/'.$user->id,
                       'where' => '/user/activate',
                       'css_class' => '',
                       ));

        return $user->save();
    }

    public function forgetPassword($email)
    {
        $user = $this->getByEmail($email);

        if (!$user) {
            $this->bcms->flash('error', 'User not found');

            return false;
        }
        /*
        if(!$user->active == 0 || $user->confirmation !== '')
        {
           $this->bcms->flash('error', 'Пользователь заблокирован');
           return false;
        }
        */
        $user->confirmation = uniqid();

        $twig = $this->bcms->view()->getEnvironment();
        $baseUrl = $this->bcms->getBaseUrl;
        $message = $twig->render('user/mail/forget-password-mail.twig', array('user' => $user, 'baseUrl' => $baseUrl));

        $this->sendEmail($user->email, 'Change your password', $message);

        return $user->save();
    }

    public function resetPassword($userId, $confirmation)
    {
        $user = \ORM::for_table($this->tableName)->where('id', $userId)->find_one();

        if (!$user) {
            $this->bcms->flashNow('error', 'User not found');

            return false;
        }

        if ($confirmation !== $user->confirmation) {
            $this->bcms->flashNow('error', 'Error, confirmation code is wrong');

            return false;
        }

        $user->confirmation = '';
        $password = substr(hash('sha512', rand()), 0, 8);
        $user->password = sha1($password);

        $twig = $this->bcms->view()->getEnvironment();
        $baseUrl = $this->bcms->getBaseUrl;

        $message = $twig->render('user/mail/new-password-mail.twig', array('user' => $user, 'password' => $password, 'baseUrl' => $baseUrl));

        $this->sendEmail($user->email, 'New password', $message);

        $this->bcms->history->add(array(
                       'title' => 'Пользователь '.$user->email.' сбросил пароль',
                       'link' => '/admin/users/edit/'.$user->id,
                       'where' => '/user/forget-password',
                       'css_class' => 'danger',
                       ));

        return $user->save();
    }

    private function validate($data)
    {
        $validator = new Validator();

        $validator
      //->add_rule('id', new Rule\NumNatural())
      //->add_rule('username', new Rule\AlphaNumeric())
      //->add_rule('firstname', new Rule\NotEmpty())
      //->add_rule('firstname', new Rule\Alpha())
      //->add_rule('lastname', new Rule\NotEmpty())
      //->add_rule('lastname', new Rule\Alpha())
      ->add_rule('password', new Rule\NotEmpty())
      ->add_rule('password', new Rule\MinLength(6))
      ->add_rule('email', new Rule\NotEmpty())
      ->add_rule('email', new Rule\Email())
      //->add_rule('phone', new Rule\AlphaNumeric())
      ->add_rule('group', new Rule\AlphaNumeric())
      ->add_rule('status', new Rule\AlphaNumeric())
      ->add_rule('confirmation', new Rule\AlphaNumeric())
      //->add_rule('balance', new Rule\Number())
      ;

        if ($validator->is_valid($data)) {
            return true;
        } else {
            $this->bcms->flash('error', print_r($validator->get_errors(), true));

            return false;
        }
    }
    private function filter($data)
    {
        foreach ($data as $key => $item) {
            $newdata[$key] = trim($item);
        }

        return $newdata;
    }

    public function sendEmail($to, $subject, $message)
    {
        /*
      $mailConfig = $this->bcms->smtp_config;
      $mail = new \BCMS\SMTP($mailConfig);
      $mail->to($to);
      $mail->from($mailConfig['fromMail'], $mailConfig['fromName']);
      $mail->subject($subject);
      $mail->body($message);
      $result = $mail->send();
*/
      $from      = "support@w5kick.tv";
        $text_body = '';
        $html_body = $message;
        $mail      = new \BCMS\Mail($to, $from, $subject, $text_body, $html_body);

      //$mail->add_attachment("/path/to/my_attachment.file");
      //$mail->add_attachment("/path/to/my_other_attachment.file");

      return $mail->send();
    }

    public function uploadPhoto()
    {
        $log = $this->bcms->getLog();

        $view = $this->bcms->view();
        $req = $this->bcms->request();

        if (!empty($this->bcms->user)) {
            $user = $this->bcms->user;
            $log->info('Uploading profile photo for '.$user['email']);
        }

        $error = false;

        $user = $this->bcms->users->get($user['id']);
        $profile = $this->bcms->users->getProfile($user['id']);

      // Photo manipulation
      if (!empty($_FILES)) {
          $uploadDir = '/upload/users';
          $uploadDirFull = $this->bcms->home_dir.$uploadDir;

          if (!file_exists($uploadDirFull)) {
              mkdir($uploadDirFull, 0777, true);
          }

          if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== 4) {
              if (!$_FILES['photo']['error']) {
                  if ($profile->photo) {
                      if (file_exists($this->bcms->home_dir.'/'.$profile->photo)) {
                          @unlink($this->bcms->home_dir.'/'.$profile->photo);
                      }
                  }

                  $userPhoto = $uploadDirFull.'/'.$_FILES['photo']['name'];

                  if (!in_array(strtolower(pathinfo($userPhoto, PATHINFO_EXTENSION)), array('jpeg', 'jpg'))) {
                      $error = 'Please select a jpg file';
                      $log->error('Please select a jpg file');

                      return $error;
                  }

                  if (move_uploaded_file($_FILES['photo']['tmp_name'], $userPhoto)) {
                      $newFileName = uniqid().'.'.pathinfo($userPhoto, PATHINFO_EXTENSION);

                      rename($userPhoto, $uploadDirFull.'/'.$newFileName);
                      $log->info($newFileName);

                      $profile->photo = $uploadDir.'/'.$newFileName;
                      $profile->save();

                      try {
                          $img = new \BCMS\SimpleImage($this->bcms->home_dir.'/'.$profile->photo);
                          $img->smart_crop(300, 300)->save($this->bcms->home_dir.'/'.$profile->photo);
                      } catch (\Exception $e) {
                          $error = 'There is a error while image processing';
                          $log->error('There is a error while image processing');

                          return $error;
                      }
                  } else {
                      $error = 'There is a error while image processing, please try again';
                      $log->error('There is a error while image processing, please try again');

                      return $error;
                  }
              } else {
                  $error = 'Error while uploading file';
                  $log->error('Error while uploading file');

                  return $error;
              }
          }
      } else {
          $error = 'Error while uploading file';
          $log->error(print_r($_FILES, true));
          $log->error(print_r($_POST, true));

          return $error;
      }

        return  $error;
    }

    public function getAdminEmails()
    {
        return $this->adminEmails;
    }
}
