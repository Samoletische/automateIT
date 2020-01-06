<?php

namespace Clients;

require_once("Clients/System.php");

if (count($argv) < 2) {
  $files = explode('/', __FILE__);
  $count = count($files);
  echo "low arguments\nuses: {$files[$count-1]} command{kill, stop}\n";
  exit();
}

$conf = json_decode(\file_get_contents('conf/conf_c.json'), true);

foreach($conf['spiders'] as $spider) {
  System::insertLog("kill/stop {$spider['port']}\n");
  switch ($argv[1]) {
    case 'kill':
      $result = System::sendRequest(
        $spider['url'],
        array(
          'command' => 'kill',
          'port' => $spider['port']
        )
      );
      System::insertLog($commands['message']);
      break;
    case 'stop':
      $socket = socket_create(AF_INET, SOCK_STREAM, 0);
      if (!socket_connect($socket, $spider['addr'], $spider['port'])) {
        echo "can't connect to tcp://{$spider['addr']}:{$spider['port']}\n";
        exit();
      }
      $data = '{"method": "close", "params": ""}';
      $commands = json_decode($data, true);
      System::insertLog($commands['message']);
      socket_write($socket, $data);
      break;
  }
}
?>
