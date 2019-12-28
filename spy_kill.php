<?php

namespace Clients;

require_once("Clients/System.php");

if (count($argv) < 3) {
  $files = explode('/', __FILE__);
  $count = count($files);
  echo "low arguments\nuses: {$files[$count-1]} addr port\n";
  exit();
}

$url = "http://192.168.0.20/automateIT/spy.php";

if ($argv[1] == "kill") {
  $result = System::sendRequest(
    $url,
    array(
      'command' => 'kill',
      'port' => $argv[2]
    )
  );
  print_r($result);
} else {
  $socket = socket_create(AF_INET, SOCK_STREAM, 0);
  if (!socket_connect($socket, $argv[1], $argv[2])) {
    echo "can't connect to tcp://{$argv[1]}:{$argv[2]}\n";
    exit();
  }
  $data = '{"method": "close", "params": ""}';
  $commands = json_decode($data, true);
  print_r($commands);
  socket_write($socket, $data);
}
?>
