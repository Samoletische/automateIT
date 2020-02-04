<?php

namespace Clients;

class Web {

  const TIMEOUT = 3; // если 3 минуты нет 'вестей' от Сборщика то выходим из сборки данных

  private $spiders;
  private $params;
  //-----------------------------------------------------

  function __construct($spiders) {
    $this->params = array();
    $this->spiders = $spiders;
  }
  //-----------------------------------------------------

  function __destruct() {
    foreach($this->spiders as $spider)
      unset($spider);
  }
  //-----------------------------------------------------

  public function addTask($task) {
    $conf = Conf::getConf();

    $params = System::SendRequest($conf->server.'/start.php?', array('command' => 'getJSON', 'param' => $task));
    if (!\is_null($params)
        && array_key_exists('result', $params)
        && ($params['result'] == 'error')
        && (array_key_exists('message', $params))) {
      System::insertLog('getting params error: '.$params['message']);
      return;
    }

    if ($this->checkParams($params))
      array_unshift($this->params, $params);
  }
  //-----------------------------------------------------

  public function monitor() {
    System::insertLog('monitor');

    if (count($this->params) == 0)
      return;

    $conf = Conf::getConf();

    System::insertLog("new task exists ({$this->params[0]['pageName']}), searching free Spider...");
    System::insertLog("count of spiders: ".count($this->spiders));
    foreach($this->spiders as $spider) {
      System::insertLog("getStatus from {$spider['port']}");
      $status = $this->sendCommandToSpider($spider, 'getStatus');
      System::insertLog("got status '$status'");

      if ($status == 'ready') {
        System::insertLog("spider {$spider['port']} is ready, starting collect...");
        $params = \array_shift($this->params);
        $result = $this->sendCommandToSpider($spider, 'collect', array($params, $conf->server, $spider['serverSelenium']));
        if (!$result) {
          array_unshift($this->params, $params);
          System::insertLog("can't set params to Spider");
          continue;
        }
        System::insertLog("collect is started on spider: {$spider['port']}");
        break;
      }
    }
  }
  //-----------------------------------------------------

  public function collect($params) {
    // if (!$this->setParams($params)) {
    //   System::insertLog("can't set params");
    //   return;
    // }
    //
    // foreach($this->spiders as $spider) {
    //   System::insertLog("getStatus");
    //   $status = $this->sendCommandToSpider($spider, 'getStatus');
    //   System::insertLog("'$status'");
    //
    //   if ($status != 'ready')
    //     continue;
    //
    //   System::insertLog("collect");
    //   $this->sendCommandToSpider($spider, 'collect', array('params' => $this->params));
    // }
  }
  //-----------------------------------------------------

  private function sendCommandToSpider($spider, $command, $additionParams=NULL, $viaHTTP=false) {
    $result = NULL;
    $params = array('command' => $command, 'params' => '');

    if (!is_null($additionParams))
      $params['params'] = $additionParams;

    $answer = $viaHTTP ? System::sendRequest($spider['url'], $params) : $this->sendRequest($spider, $params);

    if (!\is_null($answer) && \is_array($answer) && \array_key_exists('result', $answer))
      $result = $answer['result'];

    return $result;
  }
  //-----------------------------------------------------

  private function sendRequest($spider, $params) {
    if (is_null($spider['socket'])) {
      System::insertLog("creating socket on {$spider['port']}");
      $spider['socket'] = socket_create(AF_INET, SOCK_STREAM, 0);
      if (!\socket_connect($spider['socket'], $spider['addr'], $spider['port']))
        $spider['socket'] = NULL;
    }
    $message = \json_encode($params);
    //System::insertLog($message);
    //if (\socket_connect($spider['socket'], $spider['addr'], $spider['port'])) {
      \socket_write($spider['socket'], $message);
      System::insertLog("message sent");
      $response = \socket_read($spider['socket'], 1024);
      System::insertLog($response);
      if (($response !== FALSE) && ($response != '')) {
        $answer = \json_decode($response, true);
        if (\json_last_error() == JSON_ERROR_NONE)
          return $answer;
      }
    //}

    return NULL;
  }
  //-----------------------------------------------------

  private function checkParams($params) {
    $conf = Conf::getConf();

    if (!file_exists(dirname(__FILE__).'/'.$conf->webJsonEthalon)) {
      System::insertLog("Ethalon web.json file '{$conf->webJsonEthalon}' not exists");
      return false;
    }

    $ethalon = json_decode(file_get_contents(dirname(__FILE__).'/'.$conf->webJsonEthalon), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      System::insertLog("Can't load ethalon web.json file '{$conf->webJsonEthalon}'. Bad JSON format.");
      return false;
    }

    // check for nessesary fields in JSON
    if ((empty($params)) || (!is_array($params))
        || (empty($ethalon)) || (!is_array($ethalon))) {
      System::insertLog($params['pageName'].': Bad web.json structure');
      return false;
    }

    $firstNotExistingField = '';
    if (!System::checkJsonStructure($ethalon, $params, $firstNotExistingField)) {
      System::insertLog($params['pageName'].": Bad web.json structure: not exists '$firstNotExistingField' field");
      return false;
    }

    return true;
  }
  //-----------------------------------------------------

}
//-----------------------------------------------------

?>
