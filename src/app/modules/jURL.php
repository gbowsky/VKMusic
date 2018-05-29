<?php
namespace app\modules{
    use php\framework\Logger;
    use php\gui\UXApplication;
    use php\io\File;
    use php\io\FileStream;
    use php\io\MemoryStream;
    use php\io\Stream;
    use php\lang\System;
    use php\lang\Thread;
    use php\lib\Str;
    use php\net\Proxy;
    use php\net\URL;
    use php\net\URLConnection;
    use php\time\Time;
    use php\time\TimeFormat;
    use php\util\Locale;
    use php\util\Regex;

    class jURL
    {
        public $version = 0.4.0.1;

        const CRLF = "\r\n",
              LOG = false;

        private $opts = [],         // Параметры подключения
                $URLConnection,     // Соединеие
        
                // Характеристики буфера
                $buffer,            // Буфер получаемых данных
                $requestLength,     // Размер отправленных данных
                $responseLength,    // Размер полученных данных

                // Характеристики соединения
                $charset,           // Кодировка принимаемых данных
                $connectionInfo,    // Информация о последнем запросе
                $lastError,         // Информация об ошибках последнего запроса
                $requestHeaders,    // Отправленные заголовки
                $responseHeaders,   // Полученные заголовки
                $timeStart;         // Время начала запроса (для таймера)

        
        public function __construct($url = null){
            // Установка параметров по умолчанию
            $this->setOpts([    
                'url'               =>    $url,
                'connectTimeout'    =>    10000,
                'readTimeout'       =>    60000,
                'requestMethod'     =>    'GET',
                'followRedirects'   =>    false,
                'autoReferer'       =>    false,
                'userAgent'         =>    $this->genUserAgent(),
                'proxy'             =>    false,
                'proxyType'         =>    'HTTP',
                'bufferLength'      =>    256 * 1024, //256 KiB
                'cookieFile'        =>    false,
                'httpHeader'        =>    [],
                'basicAuth'         =>    false,
                'httpReferer'       =>    false,
                'returnHeaders'     =>    false,    
                'progressFunction'  =>    null,
                
                'outputFile'        =>    false,    // Файл, куда будут записываться данные (вместо того, чтобы их вернуть) // 'fileStream'
                'inputFile'          =>    false,    // Файл, откуда будут счиываться данные в body // bodyFile

                'body'              =>    null,     // Отправляемые данные
                'postData'          =>    [],       // Переформатирут данные в формат query, сохранит их в body
                'postFiles'         =>    [],       // Отправляемые файлы, которые будут отправлены по стандартам "multipart/form-data
            ]);            

            $this->log(['construct', $this->opts]);
        }

        /**
         * --RU--
         * Выполнить запрос асинхронно
         * @param callable $callback - функция будет вызвана по окончанию запроса - function($result, jURL $this)
         */
        public function asyncExec($callback = null){
            return (new Thread(function () use ($callback){
                $result = $this->Exec();
                if(is_callable($callback)){
                    UXApplication::runLater(function () use ($result, $callback) {
                        $callback($result, $this);
                    });
                }
            }))->start();
        }

        /**
         * --RU--
         * Выполнить запрос синхронно
         */
        public function exec($byRedirect = false){
            $url = new URL($this->opts['url']);
            $cookies = NULL;
            $boundary = Str::random(90);
            $useBuffer = !(isset($this->opts['outputFile']) and $this->opts['outputFile'] !== false);

            //Если был редирект, ничего не сбрасываем
            if(!$byRedirect){
                $this->resetConnectionParams();
            }            
            else $this->destroyConnection();
    
            try {    
                $this->createConnection();

                // Параметры подключения
                foreach($this->opts as $key => $value){
                    if(!$value || sizeof($value) == 0) continue;
                    switch($key){
                        case 'connectTimeout':
                            $this->URLConnection->connectTimeout = $value;
                        break;
        
                        case 'readTimeout':
                            $this->URLConnection->readTimeout = $value;
                        break;
        
                        case 'requestMethod':
                            $this->URLConnection->requestMethod = $value;
                        break;

                        case 'cookieFile':
                            $cookies = $this->loadCookies($value);
                            $this->URLConnection->setRequestProperty('Cookie', $this->getCookiesForDomain($cookies, $url->getHost()));
                        break;
        
                        case 'userAgent':
                            $this->URLConnection->setRequestProperty('User-Agent', $value);
                        break;

                        case 'httpHeader':
                            foreach($value as $h){
                                $this->URLConnection->setRequestProperty($h[0], $h[1]);
                            }
                        break;

                        case 'basicAuth':
                            $this->URLConnection->setRequestProperty('Authorization', "Basic " . base64_encode($value) );
                        break;

                        case 'postData':
                            $this->URLConnection->setRequestProperty('Content-Type', "application/x-www-form-urlencoded");
                        break; 
                        
                        case 'httpReferer':
                            $this->URLConnection->setRequestProperty('Referer', $value);
                        break;

                        case 'postFiles':                        
                            $this->URLConnection->setRequestProperty('Content-Type', "multipart/form-data; boundary=$boundary");
                        break;

                    }
                }
                $this->requestHeaders = $this->URLConnection->getRequestProperties();
                $this->log(['Connected to' => $this->opts['url']]);

                // Подключились. Отправляем данные на сервер.
                foreach($this->opts as $key => $value){
                    if(!$value || sizeof($value) == 0 || is_null($value)) continue;

                    switch($key){                        
                        case 'postData':
                            $value = $this->buildQuery($value);
                        case 'body':
                            $this->log('sendBody -> '.$key);
                            $out = $this->URLConnection->getOutputStream();
                            $out->write($value);
                            $this->requestLength += Str::Length($value);
                        break; 
                        
                        case 'inputFile':
                            $this->log('sendBody -> '.$key);
                            $out = $this->URLConnection->getOutputStream();                            
                            $fileStream = ($this->opts['inputFile'] instanceof FileStream)?$this->opts['inputFile']:FileStream::of($this->opts['inputFile'], 'r+');
                            
                            $this->log('Sending bodyFile, size = ' . $fileStream->length());

                            $this->sendData($out, $fileStream, $fileStream->length());
                            while(!$fileStream->eof()){
                                $this->sendData($out, $fileStream, $fileStream->length());
                            }

                            $fileStream->close();
                        break;

                        case 'postFiles':
                            $this->log('sendBody -> '.$key);
                            $this->sendOutputData((is_array($value))?$value:[$value], $boundary);
                        break;
                    }
                }

                /**
                 * Данные отправлены. Читаем заголовки с сервера.
                 *
                 * Нельзя использовать switch case, т.к. если первым будет followRedirects,
                 * а после него cookieFile, то куки не будут прочитаны и сохранены
                 */

                $this->responseHeaders = $this->URLConnection->getHeaderFields();
                // Извлечение кук
                if(isset($this->opts['cookieFile']) and $this->opts['cookieFile'] !== false){
                    $setCookies = (isset($this->responseHeaders['Set-Cookie']) && is_array($this->responseHeaders['Set-Cookie']))?$this->responseHeaders['Set-Cookie']:[];
                            
                    $newCookies = $this->parseCookies($setCookies, $url->getHost());
                    $saveCookies = $this->uniteCookies($cookies, $newCookies);
                    Stream::putContents($this->opts['cookieFile'], $saveCookies);
                }
    
                // Добавление заголовков в вывод
                if(isset($this->opts['returnHeaders']) and $this->opts['returnHeaders'] === true){
                    // Если в foreach засунуть $headers, после цикла все данные куда-то исчезнут >:(
                    foreach($this->URLConnection->getHeaderFields() as $k=>$v){
                        foreach($v as $kk => $s){
                            $hs[] = $k. ((strlen($k) > 0) ? ': ' : '') . $s;
                        }
                    }
                    $this->buffer->write( implode(self::CRLF, $hs) . self::CRLF . self::CRLF );
                }

                /**
                 * Поддержка перенаправлений
                 * пришлось писать свои перенаправления, т.к. со встроенным followredirects
                 * не удаётся прочитать отправляемые куки или заголовки перед перенаправлением
                 */
                if(isset($this->opts['followRedirects']) and $this->opts['followRedirects'] === true and isset($this->responseHeaders['Location'][0])){
                    if($this->opts['autoReferer'] === true){
                        $this->setOpt('httpReferer', $this->opts['url']);
                    }

                    $redirectUrl = $this->getLocationUrl($this->opts['url'], $this->responseHeaders['Location'][0]);
                    $this->log(['Relocation', $redirectUrl]);
                    $this->setOpt('url', $redirectUrl);
                    return $this->Exec(true);
                }
                

                $this->detectCharset($this->getConnectionParam('contentType'));
                $this->getInputData();

                if($useBuffer){
                    $this->buffer->seek(0);
                    $answer = $this->buffer->readFully();
                }
                else $answer = NULL;
                
                $this->buffer->close();

                if($this->charset != 'UTF-8'){
                    $answer = Str::Decode($answer, $this->charset);
                }
                
                $this->log(['URLConnection' => $this->URLConnection]);

                $this->connectionInfo = [
                    'url' => (object) $url,
                    'responseCode' => $this->getConnectionParam('responseCode'),
                    'responseMessage' => $this->getConnectionParam('responseMessage'),
                    'contentLength' => $this->getConnectionParam('contentLength'),
                    'contentType' => $this->getConnectionParam('contentType'),
                    'contentEncoding' => $this->charset,
                    'expiration' => $this->getConnectionParam('expiration'),
                    'lastModified' => $this->getConnectionParam('lastModified'),
                    'usingProxy' => $this->getConnectionParam('usingProxy'),
                    'executeTime' => $this->getExecuteTime(),
                    'requestHeaders' => $this->requestHeaders,
                    'responseHeaders' => $this->responseHeaders,
                    'requestLength' => $this->requestLength
                ];

                $this->log(['connectionInfo', $this->connectionInfo]);
                $this->log(['Answer', $answer]);
                
                $errorStream = (is_object($this->URLConnection)) ? ($this->URLConnection->getErrorStream()->readFully()) : NULL;
                if(str::length($errorStream) > 0){
                    $this->lastError = [
                        'error' => $errorStream,
                        'code' => 0
                    ];
                }


            } catch (\php\net\SocketException $e){
                $this->lastError = ['code' => 1, 'error' => $e->getMessage()];
            } catch (\php\format\ProcessorException $e){
                $this->lastError = ['code' => 2, 'error' => $e->getMessage()];
            } catch (\php\io\IOException $e){
                $this->lastError = ['code' => 3, 'error' => $e->getMessage()];
            } catch (\EngineException $e){
                $this->lastError = ['code' => 4, 'error' => $e->getMessage()];
            } catch (\Exception $e){
                $this->lastError = ['code' => 5, 'error' => $e->getMessage()];
            } 
            
            $this->log(['Connection error' => $this->lastError]);
            return $answer;
        }

        public function __destruct(){
            $this->destroyConnection();
        }

        /**
         * --RU--
         * Закрыть соединение
         */
        public function destroyConnection(){
            $this->log('destroyConnection');
            if(is_object($this->URLConnection))$this->URLConnection->disconnect();
            $this->URLConnection = NULL;
        }

        /**
         * --RU--
         * Получить время выполнения запроса (в миллисекундах)
         * @return int
         */
        public function getExecuteTime(){
            return Time::Now()->getTime() - $this->timeStart;
        }
        
        /**
         * --RU--
         * Получить информацию о запросе
         * @return array [url, responseCode, responseMessage, contentLength, contentEncoding, expiration, lastModified, usingProxy, executeTime, requestHeaders, responseHeaders, requestLength]
         */
        public function getConnectionInfo(){
            return $this->connectionInfo;
        }

        /**
         * --RU--
         * Получить информацию об ошибках
         * @return array [code, error] || false
         */
        public function getError(){
            return $this->lastError;
        }        

        /**
         * --RU--
         * Установка URL
         */
        public function setUrl($url){
            $this->opts['url'] = $url;
        }

        /**
         * --RU--
         * Установка таймаута подключения (мс)
         */
        public function setConnectTimeout($timeout){
            $this->opts['connectTimeout'] = $timeout;
        }

        /**
         * --RU--
         * Установка таймаута чтения данных (мс)
         */
        public function setReadTimeout($timeout){
            $this->opts['readTimeout'] = $timeout;
        }

        /**
         * --RU--
         * Установка типа HTTP запроса
         * @param string $method - GET|POST|PUT|DELETE|etc...
         */
        public function setRequestMethod($method){
            $this->opts['requestMethod'] = $method;
        }

        /**
         * --RU--
         * Вкл/выкл переадресацию по заголовкам Location: ...
         */
        public function setFollowRedirects($follow){
            $this->opts['followRedirects'] = $follow;
        }

        /**
         * --RU--
         * Вкл/выкл автоматическую подстановку заголовков Referer: ...
         */
        public function setAutoReferer($follow){
            $this->opts['autoReferer'] = $follow;
        }

        /**
         * --RU--
         * Установка user-agent
         */
        public function setUserAgent($ua){
            $this->opts['userAgent'] = $ua;
        }

        /**
         * --RU--
         * Установка типа прокси сервера
         * @param string $type - HTTP|SOCKS
         */
        public function setProxyType($type){
            $this->opts['proxyType'] = $type;
        }

        /**
         * --RU--
         * Установка адреса прокси сервера
         * @param string $proxy - ip:port (127.0.0.1:8080)
         */
        public function setProxy($proxy){
            $this->opts['proxy'] = $proxy;
        }

        /**
         * --RU--
         * Установка размера буфера обмена данными
         */
        public function setBufferLength($type){
            $this->opts['bufferLength'] = $type;
        }

        /**
         * --RU--
         * Установка файла для хранения кук
         * @param string $file
         */
        public function setCookieFile($file){
            $this->opts['cookieFile'] = $file;
        }

        /**
         * --RU--
         * Установка отправляемых HTTP-заголовков
         * @param array $headers [['Header1', 'Value1'], ['Header2', 'Value2']]
         */
        public function setHttpHeader($headers){
            $this->opts['httpHeader'] = $headers;
        }

        /**
         * --RU--
         * Добавляет отправляемый HTTP-заголовок
         * @param string $header - имя заголовка
         * @param string $value - значение
         */
        public function addHttpHeader($header, $value){
            $this->opts['httpHeader'][] = [$header, $value];
        }

        /**
         * --RU--
         * Установка Basic-авторизации
         * @param string $auth - "login:password" || false
         */
        public function setBasicAuth($auth){
            $this->opts['basicAuth'] = $auth;
        }

        /**
         * --RU--
         * Установка заголовка Referer
         * @param string $ref - http://site.com/
         */
        public function setHttpReferer($ref){
            $this->opts['httpReferer'] = $ref;
        }

        /**
         * --RU--
         * Добавлять HTTP-заголовки к ответу
         * @param bool $return
         */
        public function setReturnHeaders($return){
            $this->opts['returnHeaders'] = $return;
        }

        /**
         * --RU--
         * Установка файла, куда будет сохранён ответ с сервера (например, при скачивании файла)
         * @param string $file - path/to/file
         */
        public function setOutputFile($file){
            $this->opts['outputFile'] = $file;
        }
        // alias //
        public function setFileStream($file){
            $this->opts['outputFile'] = $file;
        }

        /**
         * --RU--
         * Установка файла, откуда будут считываться данные в тело запроса (например, при загрузка файла на сервер методом PUT)
         * @param string $file - path/to/file
         */
        public function setInputFile($file){
            $this->opts['inputFile'] = $file;
        }
        // alias //
        public function setBodyFile($file){
            $this->opts['inputFile'] = $file;
        }

        /**
         * --RU--
         * Данные, которые будут отправлены в теле запроса
         * @param string $data
         */
        public function setBody($data){
            $this->opts['body'] = $data;
        }

        /**
         * --RU--
         * Отправляемые данные, которые нужно преобразовать в POST-запрос
         * @param array $data - ['key' => 'value']
         */
        public function setPostData($data){
            $this->opts['postData'] = $data;
        }

        /**
         * --RU--
         * Файлы, которые будут отправлены на сервер с заголовком "multipart/form-data" (например, при POST-загрузке файлов)
         * @param array $files - ['name' => 'path/to/file']
         */
        public function setPostFiles($file){
            $this->opts['postFiles'] = $file;
        }

        /**
         * --RU--
         * Добавляет файлы, которые будут отправлены на сервер с заголовком "multipart/form-data" (например, при POST-загрузке файлов)
         * @param array $files - ['name' => 'path/to/file']
         */
        public function addPostFiles($files){
            foreach ($files as $key => $value) {
                $this->opts['postFiles'][$key] = $value;
            }
        }

        /**
         * --RU--
         * Добавляет файл, который будет отправлен на сервер с заголовком "multipart/form-data" (например, при POST-загрузке файлов)
         * @param string $name - имя
         * @param string $filepath - пуит к файлу
         */
        public function addPostFile($name, $filepath){
            $this->opts['postFiles'][$name] = $filepath;
        }

        /**
         * --RU--
         * Установка функции, которая будет вызываться при скачивании/загрузке файлов
         * @param callable $func
         */
        public function setProgressFunction($func){
            $this->opts['progressFunction'] = $func;
        }

        /**
         * --RU--
         * Установить массив параметроа
         * @param array $data [$key => $value]
         */
        public function setOpts($data){
            foreach($data as $k=>$v){
                $this->setOpt($k, $v);
            }
        }
        
        /**
         * --RU--
         * Установить значение параметра
         * @param string $key - параметр
         * @param mixed $value - значение
         */
        public function setOpt($key, $value){
            $func = 'set' . $key;
            $this->$func($value);
        }

        // private
        private function getConnectionParam($param){
            return (is_object($this->URLConnection)) ? ($this->URLConnection->{$param}) : NULL;
        }

        private function arrayMerge($a1, $a2){
            foreach($a2 as $k=>$v){
                if(isset($a1[$k]) and is_array($a1[$k])){
                    $a1[$k] = $this->arrayMerge($a1[$k], $v);
                }
                elseif(is_numeric($k)) $a1[] = $v;
                else $a1[$k] = $v;
            }

            return $a1;
        }

        private function detectCharset($header){
            if(is_string($header)){
                $reg = 'charset=([a-zA-Z0-9-_]+)';
                $regex = Regex::of($reg, Regex::CASE_INSENSITIVE)->with($header);
                if($regex->find()){
                    return $this->charset = Str::Upper(Str::Trim($regex->group(1)));
                }
            }
            return $this->charset = 'UTF-8';
        }

        private function createConnection(){
            //Устанавливаем прокси-соединение
            if(isset($this->opts['proxy']) and $this->opts['proxy'] !== false){
                $ex = Str::Split($this->opts['proxy'], ':');
                $proxy = new Proxy($this->opts['proxyType'], $ex[0], $ex[1]);
            }
                
            $this->log(['Options' => $this->opts]);

            $this->URLConnection = URLConnection::Create($this->opts['url'], $proxy);
            $this->URLConnection->doInput = true;
            $this->URLConnection->doOutput = ($this->opts['body'] !== false || $this->opts['postData'] !== false || $this->opts['postFiles'] !== false);       
            $this->URLConnection->followRedirects = false; //Встроенные редиректы не дают возможность обработать куки, придётся вручную обрабатывать заголовки Location: ... 

            $this->log(['createConnection', $this->opts['url'], ['proxy' => $proxy]]);
        }

        /*
         * Сброс переменных, отвечающих за буфер, его размер и размер пересылаемых и получаемых данных
         */
        private function resetBufferParams(){
            $this->requestLength = 0;
            $this->responseLength = 0;
            $this->buffer = new MemoryStream;
        }

        /*
         * Сброс всех переменных, характеризующих даннное соединение
         */
        private function resetConnectionParams(){
            $this->resetBufferParams();
            $this->lastError = false;

            $this->timeStart = Time::Now()->getTime();
            $this->charset = NULL;
            $this->connectionInfo = [];
            $this->requestHeaders = [];
            $this->responseHeaders = [];
        }

        /*
         * Читает данные из входящего потока в буфер
         */
        private function loadToBuffer($input){
            $data = $input->read($this->opts['bufferLength']);
            $this->responseLength += Str::Length($data);

            if($this->opts['outputFile'] instanceof FileStream){
                $this->opts['outputFile']->write($data);
            }
            else $this->buffer->write($data);

            $this->callProgressFunction($this->getConnectionParam('contentLength'), $this->responseLength, $this->responseLength, $this->responseLength);
        }

        /*
         * Читает входной поток данных
         */
        private function getInputData(){
            $this->resetBufferParams();

            if(isset($this->opts['outputFile']) and $this->opts['outputFile'] !== false){
                $this->opts['outputFile'] = ($this->opts['outputFile'] instanceof FileStream) ? $this->opts['outputFile'] : FileStream::of($this->opts['outputFile'], 'w+');
            }
            else $this->opts['outputFile'] = false;

            $in = $this->URLConnection->getInputStream();
            $this->loadToBuffer($in);

            while(!$in->eof()){
                $this->loadToBuffer($in);
            }   
            $this->callProgressFunction($this->getConnectionParam('contentLength'), $this->getConnectionParam('contentLength'), $this->responseLength, $this->responseLength);
         

            return $this->buffer;
        }

        /*
         * Отправляет файлы в выходной поток данных
         */
        private function sendOutputData($files, $boundary){
            $this->resetBufferParams();

            $out = $this->URLConnection->getOutputStream();
            $totalSize = 0;
            // Для начала узнаем общий размер файлов для progressFunction
            foreach ($files as $file) {
                $s = new FileStream($file, 'r+');
                $totalSize += $s->length();
                $s->close();
            }

            foreach ($files as $fKey => $file) {

                $fileName = File::of($file)->getName();

                $out->write("--$boundary");
                $out->write(self::CRLF);
                $out->write("Content-Disposition: form-data; name=\"$fKey\"; filename=\"$fileName\"");
                $out->write(self::CRLF);
                $out->write("Content-Type: " . URLConnection::guessContentTypeFromName($fileName));
                $out->write(self::CRLF);
                $out->write("Content-Transfer-Encoding: binary");
                $out->write(self::CRLF);
                $out->write(self::CRLF);
                                    
                $fStream = new FileStream($file, 'r+');

                $this->sendData($out, $fStream, $totalSize);
                while(!$fStream->eof()){
                    $this->sendData($out, $fStream, $totalSize);
                }
                
                $fStream->close();

                $out->write(self::CRLF);
            }
        
            $out->write("--$boundary--");
            $out->write(self::CRLF);
        }

        /*
         * Через буфер отправляет данные в исходящий поток
         */
        private function sendData($out, $fileStream, $totalSize){
            $data = $fileStream->read($this->opts['bufferLength']);
            $this->requestLength += Str::Length($data);

            $out->write($data);

            $this->callProgressFunction(0, 0, $totalSize, $this->requestLength);
        }

        /*
         * На основе текущего url и полученного заголовка Location
         * генерирует новое URL для перенаправления
         * @return string
         */
        private function getLocationUrl($url, $location){            
            if(Str::contains($location, '://')){
                return $location;
            }
            
            $tmp1 = Str::Split($url, '://', 2);
            
            if(Str::Sub($location, 0, 1) == '/'){    
                return $tmp1[0] . '://' . explode('/', $tmp1[1])[0] . $location;
            }
            elseif(Str::Sub($location, 0, 1) == '?'){    
                return Str::Split($url, '?', 2)[0] . $location;
            }
            elseif(Str::Sub($location, 0, 2) == './'){
                $location = Str::Sub($location, 2, Str::Length($location));
            }

            $tmp2 = explode('/', $tmp1[1]);
            $tmp2[sizeof($tmp2)-1] = $location;

            return $tmp1[0] . '://' . implode('/', $tmp2);
        }
        
        /*
         * Парсит json-файл с куками
         * @return array
         */
        private function loadCookies($file){
            if(Stream::Exists($file)){
                $cookies = Stream::getContents($file);
                $r = json_decode($cookies, true);
                if(is_array($r)) return $r;
            }    
            
            return [];
        }

        /*
         * Выбирает из массива кук только для определенного домена
         * @return string - строка с куками для header
         */
        private function getCookiesForDomain($cookies, $domain){
            $cooks = isset($cookies[$domain])?$cookies[$domain]:[];
            $cookieString = [];
            $now = Time::Now()->getTime() / 1000;
            
            foreach($cooks as $key=>$value){
                if($value['expires'] >= $now){
                    $cookieString[] = $key . '=' . $value['value'];
                }
            }

            if(sizeof($cooks) == 0)return null;
            
            return implode('; ', $cookieString) . ';';
        }

        /*
         * Объединяет те куки, которые были сохранены
         * с новыми, полученными с сайта + удаляет просроченные куки
         *
         * @return string - json строка для хранения кук
         */
        private function uniteCookies($oldCookies, $newCookies){
            $now = Time::Now()->getTime() / 1000;
            
            foreach($newCookies as $domain => $cooks){
                foreach($cooks as $key => $value){
                    if($value['expires'] < $now){
                        if(isset($oldCookies[$domain][$key])) return $oldCookies[$domain][$key];
                    }else{
                        $oldCookies[$domain][$key] = $value;
                    }
                }

                if(sizeof($oldCookies[$domain]) == 0) unset($oldCookies[$domain]);
            }

            return json_encode($oldCookies);
        }

        /*
         * Парсит полученные с сервера куки в формат для хранения
         * $defaultDomain - для какого домена по умолчанию установить куки
         * return array
         */
        private function parseCookies($cookies, $defaultDomain){
            $return = [];
            
            foreach($cookies as $cookie){
                $parts = Str::split($cookie, ';');
                
                $tmp = [];
                $key = null;
                $value = null;
                
                foreach($parts as $k => $part){
                    $ex = Str::split(Str::Trim($part), '=');
                    if($k == 0){
                        $key = $ex[0];
                        $value = $ex[1];
                        continue;
                    }
                    $tmp[$ex[0]] = $ex[1];
                }

                $domain = isset($tmp['domain']) ? $tmp['domain'] : $defaultDomain;
                $dFormatA = 'EEE, dd-MMM-yyyy HH:mm:ss zzz';
                $dFormatB = 'EEE, dd MMM yyyy HH:mm:ss zzz';

                $time = (new TimeFormat($dFormatA, new Locale('en')))->parse($tmp['expires']);
                if(!is_object($time)){
                    $time = (new TimeFormat($dFormatB, new Locale('en')))->parse($tmp['expires']);
                }

                $expires = (isset($tmp['expires']) AND is_object($time))
                    ? ($time->getTime()) 
                    : (Time::Now()->getTime() + 60*60*24*365*1000);
                    
                $expires = round($expires / 1000);

                $return[$domain][$key] = [
                    'value' => $value,
                    'expires' => $expires
                ];
            }

            return $return;
        }

        /**
         * Генерирует дефолтный User-Agent
         */
        private function genUserAgent(){
            return 'jURL/'.$this->version.' (Java/'. System::getProperty('java.version') .'; '. System::getProperty('os.name') .'; DevelNext)';
        }

        /**
         * Вызывает функцию, переданную для определения прогресса загрузки файла
         */
        private function callProgressFunction($dlTotal, $dl, $ulTotal, $ul){
            if(!isset($this->opts['progressFunction']) || !is_callable($this->opts['progressFunction'])) {
                return;
            }

            if(UXApplication::isUiThread()){
                $this->opts['progressFunction']($this, $dlTotal, $dl, $ulTotal, $ul);
            }
            else{
                UXApplication::runLater(function() use ($dlTotal, $dl, $ulTotal, $ul){
                    $this->opts['progressFunction']($this, $dlTotal, $dl, $ulTotal, $ul);
                });
            }
        }

        public function buildQuery($a,$b='',$c=0){
            if (!is_array($a)) return $a;

            foreach ($a as $k=>$v){
                if($c){
                    if( is_numeric($k) ){
                        $k=$b."[]";
                    }
                    else{
                        $k=$b."[$k]";
                    }
                }
                else{   
                    if (is_int($k)){
                        $k=$b.$k;
                    }
                }

                if (is_array($v)||is_object($v)){
                    $r[] = $this->buildQuery($v,$k,1);
                        continue;
                }

                $r[] = urlencode($k) . "=" . urlencode($v);
            }
            return implode("&",$r);
        }

        private function Log($data){
            if(self::LOG) Logger::Debug('[jURL] ' . var_export($data, true));
        }

        // На случай, если модуль подключён к форме, чтоб не было ошибки
        public function getScript(){
            return null;
        }

        public function apply(){
            return null;
        }
    }
}