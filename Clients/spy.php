<?php

namespace Clients;

require_once('Spider.php');

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
//-----------------------------------------------------

function createSpider() {
  global $spider;

  $spider = new Spider($argv[3]);
  // переданы некорректные данные и поэтому закрываем Сборщика
  if (is_null($spider)) {
    echo date('h:i:s')." - can't start Spider - exit\n";
    exit();
  }
} // createSpider
//-----------------------------------------------------

function startWorker() {
  global $spider;

  echo "starting worker at tcp://$argv[2]:$argv[3]\n";
  $tcp_worker = new Worker("tcp://$argv[2]:$argv[3]");
  $tcp_worker->count = 2;

  $tcp_worker->onConnect = function($connection) {
    echo "connection opened\n";
  };

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
          $spider->saveParamsToFile($commands['params'][0]);
          $spider->startCollectSpy();
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

  $tcp_worker->onClose = function($connection) {
    echo "connection closed\n";
  };

  Worker::runAll();
} // startWorker
//-----------------------------------------------------

function killSpy($in) {

  $result = array('result' => true, 'message' => '');

  if (array_key_exists('port', $in)) {
    $filename = $in['port'].'/'.$in['port'].'_pid.txt';
    $result['message'] .= PHP_EOL.$filename;
    if (\file_exists($filename)) {
      $pid = \file_get_contents($filename);
      $result['message'] .= ' - '.$pid;
      system("kill $pid");
    }
    $filename = $in['port'].'/'.($in['port']+1).'_pid.txt';
    $result['message'] .= PHP_EOL.$filename;
    if (\file_exists($filename)) {
      $pid = \file_get_contents($filename);
      $result['message'] .= ' - '.$pid;
      system("kill $pid");
    }
    $result['result'] = true;
  }

  return $result;

} // killSpy
//-----------------------------------------------------

function areYouReady($in) {

  $result = array('result' => false, 'message' => '');

  if (array_key_exists('addr', $in)
      && array_key_exists('port', $in)
      && array_key_exists('serverSelenium', $in)
      && array_key_exists('starterAddr', $in)
      && array_key_exists('starterPort', $in)) {
    // проверяем готовность Сборщика
    $result['result'] = Spider::ReadyToUse($in['serverSelenium']);

    if (!$result['result']) {
      $result['message'] = 'spider not ready';
      return $result;
    }
    if (\file_exists($in['port'])) {
      // remove port dir
      if (\is_dir($in['port'])) {
        $files = \scandir($in['port']);
        foreach ($files as $file) {
          if (($file == '.') || ($file == '..'))
            continue;
          if (\is_dir($in['port'].'/'.$file)) {
            $result['result'] = false;
            $result['message'] = 'port contains a dir';
            break;
          }
          $result['result'] = \unlink($in['port'].'/'.$file);
          if (!$result['result']) {
            $result['message'] = 'can not unlink file in port dir';
            return $result;
          }
        }
        $result['result'] = \rmdir($in['port']);
      } else // or file
        $result['result'] = \unlink($in['port']);
    }
    if (!$result['result'])
      return $result;

    // create dir and fill it
    $result['result'] = mkdir($in['port']);
    if (!$result['result']) {
      $result['message'] = 'can not make port dir';
      return $result;
    }
    $result['result'] = copy('spyd.php', $in['port'].'/spyd.php');
    if (!$result['result']) {
      $result['message'] = 'can not copy spyd.php';
      return $result;
    }
    $result['result'] = copy('spyw.php', $in['port'].'/spyw.php');
    if (!$result['result']) {
      $result['message'] = 'can not copy spyw.php';
      return $result;
    }

    // start spy daemon
    $spyStr = "php {$in['port']}/spyd.php start ".$in['addr'].' '.$in['port'].' '.$in['starterAddr'].' '.$in['starterPort']." > ".$in['port'].".log.txt 2>&1 &";
    if (system($spyStr) === FALSE) {
      $result['message'] = $spyStr;
      $result['result'] = false;
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
