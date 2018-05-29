<?php
namespace app\forms;

use std, gui, framework, app;


class MainForm extends AbstractForm
{

    /**
     * @event button.action 
     */
    function doButtonAction(UXEvent $e = null)
    {    
        if ($this->edit->text != '')
        {
            if ($this->passwordField->text != '')
            {
                if (VKDirectAuth::auth($this->edit->text,$this->passwordField->text) == true)
                {
                    app()->hideForm('MainForm');
                    app()->showForm('AudioRoom');
                }
                else 
                {
                    $this->toast('Неправильно набран логин/пароль или отсутствует подключение к сети');
                }
            }
        }
        else 
        {
            $this->toast('Введите логин и пароль');
        }
    }

}
