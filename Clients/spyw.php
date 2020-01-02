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

  system('echo '.getmypid().' > '.($argv[3]-1).'/'.$argv[3].'_pid.txt');

  $socket = NULL;
  $parentAddr = $argv[2];
  $parentPort = $argv[3]-1;
  $currPort = $argv[3];
  $spider = new Spider($parentPort);
  insertLog("parent: $parentAddr:$parentPort");

  insertLog("starting worker at tcp://$parentAddr:$currPort");
  $tcp_worker = new Worker("tcp://$parentAddr:$currPort");
  $tcp_worker->count = 2;

  $timer = Timer::add(TIMER, function() {
    monitor();
  });

  $tcp_worker->onConnect = function($connection) {
    insertLog("connection opened");
  };

  $tcp_worker->onMessage = function($connection, $data) {
    global $spider, $parentPort;

    $commands = json_decode($data, true);
    $result = array('result' => NULL);
    $sendAnswer = true;
    if ((json_last_error() == JSON_ERROR_NONE)
        && array_key_exists('command', $commands)
        && array_key_exists('params', $commands)) {
      $command = $commands['command'];
      insertLog("receive command '{$command}'");
      switch ($command) {
        case 'close':
          echo "receive close-command, exiting...\n";
          exit();
          Worker::stopAll();
        case 'collect':
          $params = Spider::loadArrayFromFile("web_$parentPort.json");
          if (is_null($params))
            break;
          insertLog('params loaded');
          if (!$spider->setParams($params))
            break;
          insertLog('params seted');
          $spider->setStatus(Spider::COLLECTING);
          $connection->send('ok');
          $sendAnswer = false;
          if (!$spider->collect(0)) {
            insertLog("collecting error");
            sendToParent('setStatus', Spider::ERROR);
            break;
          }
          sendToParent('setStatus', Spider::COLLECTED);
          break;
        default:
          if (!method_exists($spider, $command))
            break;
          echo "is method an array: {$command} - ".is_array($command)."\n";
          $result['result'] = $spider->$command();
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

function sendToParent($command, $params) {
  global $socket, $parentAddr, $parentPort;

  if (is_null($socket)) {
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    if (!socket_connect($socket, $parentAddr, $parentPort)) {
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
} // sendToParent
//-----------------------------------------------------

?>
