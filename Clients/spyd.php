<?php

namespace Clients;

require_once('Spider.php');
require_once(__DIR__.'/../lib/Workerman/Autoloader.php');

use Workerman\Worker;
use Workerman\Lib\Timer;

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('TIMER', 3);
//-----------------------------------------------------

if (PHP_SAPI !== 'cli')
  exit();

if (isset($argv)) {
  insertLog("start spider");
  if (count($argv) < 5) {
    insertLog("count of argv < 5 - exit");
    exit();
  }

  if ($argv[1] != "start") {
    insertLog("bad command, exiting...");
    exit();
  }

  $spyStr = "php {$argv[3]}/spyw.php start ".$argv[2].' '.($argv[3]+1)." > ".($argv[3]+1).".log.txt 2>&1 &";
  insertLog("starting: $spyStr");
  $spyResult = system($spyStr);
  insertLog("system res: '$spyResult' - is it FALSE: ".($spyResult === FALSE));
  if ($spyResult === FALSE) {
    insertLog("can't start spy worker, exiting...");
    exit();
  }

  system('echo '.getmypid().' > '.$argv[3].'/'.$argv[3].'_pid.txt');

  $status = 'ready';
  $spiderSocket = NULL;
  $spiderAddr = $argv[2];
  $spiderPort = $argv[3]+1;
  $currPort = $argv[3];
  $webSocket = NULL;
  $webAddr = $argv[4];
  $webPort = $argv[5];

  insertLog("starting worker at tcp://$spiderAddr:$currPort");
  $tcp_worker = new Worker("tcp://$spiderAddr:$currPort");
  $tcp_worker->count = 2;

  $timer = Timer::add(TIMER, function() {
    monitor();
  });

  $tcp_worker->onConnect = function($connection) {
    insertLog("connection opened");
  };

  $tcp_worker->onMessage = function($connection, $data) {
    global $status, $spiderAddr, $currPort;

    $result = array('result' => NULL);
    $sendAnswer = true;
    $commands = json_decode($data, true);
    if ((json_last_error() == JSON_ERROR_NONE)
        && array_key_exists('command', $commands)
        && array_key_exists('params', $commands)) {
      $command = $commands['command'];
      insertLog("receive command '{$command}'");
      switch ($command) {
        case 'getStatus':
          $result['result'] = $status;
          insertLog("current status '$status'");
          break;
        case 'collect':
          // save params to file
          // if (!Spider::saveArrayToFile($commands['params'], "web_$currPort.json")) {
          //   insertLog("can't save params to file");
          //   $status = Spider::ERROR;
          //   break;
          // }

          // set params to spider
          if (!sendToSpider('setParams', $commands['params'])) {
            insertLog("can't set params to spider");
            $status = Spider::ERROR;
            break;
          }

          // start collect
          if (!sendToSpider($command, $commands['params'])) {
            insertLog("can't starting collect");
            $status = Spider::ERROR;
            break;
          }

          // setting daemon status
          $status = Spider::COLLECTING;
          $result['result'] = true;

          break;
        case 'setStatus':
          insertLog("get new status - {$commands['params']}");
          switch ($commands['params']) {
            case Spider::COLLECTED:
              $connection->send('ok');
              $sendAnswer = false;
              $status = Spider::COLLECTED;
              if (!sendToSpider('process')) {
                insertLog("can't process the result");
                $status = Spider::ERROR;
                break;
              }
              $status = Spider::PROCESSING;
              break;
            case Spider::PROCESSED:
              $connection->send('ok');
              $sendAnswer = false;
              $status = Spider::PROCESSED;
              if (!sendToSpider('storage')) {
                insertLog("can't storage the result");
                $status = Spider::ERROR;
                break;
              }
              $status = Spider::STORAGING;
              break;
            case Spider::STORAGED:
              $connection->send('ok');
              $sendAnswer = false;
              $status = Spider::STORAGED;
              if (!sendToSpider('collect')) {
                insertLog("can't continue collect");
                $status = Spider::ERROR;
                break;
              }
              $status = Spider::COLLECTING;
              break;
            case Spider::READY:
              $connection->send('ok');
              $sendAnswer = false;
              if ($status == Spider::STORAGING)
                if (!sendToWeb('collected', array($spiderAddr, $currPort))) {
                  insertLog("can't send to web status - collected");
                  break;
                }
              $status = Spider::READY;
              break;
            default:
              insertLog("bad status - {$commands['params']}");
          }
          break;
        default:
          $result['result'] = 'bad command';
      }
    }
    if ($sendAnswer)
      $connection->send(json_encode($result));
  };

  $tcp_worker->onClose = function($connection) {
    insertLog("connection closed");
  };

  Worker::runAll();
}
//-----------------------------------------------------

function monitor() {
  //insertLog("monitor");
} // monitor
//-----------------------------------------------------

function insertLog($message) {
  echo date("H:i:s")." - $message".PHP_EOL;
} // insertLog
//-----------------------------------------------------

function sendToSpider($command, $params=NULL) {
  global $spiderSocket, $spiderAddr, $spiderPort;

  if (is_null($spiderSocket)) {
    $spiderSocket = socket_create(AF_INET, SOCK_STREAM, 0);
    if (!socket_connect($spiderSocket, $spiderAddr, $spiderPort)) {
      insertLog("can't connect to socket");
      $spiderSocket = NULL;
      return false;
    }
  }

  if (socket_write($spiderSocket, json_encode(array('command' => $command, 'params' => $params))) === FALSE) {
    insertLog("can't send to socket");
    return false;
  }
  $response = socket_read($spiderSocket, 1024);
  insertLog($response);
  if (($response === FALSE) || ($response == '')) {
    insertLog("bad response from socket");
    return false;
  }

  return ($response == 'ok');
} // sendToSpider
//-----------------------------------------------------

function sendToWeb($command, $params=NULL) {
  global $webSocket, $webAddr, $webPort;

  //++ FDO
  insertLog("sending command '$command' to web via $webAddr:$webPort");
  //--
  if (is_null($webSocket)) {
    $webSocket = socket_create(AF_INET, SOCK_STREAM, 0);
    if (!socket_connect($webSocket, $webAddr, $webPort)) {
      insertLog("can't connect to socket");
      $webSocket = NULL;
      return false;
    }
  }

  if (socket_write($webSocket, json_encode(array('command' => $command, 'params' => $params))) === FALSE) {
    insertLog("can't send to socket");
    return false;
  }
  $response = socket_read($webSocket, 1024);
  insertLog($response);
  if (($response === FALSE) || ($response == '')) {
    insertLog("bad response from socket");
    return false;
  }

  return ($response == 'ok');
} // sendToWeb
//-----------------------------------------------------

?>
