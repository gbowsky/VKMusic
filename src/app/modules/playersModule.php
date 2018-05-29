<?php
namespace app\modules;

use std, gui, framework, app;


class playersModule extends AbstractModule
{

    /**
     * @event timer.action 
     */
    function doTimerAction(ScriptEvent $e = null)
    {    
        if ($this->player->position == 100)
        {
            app()->form('AudioRoom')->playSelectedAudio($this->listView->selectedIndex+1);
        }
    }

    /**
     * @event systemTray.click-Left 
     */
    function doSystemTrayClickLeft(UXMouseEvent $e = null)
    {    
        app()->getForm('AudioRoom')->doPlayAction();
    }

    /**
     * @event systemTray.click-2x 
     */
    function doSystemTrayClick2x(UXMouseEvent $e = null)
    {    
        app()->getForm('AudioRoom')->playSelectedAudio(app()->getForm('AudioRoom')->listView->selectedIndex+1);
    }

}
