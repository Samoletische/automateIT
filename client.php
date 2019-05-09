<?php
namespace Facebook\WebDriver;

require_once("client_inc.php");
// ini_set('error_reporting', E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

$web = new Web();
$json = json_decode(file_get_contents('../enterData/avitoPioner_web.json'), true);
if (json_last_error() === JSON_ERROR_NONE)
  $web->collect($json);
else
  echo "bad enter JSON";

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
