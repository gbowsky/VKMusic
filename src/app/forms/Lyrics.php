<?php
namespace app\forms;

use std, gui, framework, app;


class Lyrics extends AbstractForm
{
    public function getLyrics($lyricsid) {
        $this->show();
        $this->showPreloader('Загрузка');
        $thread = new Thread(function () use ($lyricsid, $this) {
            $a = VKDirectAuth::Query('audio.getLyrics', ['lyrics_id'=>$lyricsid]);
            $this->textArea->text = $a['response']['text'];
        });
        $thread->start();
        $this->hidePreloader();
    }
}