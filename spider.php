<?php
namespace Facebook\WebDriver;

require_once('client_inc.php');

$content = file_get_contents('php://input');
$in = json_decode($content, true);
if ((array_key_exists('command'))
  switch ($in['command']) {
    case 'areYouReady':
      if ((array_key_exists('token')) && (array_key_exists('serverSelenium'))) {
        $result = array();
        $result['result'] = Spider::ReadyToUse($in['serverSelenium']);
        if ($result['result']) {
          $state = array('status' => 'new', 'date' => date('h:i:s'));
          // unlink($in['token']);
          $f = fopen($in['token'], 'W'); // стираем если что-то там было
          fwrite($f, json_encode($state));
          fclose($f);
        }
        else
          unlink($in['token']);

        echo json_encode($result);

        // for ($c = 0; $c < 10; $c++)
        //   sleep(1);
        // $state = json_decode(file_get_contents($in['token']), true);
        // $state['date2'] = date('h:i:s');
        // $f = fopen($in['token'], 'w');
        // fwrite($f, json_encode($state));
        // fclose($f);
      }
      break;
  }
}
else
  echo json_encode(array('result' => 'bad command'));
?>
