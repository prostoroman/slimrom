<?php


namespace BCMS;

class Modules
{
    protected $bcms;

    public function __construct($di)
    {
        $this->bcms = $di;
    }

    public function get($name)
    {
        $name = mb_convert_case($name, MB_CASE_TITLE, "UTF-8");
        $name = str_replace('-', "\\", $name);
        $name = "\\BCMS\\".$name;

        $myclass = null;

        if (class_exists($name)) {
            $myclass = new $name($this->bcms);
        }

        return $myclass;
    }
}
