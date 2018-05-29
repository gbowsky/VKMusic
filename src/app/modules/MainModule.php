<?php
namespace app\modules;

use std, gui, framework, app;

class MainModule extends AbstractModule
{
    /**
     * @event construct 
     */
    function doConstruct(ScriptEvent $e = null)
    {    
        if (file_exists('./cache.vk') == true)
        {
            VKDirectAuth::settoken();
            app()->hideForm('MainForm');
            app()->showForm('AudioRoom');
        }
    }

}
