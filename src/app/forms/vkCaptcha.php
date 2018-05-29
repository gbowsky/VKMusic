<?php
namespace app\forms;

use php\gui\framework\AbstractForm;
use php\gui\event\UXMouseEvent; 
use php\gui\event\UXWindowEvent; 
use action\Element; 
use php\io\Stream; 


class vkCaptcha extends AbstractForm
{
    private $captchaUrl, $captchaCode;

    /**
     * @event button.click 
     **/
    function doButtonClick(UXMouseEvent $event = null)
    {    
        $this->captchaCode = $this->input->text;
        app()->hideForm('vkCaptcha');
    }

    /**
     * @event show 
     **/
    function doShow(UXWindowEvent $event = null)
    {
        $this->showPreloader('Загрузка капчи');
        $this->input->text = '';
        
        Element::loadContentAsync($this->captcha, $this->captchaUrl, function () use ($event) {
            $this->hidePreloader();
        });
    }
    
    function setUrl($url){
        $this->captchaUrl = $url;
    }
}
