<?php
$content = file_get_contents('php://input');
$json = 'conf/econCalendar_web.json';

// try {
//   $headers = getallheaders();
//
//   if ($headers !== FALSE) {
//     $f = fopen('temp.txt', 'w');
//     foreach ($headers as $name => $value)
//       fwrite($f, $name.': '.$value."\n");
//     fclose($f);
//   }
// } catch (Error $er) {
//
// } catch (Exception $ex) {
//
// }

$in = json_decode($content, true);
if (json_last_error() != JSON_ERROR_NONE)
  echo json_encode(array('result' => 'error', 'message' => 'bad query'));
if (!array_key_exists('command', $in))
  echo json_encode(array('result' => 'error', 'message' => 'no command on query'));

switch($in['command']) {
  case 'getJSON':
    echo file_get_contents($json);
    break;
  default:
    echo json_encode(array('result' => 'error', 'message' => 'bad command'));
}
?>
