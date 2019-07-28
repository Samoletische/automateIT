<?php
require_once('client_inc.php');
// ini_set('error_reporting', E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

$command = 'monitor'; // 'monitor' или 'collect'
//$params = System::SendRequest($server.'/start.php?', array('command' => 'getJSON'));
$params = System::SendRequest($server.'/monitor.php?', array('command' => 'getJSON'));
//print_r($params);
if ((array_key_exists('result', $params)) && ($params['result'] == 'error') && (array_key_exists('message', $webJSON)))
  echo 'Getting params error: '.$params['message']."\n";
elseif (($command == 'monitor')
    && (array_key_exists('result', $params)) && ($params['result'] == 'ok')
    && (array_key_exists('collectPeriod', $params))
    && (array_key_exists('collectParams', $params))) {
  $error = '';
  $web = System::createWeb($params['collectParams'], $error);
  //print_r($params);
  if (is_null($web))
    echo "Creation Web error: $error\n";
  else {
    // Нужно запускать внешний экземпляр иониторинга, который с заданной периодичностью
    // будет запускать сбор данных, и который можно в любой момент будет отследить и остановить.
    // Но пока запустим три раза процесс сбора
    for ($c = 0; $c < 3; $c++) {
      $web->collect();
      sleep($params['collectPeriod']);
    }
  }
} elseif ($command == 'collect') {
  $error = '';
  $web = System::createWeb($params, $error);
  //print_r($params);
  if (is_null($web))
    echo "Creation Web error: $error\n";
  else
    $web->collect();
} else
  echo "Getting params error: bad answer\n";

?>
