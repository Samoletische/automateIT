<?php
require_once('server_inc.php');

$content = file_get_contents('php://input');
$json = 'conf/dromLexus_monitor.json';

echo json_encode(makeAnswer());
//-----------------------------------------------------

function makeAnswer() {

  global $content;

  $in = json_decode($content, true);
  if (json_last_error() != JSON_ERROR_NONE) {
    System::insertLog('bad query');
    return array('result' => 'error', 'message' => 'bad query');
  }
  if (!array_key_exists('command', $in)) {
    System::insertLog('no command on query');
    return array('result' => 'error', 'message' => 'no command on query');
  }

  System::insertLog($in['command']);
  switch($in['command']) {
    case 'getJSON':
      return getJSON();
    default:
      return array('result' => 'error', 'message' => 'bad command');
  }

} // makeAnswer
//-----------------------------------------------------

function getJSON () {

  global $json, $monitorJsonEthalon;
  $result = array('result' => 'ok');

  $monitorJSON = json_decode(file_get_contents($json), true);
  if (json_last_error() != JSON_ERROR_NONE) {
    System::insertLog('bad JSON in monitor.json');
    return array('result' => 'error', 'message' => 'bad JSON in monitor.json');
  }
  // проверка структуры
  $error = '';
  if (!System::checkJSON($monitorJSON, $monitorJsonEthalon, $error)) {
    System::insertLog('error in monitor.json: '.$error);
    return array('result' => 'error', 'message' => 'error in monitor.json: '.$error);
  }
  // проверка на наличие уже данных по этому мониторингу в БД
  // запуск сборки на клиенте
  $result['collectPeriod'] = $monitorJSON['collectPeriod'];
  $result['collectParams'] = json_decode(file_get_contents($monitorJSON['collectParams']), true);
  // запуск мониторинга на сервере

  System::insertLog('return params');

  return $result;

} // getJSON
//-----------------------------------------------------
?>
