<?php
$content = file_get_contents('php://input');

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
if ((array_key_exists('command', $in)) && ($in['command'] == 'getJSON'))
  echo file_get_contents('json/gPlay_web.json');
else
  echo json_encode(array('result' => 'bad command'));
?>
