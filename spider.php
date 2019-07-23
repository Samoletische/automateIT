<?php
//namespace Facebook\WebDriver;

require_once('client_inc.php');

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('TIMEOUT', 40);
//-----------------------------------------------------

if (isset($argv)) { // запуск готового Сборщика из консоли
  echo date('h:i:s')." - start spider\n";
  // можно положить pid в отдельный файл
  //echo getmypid()."\n";
  if (count($argv) < 2) {
    echo date('h:i:s')." - count of argv < 2 - exit\n";
    exit();
  }

  //echo $argv[1]."\n";

  // читаем файл с параметрами
  $filename = 'params_'.$argv[1].'.txt';
  //echo $filename."\n";
  if (!file_exists($filename)) {
    echo date('h:i:s')." - file of params don't exists - exit\n";
    exit();
  }
  //echo "exists\n";

  $params = json_decode(file_get_contents($filename), true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    echo date('h:i:s')." - file of params is not web.json format - exit\n";
    exit();
  }

  $spider = new Spider($argv[1], $params);
  // переданы некорректные данные и поэтому закрываем Сборщика
  if ($spider === NULL) {
    echo date('h:i:s')." - can't start Spider - exit\n";
    exit();
  }

  $filename = $argv[1].'.out.txt';
  if (file_exists($filename))
    unlink($filename);

  $lastResponse = time();
  //echo $lastResponse;
  $c = 0;
  while (true) {
    // читаем команды управления
    $filename = $argv[1].'.in.txt';
    if (file_exists($filename)) {
      $f = fopen($filename, 'r');
      if ($f) {
        //flock($f, LOCK_EX);
        //$commands = explode("\n", fread($f, filesize($filename)));
        $command = fread($f, filesize($filename));
        fclose($f);
        unlink($filename);
        //echo date('h:i:s')." - receive command: ".print_r($commands)."\n";
        echo date('h:i:s')." - receive command: ".$command."\n";
        // получаем команды
        //foreach ($commands as $command) {
          //$command = trim(fgets(STDIN));
          if (strlen($command) > 0) {
            //echo $command."\n";
            $result = NULL;
            // получаем параметры команды
            $commandParams = explode(',', $command);
            $method = $commandParams[0];
            echo date('h:i:s')." - command params: ";
            print_r($commandParams)."\n";
            // если метод существует, то вызываем его
            if (method_exists($spider, $method)) {
              switch ($method) {
                case 'setCurrPage':
                  if (count($commandParams) > 2)
                    $result = $spider->$method($commandParams[1], $commandParams[2]);
                  break;
                default:
                  $result = $spider->$method();
              }
            }
            setAnswer($argv[1], $result);
            if ($result === NULL) {
              echo date('h:i:s')." - can't continues - exit\n";
              exit();
            }
            //print_r($result);
            $lastResponse = time();
          }
          // else
            //echo "no command\n";
        //}
      }
    }

    if (($lastResponse + TIMEOUT) < time()) {
      echo date('h:i:s')." - time of waiting a commands is out\n";
      break;
    }

    sleep(1);

    //echo $c++;
  }
  echo date('h:i:s').' - exit';
}
else { // команды управления Сборщиком через HTTP
  $content = file_get_contents('php://input');
  $in = json_decode($content, true);

  if ((isset($in)) && (array_key_exists('command', $in)))
    switch ($in['command']) {
      case 'areYouReady':
        echo json_encode(areYouReady($in));
        break;
      case 'getStatus':
        echo json_encode(getStatus($in));
        break;
      case 'setCurrPage':
        echo json_encode(setCurrPage($in));
        break;
      case 'getNextPage':
        echo json_encode(getNextPage($in));
        break;
      case 'collect':
        echo json_encode(collect($in));
        break;
      default:
        echo json_encode(array('result' => 'bad command'));
    }
  else
    echo json_encode(array('result' => 'bad command'));
}
//-----------------------------------------------------

function areYouReady($in) {

  $result = array('result' => false);

  if ((array_key_exists('token', $in)) && (array_key_exists('params', $in))) {
    // проверяем готовность Сборщика
    $result['result'] = Spider::ReadyToUse();

    // сохраняем параметры в файле
    if ($result['result']) {
      $f = fopen('params_'.$in['token'].'.txt', 'w');
      if ($f) {
        fwrite($f, json_encode($in['params']));
        fclose($f);
      }
      else
        $result['result'] = false;
    }

    // запускаем Сборщик
    if ($result['result']) {
      $filename = $in['token'].'.in.txt';
      if (file_exists($filename))
        unlink($filename);
      if (!system("php -f spider.php ".$in['token']." > ".$in['token'].".log.txt 2>&1 &"))
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
  return $result;
} // getAnswer
//-----------------------------------------------------

function setAnswer($token, $answer) {
  $result = false;
  $filename = $token.'.out.txt';
  $lastResponse = time();
  while(true) {
    if (!file_exists($filename)) {
      $f = fopen($filename, 'w');
      fwrite($f, $answer);
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

function setCurrPage($in) {

  $result = array('result' => '');

  if ((array_key_exists('token', $in)) && (array_key_exists('currPage', $in)) && (array_key_exists('pageNum', $in))) {
    if (sendCommand($in['token'], 'setCurrPage,'.$in['currPage'].','.$in['pageNum']))
      $result['result'] = getAnswer($in['token']);
  }

  return $result;

} // setCurrPage
//-----------------------------------------------------

function getNextPage($in) {

  $result = array('result' => '');

  if (array_key_exists('token', $in)) {
    if (sendCommand($in['token'], 'getNextPage'))
      $result['result'] = getAnswer($in['token']);
  }

  return $result;

} // getNextPage
//-----------------------------------------------------

function collect($in) {

  $result = array('result' => '');

  if (array_key_exists('token', $in)) {
    if (sendCommand($in['token'], 'collect'))
      $result['result'] = getAnswer($in['token']);
  }

  return $result;

} // collect
//-----------------------------------------------------
?>
