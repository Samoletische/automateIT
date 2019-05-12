<?php
namespace Facebook\WebDriver;

require_once('client_inc.php');

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('TIMEOUT', 10);
//-----------------------------------------------------

if (isset($argv)) { // запуск готового Сборщика из консоли
  // можно положить pid в отдельный файл
  //echo getmypid()."\n";
  if (count($argv) < 2)
    exit();

  //echo $argv[1]."\n";

  // читаем файл с параметрами
  $filename = 'params_'.$argv[1].'.txt';
  //echo $filename."\n";
  if (!file_exists($filename))
    exit();
  //echo "exists\n";

  $params = json_decode(file_get_contents($filename), true);
  if (json_last_error() !== JSON_ERROR_NONE)
    exit();

  $spider = new Spider($argv[1], $params);
  // переданы некорректные данные и поэтому закрываем Сборщика
  if ($spider === NULL)
    exit();

  $lastResponse = time();
  //echo $lastResponse;
  $c = 0;
  while (true) {
    // читаем команды управления
    $filename = $argv[1].'.in.txt';
    if (file_exists($filename)) {
      $f = fopen($filename, 'r');
      if ($f) {
        flock($f, LOCK_EX);
        $commands = explode("\n", fread($f, filesize($filename)));
        // получаем команды
        foreach ($commands as $command) {
          //$command = trim(fgets(STDIN));
          if (strlen($command) > 0) {
            //echo $command."\n";
            $result = array('result' => 'bad command');
            // получаем параметры команды
            $commandParams = explode(',', $command);
            // если метод существует, то вызываем его
            if (method_exists($spider, $commandParams[0])) {
              switch ($commandParams[0]) {
                case 'setCurrPage':
                  if (count($commandParams) > 1)
                    $result['result'] = $spider->$command($commandParams[1]);
                  break;
                default:
                  $result['result'] = $spider->$command();
              }
            }
            echo $result['result'];
            //print_r($result);
            $lastResponse = time();
          }
          // else
            //echo "no command\n";
        }
        fclose($f);
        unlink($filename);
      }
    }

    if (($lastResponse + TIMEOUT) < time())
      break;

    sleep(1);

    //echo $c++;
  }
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
    if ($result['result'])
      if (!system("php -f spider.php ".$in['token']." > ".$in['token'].".out.txt 2>/dev/null &"))
        $result['result'] = false;
  }

  return $result;

} // areYouReady
//-----------------------------------------------------

function sendCommand($token, $command) {
  $f = fopen($token.'.in.txt', 'w');
  if ($f) {
    fwrite($f, $command);
    fclose($f);
    return true;
  }
  else
    return false;
} // sendCommand
//-----------------------------------------------------

function getAnswer($token) {
  $result = '';
  $filename = $token.'.out.txt';
  if (file_exists($filename)) {
    $f = fopen($filename, 'r');
    $result = fgets($f, filesize($filename) + 1);
    fclose($f);
  }
  return $result;
} // getAnswer
//-----------------------------------------------------

function getStatus($in) {

  $result = array('result' => '');

  if (array_key_exists('token', $in)) {
    sendCommand($in['token'], 'getStatus');
    sleep(1);
    $result['result'] = getAnswer($in['token']);
  }

  return $result;

} // getStatus
//-----------------------------------------------------

function setCurrPage($in) {

  $result = array('result' => '');

  if ((array_key_exists('token', $in)) && (array_key_exists('currPage', $in))) {

  }

  return $result;

} // setCurrPage
//-----------------------------------------------------

function getNextPage($in) {

  $result = array('result' => '');

  if (array_key_exists('token', $in)) {

  }

  return $result;

} // getNextPage
//-----------------------------------------------------

function collect($in) {

  $result = array('result' => '');

  if (array_key_exists('token', $in)) {

  }

  return $result;

} // collect
//-----------------------------------------------------
?>
