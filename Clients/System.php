<?php

namespace Clients;

require_once('Web.php');
require_once(__DIR__.'/../lib/Workerman/Autoloader.php');

use Workerman\Worker;

abstract class System {

  /**
  * Кодирует получаемый через параметр контент, отправляет его в формате JSON
  * в php-скрипт, адрес которого передан через параметр
  * Параметры:
  *   $url      - string  - адресная строка до php-скрипта
  *   $content  - array   - параметризированный массив
  * Возвращаемые значения:
  *   array - ответ от php-скрипта в виде параметризированного массива.
  *   Если входящий контент или ответ невозможно кодировать в/декодировать из JSON, то возвращается NULL.
  */
  static function sendRequest($url, $content) {
    $json = json_encode($content);

    if (json_last_error() === JSON_ERROR_NONE) {
      $context = stream_context_create(array(
        'http' => array(
          'method'  => 'POST',
          'header'  => 'Content-type: application/json',
          'content' => $json
        )
      ));
      $response = file_get_contents($url, false, $context);
      //System::insertLog($response);
      $result = json_decode($response, true);
      //print_r($result);
      if (json_last_error() === JSON_ERROR_NONE)
        return $result;
      else
        return NULL;
    }
    else
      return NULL;
  } // System::sendRequest
  //-----------------------------------------------------

  /**
  * Создаёт экземпляр класса Web, предварительно проверив файл параметров типа web.json
  * на корректность структуры.
  * Параметры:
  *   $starterIP  - string  - ip-адрес стартера
  *   $error    - string  - при возникновении ошибки сюда запишется её текст
  * Возвращаемые значения:
  *   Web - объект, если ошибок не было.
  *   Если были ошибки (проблемы с файлом настроек), то возвращается NULL.
  */
  static function createWeb($starterIP, $starterPort, &$error='') {

    $conf = Conf::getConf();

    $spiders = array();
    foreach($conf->spiders as $spy) {
      //System::insertLog($spy['port']);
      $spyReady = true;
      $socket = \socket_create(AF_INET, SOCK_STREAM, 0);
      if ($socket === FALSE)
        System::insertLog("can't create socket: ".socket_strerror(\socket_last_error($socket)));

      if (!\socket_connect($socket, $spy['addr'], $spy['port'])) {
        $spyReady = false;
        System::insertLog("spy not ready, starting...");
        $result = System::sendRequest(
          $spy['url'],
          array(
            'command' => 'areYouReady',
            'addr' => $spy['addr'],
            'port' => $spy['port'],
            'serverSelenium' => $spy['serverSelenium'],
            'starterAddr' => $starterIP,
            'starterPort' => $starterPort
          )
        );
        //print_r($result);
        if (!\is_null($result) && \array_key_exists('result', $result) && ($result['result'])) {
          System::insertLog(\is_numeric($spy['addr']).' - '.\is_numeric($spy['port']));
          System::insertLog("spy started, connecting... tcp://{$spy['addr']}:{$spy['port']}");
          \sleep(1);
          $socket = \socket_create(AF_INET, SOCK_STREAM, 0);
          $spyReady = \socket_connect($socket, $spy['addr'], $spy['port']);
        }
      }
      if ($spyReady) {
        System::insertLog("spy {$spy['port']} ready");
        $spiders[] = array(
          'addr' => $spy['addr'],
          'port' => $spy['port'],
          'url' => $spy['url'],
          'serverSelenium' => $spy['serverSelenium'],
          'socket' => NULL
        );
      }
      //sleep(1);
    }

    // нет смысла продолжать, если некому собирать инфу
    if (count($spiders) == 0)
      return NULL;

    return new Web($spiders, $starterIP, $starterPort);

  }
  //-----------------------------------------------------

  /**
  *  Сравнивает две структуры параметризированных массивов. Одна структура эталонная, другая - проверяемая.
  *  Параметры:
  *    $ethalon                - array   - параметризированный массив эталонной структуры
  *    $check                  - array   - параметризированный массив проверяемой структуры
  *    $firstNotExistingField  - string  - при отсутствии в проверяемой структуре поля из эталонной
  *                                        сюда запишется его (поля) имя
  *  Возвращаемые значения:
  *    bool - флаг идентичности структур.
  */
  static function checkJsonStructure($ethalon, $check, &$firstNotExistingField) {

    foreach ($ethalon as $key => $value) {
      if (!array_key_exists($key, $check)) {
        $firstNotExistingField = $key;
        return false;
      }
      if (is_array($ethalon[$key]))
        if (!self::checkJsonStructure($ethalon[$key], $check[$key], $firstNotExistingField))
          return false;
    }

    return true;

  }
  //-----------------------------------------------------

  /**
  *
  */
  static function insertLog($message, $fromSystem=false) {
    $index = $fromSystem ? 1 : 0;
    $back = \debug_backtrace();
    $path = explode('/', $back[$index]['file']);
    $filename = explode('.', $path[count($path) - 1]);
    $file = $filename[0];
    $line = $back[$index]['line'];
    echo date("H:i:s")." - $file ($line): $message".PHP_EOL;
  }
  //-----------------------------------------------------

  /**
  *
  */
  static function showCalledFrom() {
    $back = \debug_backtrace();
    $path = explode('/', $back[1]['file']);
    $filename = explode('.', $path[count($path) - 1]);
    $file = $filename[0];
    $line = $back[1]['line'];
    System::insertLog("called from $file ($line)", true);
  }
  //-----------------------------------------------------

  static function saveArrayToFile($array, $file) {
    if (\file_exists($file))
      if (!\unlink($file))
        return false;

    $json = \json_encode($array);
    if ($json === FALSE)
      return false;

    if (\file_put_contents($file, $json) === FALSE)
      return false;

    return true;
  }
  //-----------------------------------------------------

  static function loadArrayFromFile($file) {
    if (!\file_exists($file))
      return NULL;

    $json = \file_get_contents($file);
    if ($json === FALSE)
      return NULL;

    $array = \json_decode($json, true);
    if (\json_last_error() !== JSON_ERROR_NONE)
      return NULL;

    return $array;
  }
  //-----------------------------------------------------

}
//-----------------------------------------------------

Class Conf {
  static $instance = NULL;
  public $server;
  public $serverSelenium;
  public $spiders;
  public $webJsonEthalon;
  //-----------------------------------------------------

  static function getConf() {
    if (empty(self::$instance)) {
      $conf = json_decode(file_get_contents(dirname(__FILE__).'/../conf/conf_c.json'), true);
      if ((json_last_error() == JSON_ERROR_NONE)
          && array_key_exists('server', $conf)
          //&& array_key_exists('serverSelenium', $conf)
          && array_key_exists('spiders', $conf)
          && is_array($conf['spiders'])
          && array_key_exists('webJsonEthalon', $conf)) {

        $mayCreate = true;
        foreach($conf['spiders'] as $spy)
          if (!array_key_exists('addr', $spy)
              || !array_key_exists('port', $spy)
              || !array_key_exists('url', $spy)
              || !array_key_exists('serverSelenium', $spy)) {
            $mayCreate = false;
            break;
          }
        if ($mayCreate)
          self::$instance = new Conf($conf);
      }
    }
    return self::$instance;
  }
  //-----------------------------------------------------

  private function __construct($conf) {
    $this->server = $conf['server'];
    //$this->serverSelenium = $conf['serverSelenium'];
    $this->spiders = $conf['spiders'];
    $this->webJsonEthalon = $conf['webJsonEthalon'];
  }
  //-----------------------------------------------------
} // Conf
//-----------------------------------------------------

?>
