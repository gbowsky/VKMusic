<?php
namespace app\forms;

use std, gui, framework, app;


class AudioRoom extends AbstractForm
{

    /**
     * @event show 
     */
    function doShow(UXWindowEvent $e = null)
    {    
        if (!file_exists("./Audios/"))
            {
                fs::makeDir('Audios');
            }
            if ($this->ini->get('volume') == null or 0)
            {
                $this->ini->set('volume', 100);
            }
        $GLOBALS['me'] = VKDirectAuth::Query('users.get')['response'][0];
        $this->comboboxAlt->selectedIndex = 0;
        $this->player->volume = $this->ini->get('volume');
        $this->volume->value = $this->ini->get('volume');
        if ($this->ini->get('first_start') == null or 0)
        {
        $this->systemTray->displayMessage('Первый запуск', "
            ЛКМ - Play/Pause
            2xЛКМ - Следующий трек");
            $this->ini->set('first_start', 1); 
        }
        $GLOBALS['big-list-count'] = 0;
        $this->getAudios();
        
    }
    
    function getAudios($off = 0)
    {
        if ($off == 0)
        {
            $this->listView->scrollTo(0);
        }
        Logger::warn('getAudios called');
        if ($this->comboboxAlt->selectedIndex == 0)
        {
            $this->getMyAudios($off);
        }
        elseif ($this->comboboxAlt->selectedIndex == 1)
        {
            $this->getMyRecommendations($off);
        }
    }
    
    function getMyAudios($offset)
    {
        $GLOBALS['mode'] = 'normal';
        $this->listView->items->clear();
        $a = VKDirectAuth::Query('audio.get', ['offset'=>$offset]);
        $GLOBALS['got-count'] = $a['response']['count'];
        foreach ($a['response']['items'] as $b)
        {
            $GLOBALS['big-audio-list'][$GLOBALS['big-list-count']] = $b;
            $GLOBALS['big-list-count'] += 1;
        }
        $this->renderAudios($offset);
    }

    function getMyRecommendations($offset)
    {
        $GLOBALS['mode'] = 'recs';
        $this->listView->items->clear();
        $a = VKDirectAuth::Query('audio.getRecommendations', ['offset'=>$offset, 'count'=>50]);
        foreach ($a['response']['items'] as $b)
        {
            $GLOBALS['big-audio-list'][$GLOBALS['big-list-count']] = $b;
            $GLOBALS['big-list-count'] += 1;
        }
        $this->renderAudios();
    }


    /**
     * @event Play.action 
     */
    function doPlayAction(UXEvent $e = null)
    {    
        if ($this->listView->selectedIndex == -1)
        {
            $this->playSelectedAudio();
        }
        else 
        {
            if ($this->player->status != 'PLAYING')
            {
                $this->player->play();
            }
            else
            {
                $this->player->pause();
            }
        }
    }



    /**
     * @event progress.step 
     */
    function doProgressStep(UXEvent $e = null)
    {    
        $this->progress->value = $this->player->position;
        if ($this->player->status != "PLAYING")
        {
            $this->Play->text = '';
        }
        else 
        {
            $this->Play->text = '';
        }
        
        $this->label->text = (new Time($this->player->positionMs))->toString('mm:ss');
        if (!$GLOBALS['mode'] == 'normal'){
            $this->button4->text = '';
        }
        else 
        {
            $this->button4->text = '';
        }
    }

    /**
     * @event progress.mouseDown-Left 
     */
    function doProgressMouseDownLeft(UXMouseEvent $e = null)
    {    
        $this->player->pause();
    }

    /**
     * @event progress.mouseUp-Left 
     */
    function doProgressMouseUpLeft(UXMouseEvent $e = null)
    {    
        $this->player->play();
    }

    /**
     * @event volume.mouseDrag 
     */
    function doVolumeMouseDrag(UXMouseEvent $e = null)
    {    
        $this->player->volume = $this->volume->value;
        $this->ini->set('volume', $this->volume->value);
    }


    /**
     * @event progress.mouseDrag 
     */
    function doProgressMouseDrag(UXMouseEvent $e = null)
    {    
        $this->player->position = $this->progress->value;
    }

    /**
     * @event button.action 
     */
    function doButtonAction(UXEvent $e = null)
    {
        $this->toast('Скачивание '.$GLOBALS['now-playing']['artist']." ".$GLOBALS['now-playing']['title']);
        $thread = new Thread(function() use($GLOBALS){
            if (!file_exists("./Audios/"))
            {
                fs::makeDir('Audios');
            }
            file_put_contents("./Audios/".$GLOBALS['now-playing']['artist']."_".$GLOBALS['now-playing']['title'].".mp3", file_get_contents($GLOBALS['now-playing']['url']));
            $this->toast('Скачивание '.$GLOBALS['now-playing']['artist']." ".$GLOBALS['now-playing']['title'].' завершено.');
        });
        $thread->start();
    }

    /**
     * @event buttonAlt.action 
     */
    function doButtonAltAction(UXEvent $e = null)
    {
        $this->shuffleList();
        $this->playSelectedAudio();
        $this->listView->scrollTo(0);
    }

    /**
     * @event listView.scroll-Down 
     */
    function doListViewScrollDown(UXScrollEvent $e = null)
    {
        if ($GLOBALS['mode'] == 'normal' or 'recs')
        {
            $a = $this->listView->items->count;
            $this->getAudios($this->listView->items->count);
            $this->listView->scrollTo($a-1);
        }
        else
        {
            $a = $this->listView->items->count;
            $this->searchAudio($this->listView->items->count);   
            $this->listView->scrollTo($a-1);
        }
    }

    /**
     * @event comboboxAlt.action 
     */
    function doComboboxAltAction(UXEvent $e = null)
    {    
        $GLOBALS['big-list-count'] = 0;
        $GLOBALS['big-audio-list'] = null;
        $this->getAudios();
    }

    /**
     * @event button4.action 
     */
    function doButton4Action(UXEvent $e = null)
    {
        if ($GLOBALS['mode'] == 'normal')
        {
            VKDirectAuth::Query('audio.delete', ['audio_id'=>$GLOBALS['now-playing']['id'],'owner_id'=>$GLOBALS['me']['id']]);
            $this->toast('Аудиозапись удалена');
            $this->listView->selectedIndex += 1;
        }
        else 
        {
            VKDirectAuth::Query('audio.add', ['audio_id'=>$GLOBALS['now-playing']['id'],'owner_id'=>$GLOBALS['now-playing']["owner_id"]]);
            $this->toast('Аудиозапись добавлена');
        }
    }

    /**
     * @event edit.globalKeyPress-Enter 
     */
    function doEditGlobalKeyPressEnter(UXKeyEvent $e = null)
    {    
        if ($this->edit->text != '')
        {
            $this->comboboxAlt->selectedIndex = -1;
            $this->comboboxAlt->value = 'Поиск';
            $GLOBALS['big-list-count'] = 0;
            $GLOBALS['big-audio-list'] = null;
            $this->searchAudio();
        }
        else 
        {
            $this->toast('Введите текст в строку поиска');
        }
    }

    /**
     * @event button3.action 
     */
    function doButton3Action(UXEvent $e = null)
    {
        open("./Audios/");
    }


    function searchAudio($offset = 0)
    {    
        if ($offset == 0)
        {
            $this->listView->scrollTo(0);
        }
        $GLOBALS['mode'] = 'search';
        $this->listView->items->clear();
        $a = VKDirectAuth::Query('audio.search', ['q'=>$this->edit->text, 'offset'=>$offset]);
        foreach ($a['response']['items'] as $b)
        {
            $GLOBALS['big-audio-list'][$GLOBALS['big-list-count']] = $b;
            $GLOBALS['big-list-count'] += 1;
        }
        $this->renderAudios();
    }

    function renderAudios()
    {
        foreach ($GLOBALS['big-audio-list'] as $c)
        {
            $main = new UXHBox;
            $pic = new UXImageView;
            $pic->size = [40,40];
            if ($c['album']['thumb']['photo_68'] != "")
            {
                Element::loadContent($pic, $c['album']['thumb']['photo_68']);
            }
            else 
            {
                Element::loadContent($pic, "https://vk.com/images/audio_row_placeholder.png");
            }
            $names = new UXVBox;
            $artist = new UXLabel($c['artist']);
            $title = new UXLabel($c['title']);
            $title->font->bold = true;
            $title->minWidth = $this->listView->width-55;
            $artist->minWidth = $this->listView->width-55;
            $title->maxWidth = $this->listView->width-55;
            $artist->maxWidth = $this->listView->width-55;
            $title->wrapText = true;
            $artist->wrapText = true;
            $names->add($title);
            $names->add($artist);
            $names->alignment = "CENTER_LEFT";
            $names->paddingLeft = 5;
            $names->maxWidth = $this->listView->width-45;
            $main->maxWidth = $this->listView->width-35;
            $names->minWidth = $this->listView->width-45;
            $main->minWidth = $this->listView->width-35;
            $main->padding = 5;
            $main->add($pic);
            $main->add($names);
            $main->on('click', function () use () {
                $this->playSelectedAudio($this->listView->selectedIndex);
            });
            $this->listView->items->add($main);
        }
        
    }
    
    function shuffleList()
    {
        $this->listView->items->clear();
        shuffle($GLOBALS['big-audio-list']);
        $this->renderAudios();
    }

    function playSelectedAudio($id = 0)
    {
        $this->listView->selectedIndex = $id;
        $GLOBALS['now-playing'] = $GLOBALS['big-audio-list'][$id];
        $this->Title_audio->text = $GLOBALS['now-playing']['title']." - ".$GLOBALS['now-playing']['artist'];
        $this->player->source = $GLOBALS['now-playing']['url'];
    }

}
