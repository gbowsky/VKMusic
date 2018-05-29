<?php
namespace app\modules;

use httpclient;
use std, gui, framework, app;
use php\framework\Logger;
use php\format\JsonProcessor;
use app\forms\vkCaptcha;
use php\gui\UXApplication;
use php\gui\UXDialog;
use php\io\Stream;
use php\lang\Thread;
use php\lib\Str;


class VKDirectAuth extends AbstractModule
{

    const LOG = false;
    private static
        $appID = '2274003',
        $appSecret = 'hHbZxrka2uZ6jB1inYsH',
        $tokenFile = './cache.vk',
        $accessToken = 'false',
        $version = '5.74';
        
    private function Log(){
        if(self::LOG) Logger::Debug('[VK] ' . var_export(func_get_args(), true));
    }
    
    public static function auth($login, $password)
    {
        $reqe = new HttpClient;
        $reqe->userAgent = 'VKAndroidApp/4.0.1-816 (Android 6.0; SDK 23; x86; Google Nexus 5X; ru)';
        $reqe->responseType = 'JSON';
        $req = $reqe->get('https://oauth.vk.com/token', [
        'grant_type'=>'password',
        'client_id'=>self::$appID,
        'client_secret'=>self::$appSecret,
        'username'=>$login,
        'password'=>$password]);
        if ($req->body()['access_token'] != '')
        {
            self::$accessToken = $req->body()['access_token'];
            $toke = self::Query('auth.refreshToken', ['access_token'=>self::$accessToken, 'receipt'=>'JSv5FBbXbY:APA91bF2K9B0eh61f2WaTZvm62GOHon3-vElmVq54ZOL5PHpFkIc85WQUxUH_wae8YEUKkEzLCcUC5V4bTWNNPbjTxgZRvQ-PLONDMZWo_6hwiqhlMM7gIZHM2K2KhvX-9oCcyD1ERw4']);
            self::$accessToken = $toke['response']['token'];
            file_put_contents(self::$tokenFile, self::$accessToken);
            return true;
        }
        else 
        {
            return false;
        }
    }
    
    public static function settoken($token = '')
    {
        if ($token == '')
        {
            self::$accessToken = file_get_contents(self::$tokenFile);
        }
        else 
        {
            self::$accessToken = $token;
            file_put_contents(self::$tokenFile, self::$accessToken);
        }
    }
    
    public static function Query($method, $params = [], $callback = false, $jParams = [])
    {        
        $params['v'] = self::$version;
                        
        if(self::$accessToken){
            $params['access_token'] = self::$accessToken;
        }
                        
        $url = 'https://api.vk.com/method/'.$method.'?'.http_build_query($params);

        $connect = new jURL($url);
        $connect->setUserAgent('VKAndroidApp/4.0.1-816 (Android 6.0; SDK 23; x86; Google Nexus 5X; ru)');
        $connect->setOpts($jParams);
        if(is_callable($callback)){
            $connect->asyncExec(function($content, $connect) use ($method, $params, $callback, $jParams){
                $result = self::processResult($content, $connect, $method, $params, $callback, $jParams);
                if($result !== false) $callback($result);
            });
        } else {
            $content = $connect->exec();
            return self::processResult($content, $connect, $method, $params, $callback, $jParams);
        }
    }
    
    private static $longPoll, $lpAbort = false;
    
    public static function longPollConnect($callback, $params = false){
        if(!$params) return self::query('messages.getLongPollServer', ['use_ssl' => 1, 'need_pts' => 1], function($answer) use ($callback){
            self::$lpAbort = false;
            return self::longPollConnect($callback, $answer['response']);
        });

        self::log(['longPollConnect' => $params]);

        $func = function() use ($params, $callback){
            self::query(null, [], function($answer) use ($params, $callback){
                if(self::$lpAbort === true){
                    self::$lpAbort = false;
                    return;
                } 

                if(isset($answer['failed'])) return self::longPollConnect($callback, false); 

                UXApplication::runLater(function() use ($callback, $answer){
                    $callback($answer['updates']);
                });

                $params['ts'] = $answer['ts'];
                return self::longPollConnect($callback, $params);
            }, 
            [
                'url' => 'https://'.$params['server'].'?act=a_check&key='.$params['key'].'&ts='.$params['ts'].'&wait=25&mode=2',
                'connectTimeout' => 10000,
                'readTimeout' => 35000
            ]);
        };

        self::$longPoll = new Thread($func);
        self::$longPoll->start();
    }
    
    private static function processResult($content, $connect, $method, $params, $callback, $jParams){
        try {
            $errors = $connect->getError();
            if($errors !== false){
                throw new vkException('Невозможно совершить запрос', -1, $errors);
            }

            $json = new JsonProcessor(JsonProcessor::DESERIALIZE_AS_ARRAYS);
            $data = $json->parse($content);

            self::log([$url=>$data]);
                 
                        
            if(isset($data['error'])){
                throw new vkException($data['error']['error_msg'], $data['error']['error_code'], $data);
                return false;
            }

            return $data;
            
        }catch(vkException $e){
            UXApplication::runLater(function () use ($e, $method, $params, $callback, $jParams) {
                switch($e->getCode()){
                    //api.vk.com недоступен, обычно из-за частых запросов
                    case -2:
                        wait(500);
                        
                    break;    
                    
                    case 5://Просроченный access_token
                    case 10://Ошибка авторизации
                        UXDialog::show('Вам необходимо повторно авторизоваться', 'ERROR');
                        self::logout();
                        return self::checkAuth(function(){
                            self::Query($method, $params, $callback, $jParams);
                        });
                    break;    
                        //Нужно ввести капчу
                    case 14:
                        $result = $e->getData();

                        $vkCaptcha = app()->getForm('vkCaptcha');
                        $vkCaptcha->setUrl($result['error']['captcha_img']);
                        $vkCaptcha->showAndWait();

                        $params['captcha_sid'] = $result['error']['captcha_sid'];
                        $params['captcha_key'] = $vkCaptcha->input->text;
                    break;    

                    default:
                        return UXDialog::show('Ошибка VK API: '.$e->getMessage().' (code='.$e->getCode().')' . "\n\n\nDebug: " . var_export($e->getData(), true), 'ERROR');
                }

                return self::Query($method, $params, $callback, $jParams);
            
            });
        }

        return false;
    }
}

class vkException extends \Exception{
    private $data;
    public function getData(){
        return $this->data;
    }
        
    public function __construct($message = null, $code = 0, $data = []){
        $this->data = $data;
        return parent::__construct($message, $code, null);
    }
    
}

if(!function_exists('http_build_query')){
    function http_build_query($a,$b='',$c=0)
     {
            if (!is_array($a)) return false;
            foreach ((array)$a as $k=>$v)
            {
                if ($c)
                {
                    if( is_numeric($k) )
                        $k=$b."[]";
                    else
                        $k=$b."[$k]";
                }
                else
                {   if (is_int($k))
                        $k=$b.$k;
                }

                if (is_array($v)||is_object($v))
                {
                    $r[]=http_build_query($v,$k,1);
                        continue;
                }
                $r[]=urlencode($k)."=".urlencode($v);
            }
            return implode("&",$r);
            }
}
