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
  if (count($argv) < 3) {
    insertLog("count of argv < 4 - exit");
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
  $socket = NULL;
  $spiderAddr = $argv[2];
  $spiderPort = $argv[3]+1;
  $currPort = $argv[3];

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
    global $status, $currPort;

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
          break;
        case 'collect':
          if (!Spider::saveArrayToFile($commands['params'], "web_$currPort.json")) {
            insertLog("can't save params to file");
            break;
          }
          if (!sendToSpider($command, $commands['params'])) {
            insertLog("can't send to spider");
            break;
          }
          $status = Spider::COLLECTING;
          $result['result'] = true;
          break;
        case 'setStatus':
          $status = $commands['params'];
          // можно вставить проверку правильный ли прищёл статус...
          $connection->send('ok');
          $sendAnswer = false;
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

function sendToSpider($command, $params) {
  global $socket, $spiderAddr, $spiderPort;

  if (is_null($socket)) {
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    if (!socket_connect($socket, $spiderAddr, $spiderPort)) {
      insertLog("can't connect to socket");
      $socket = NULL;
      return false;
    }
  }

  if (socket_write($socket, json_encode(array('command' => $command, 'params' => $params))) === FALSE) {
    insertLog("can't send to socket");
    return false;
  }
  $response = socket_read($socket, 1024);
  insertLog($response);
  if (($response === FALSE) || ($response == '')) {
    insertLog("bad response from socket");
    return false;
  }

  return ($response == 'ok');
} // sendToSpider
//-----------------------------------------------------

?>
