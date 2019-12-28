<?php

namespace Clients;

require_once('Spider.php');
require_once(__DIR__.'/lib/Workerman/Autoloader.php');

use Workerman\Worker;

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('TIMEOUT', 80);
//-----------------------------------------------------

if (isset($argv)) { // запуск готового Сборщика из консоли
  echo date('h:i:s')." - start spider\n";
  // можно положить pid в отдельный файл
  //echo getmypid()."\n";
  if (count($argv) < 3) {
    echo date('h:i:s')." - count of argv < 4 - exit\n";
    exit();
  }

  $spider = new Spider($argv[3]);
  // переданы некорректные данные и поэтому закрываем Сборщика
  if (is_null($spider)) {
    echo date('h:i:s')." - can't start Spider - exit\n";
    exit();
  }

  echo "starting worker at tcp://$argv[2]:$argv[3]\n";
  $tcp_worker = new Worker("tcp://$argv[2]:$argv[3]");
  $tcp_worker->count = 2;
  $tcp_worker->onConnect = function($connection) {
    echo "connection opened\n";
  };
  //-----------------------------------------------------
  $tcp_worker->onMessage = function($connection, $data) {

    global $spider;

    $status = $spider->getStatus();
    echo "spider status - $status\n";
    $commands = json_decode($data, true);
    $result = array('result' => NULL);
    if ((json_last_error() == JSON_ERROR_NONE)
        && array_key_exists('method', $commands)
        && array_key_exists('params', $commands)) {
      $method = $commands['method'];
      echo "receive command '{$method}'\n";
      switch ($method) {
        case 'close':
          echo "receive close-command, exiting...\n";
          exit();
          Worker::stopAll();
        case 'collect':
          if (!method_exists($spider, $method))
            break;
          if (count($commands['params']) > 1)
            $result['result'] = $spider->$method($commands['params'][0]);
          break;
        default:
          if (!method_exists($spider, $method))
            break;
          echo "is method an array: {$method} - ".is_array($method)."\n";
          $result['result'] = $spider->$method();
      }
    }
    $connection->send(json_encode($result));

  };
  //-----------------------------------------------------
  $tcp_worker->onClose = function($connection) {
    echo "connection closed\n";
  };
  //-----------------------------------------------------
  Worker::runAll();

  //echo date('h:i:s').' - exit';
}
else { // команды управления Сборщиком через HTTP
  $content = file_get_contents('php://input');
  $in = json_decode($content, true);

  if ((isset($in)) && (array_key_exists('command', $in)))
    switch ($in['command']) {
      case 'kill':
        echo \json_encode(killSpy($in));
        break;
      case 'areYouReady':
        echo \json_encode(areYouReady($in));
        break;
      case 'getStatus':
        echo \json_encode(getStatus($in));
        break;
      case 'setStatus':
        echo \json_encode(setStatus($in));
        break;
      case 'setCurrPage':
        echo \json_encode(setCurrPage($in));
        break;
      case 'getNextPage':
        echo \json_encode(getNextPage($in));
        break;
      case 'collect':
        echo \json_encode(collect($in));
        break;
      default:
        echo \json_encode(array('result' => 'bad command'));
    }
  else
    echo \json_encode(array('result' => 'bad command'));
}
//-----------------------------------------------------

function killSpy($in) {

  $result = array('result' => true, 'message' => '');

  if (array_key_exists('port', $in)) {
    $result['message'] = system("kill {$in['port']}");
    $result['result'] = ($result['message'] === FALSE) ? false : true;
  }

  return $result;

} // killSpy
//-----------------------------------------------------

function areYouReady($in) {

  $result = array('result' => false, 'message' => '');

  if (array_key_exists('addr', $in) && array_key_exists('port', $in) && array_key_exists('serverSelenium', $in)) {
    $result['message'] = 'all keys exists';
    // проверяем готовность Сборщика
    $result['result'] = Spider::ReadyToUse($in['serverSelenium']);

    if ($result['result']) {
      // запускаем Сборщик
      system("php -f spy.php start ".$in['addr'].' '.$in['port']." > ".$in['port'].".log.txt 2>&1 &");
    }
  }

  return $result;

} // areYouReady
//-----------------------------------------------------

function sendCommand($token, $command) {
  $result = false;
  $filename = $token.'.in.txt';
  $lastResponse = time();
  while(true) {
    if (!file_exists($filename)) {
      $f = fopen($filename, 'w');
      if ($f) {
        fwrite($f, $command);
        fclose($f);
        $result = true;
        break;
      }
    }

    if (($lastResponse + TIMEOUT) < time())
      break;

    sleep(1);
  }

  return $result;
} // sendCommand
//-----------------------------------------------------

function getAnswer($token) {
  $result = '';
  $filename = $token.'.out.txt';
  $lastResponse = time();
  while(true) {
    if (file_exists($filename)) {
      $f = fopen($filename, 'r');
      $result = fgets($f, filesize($filename) + 1);
      fclose($f);
      unlink($filename);
      break;
    }

    if (($lastResponse + TIMEOUT) < time())
      break;

    sleep(1);
  }

  return json_decode($result, true);
} // getAnswer
//-----------------------------------------------------

function setAnswer($token, $answer) {
  $result = false;
  $filename = $token.'.out.txt';
  $lastResponse = time();
  while(true) {
    if (!file_exists($filename)) {
      $f = fopen($filename, 'w');
      echo "\n";
      fwrite($f, json_encode($answer));
      fclose($f);
      $result = true;
      break;
    }

    if (($lastResponse + TIMEOUT) < time())
      break;

    sleep(1);
  }
  return $result;
} // setAnswer
//-----------------------------------------------------

  function getStatus($in) {

  $result = array('result' => NULL);

  if (array_key_exists('token', $in)) {
    if (sendCommand($in['token'], 'getStatus'))
      $result['result'] = getAnswer($in['token']);
  }

  return $result;

} // getStatus
//-----------------------------------------------------

function setStatus($in) {

  $result = array('result' => NULL);

  if ((array_key_exists('token', $in))
      && (array_key_exists('status', $in))) {
    if (sendCommand($in['token'], 'setStatus,'.$in['status']))
      $result['result'] = getAnswer($in['token']);
  }

  return $result;

} // setStatus
//-----------------------------------------------------

function collect($in) {

  $result = array('result' => '');

  if (array_key_exists('token', $in) && array_key_exists('pageNum', $in))
    if (sendCommand($in['token'], 'collect,'.$in['pageNum']))
      $result['result'] = getAnswer($in['token']);

  return $result;

} // collect
//-----------------------------------------------------
?>
