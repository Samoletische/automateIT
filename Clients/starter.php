<?php

namespace Clients;

// ini_set('error_reporting', E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

require_once('System.php');
require_once('lib/Workerman/Autoloader.php');

Use Workerman\Worker;
Use Workerman\Lib\Timer;

define('TIMER', 3);

$conf = Conf::getConf();
if (is_null($conf))
  exit();

$web = NULL;
$starterIP = NULL;
$starterPort = 1200;
$tasks = array('runsim21', 'dromLexus'); // пока задаём статично

foreach (dns_get_record(gethostname()) as $record)
  if (\array_key_exists('ip', $record)) {
    $starterIP = $record['ip'];
    break;
  }
if (\is_null($starterIP)) {
  System::insertLog("can't get IP-address of this mashine. exiting...");
  exit();
}

$worker = new Worker("tcp://$starterIP:$starterPort");
$worker->count = 2;

$timer = Timer::add(TIMER, function() {
  taskMngr();
});

$worker->onConnect = function($connection) {

};

$worker->onMessage = function($connection, $data) {
  $commands = json_decode($data, true);
  $result = 'bad params receive from spider';
  if ((json_last_error() == JSON_ERROR_NONE)
      && array_key_exists('command', $commands)
      && array_key_exists('params', $commands)) {
    $command = $commands['command'];
    System::insertLog("receive command '{$command}'");
    switch ($command) {
      case 'collected':
        System::insertLog("collect is completed on spider: {$commands['params'][1]}");
        $result = 'ok';
        break;
      default:
        $result = 'bad command';
    }
  }
  $connection->send($result);
};

$worker->onClose = function($connection) {

};

Worker::runAll();
//-----------------------------------------------------

function taskMngr() {
  global $web, $starterIP, $starterPort;

  System::insertLog('taskMngr');

  if (\is_null($web)) {
    $web = System::createWeb($starterIP, $starterPort);
    if (\is_null($web)) {
      System::insertLog("creating Web error. exiting...");
      Worker::stopAll();
      exit();
    }
    System::insertLog("Web is created");

    return;
  }

  $task = getTask();

  if (!\is_null($task) && ($task != ''))
    $web->addTask($task);

  $web->monitor();

} // loop
//-----------------------------------------------------

function getTask() {
  global $tasks;

  System::insertLog("count of tasks: ".count($tasks));
  if (count($tasks) == 0)
    return;
  return \array_shift($tasks);
} // getTask
//-----------------------------------------------------

?>
