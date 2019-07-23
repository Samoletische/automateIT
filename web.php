<?php
//namespace Facebook\WebDriver;

require_once('client_inc.php');
// ini_set('error_reporting', E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

$webJSON = System::SendRequest($server.'/start.php?', array('command' => 'getJSON'));
if (!is_null($webJSON)) {
  $error = '';
  $web = System::createWeb($webJSON, $error);
  //print_r($webJSON);
  if (is_null($web))
    echo "Creation Web error: $error\n";
  else
    $web->collect();
}
else
  echo "bad enter JSON\n";

// $dir = '../img/';
// $files = scandir($dir);
// foreach ($files as $file) {
// 	if (!strpos($file, '.png'))
// 		continue;
// 	echo "\nразбираем файл ".$file;
// 	$recog = new Recognize($dir.$file, 'temp/');
//   echo "\nРезультат: ".$recog->recognize();
// }
?>
