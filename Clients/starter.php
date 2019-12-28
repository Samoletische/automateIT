<?php

namespace Clients;

// ini_set('error_reporting', E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

require_once('System.php');
require_once('lib/Workerman/Autoloader.php');

Use Workerman\Worker;
Use Workerman\lib\Timer;

$conf = Conf::getConf();
if (is_null($conf))
  exit();

$web = NULL;
$starterIP = NULL;
$tasks = array('econCalendar'); // пока задаём статично

foreach (dns_get_record(gethostname()) as $record)
  if (\array_key_exists('ip', $record)) {
    $starterIP = $record['ip'];
    break;
  }
if (\is_null($starterIP)) {
  System::insertLog("can't get IP-address of this mashine. exiting...");
  exit();
}

$worker = new Worker('tcp://'.$starterIP.':1200');
$worker->count = 2;

$timer = Timer::add(3, function() {
  taskMngr();
});

$worker->onConnect = function($connection) {

};

$worker->onMessage = function($connection, $data) {

};

$worker->onClose = function($connection) {

};

Worker::runAll();
//-----------------------------------------------------

function taskMngr() {
  global $web, $starterIP;

  if (\is_null($web)) {
    $web = System::createWeb($starterIP);
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

  if (count($tasks) == 0)
    return;
  return \array_shift($tasks);
} // getTask
//-----------------------------------------------------

?>
