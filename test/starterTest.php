<?php
require_once('Clients/System.php');
//require_once('phar://phpunit-9.0.1.phar/phpunit/Framework/TestCase.php');
use Clients\System;
use PHPUnit\Framework\TestCase;

class starterTest extends TestCase {
  private $web;

  public function testWebCollect() {
    $starterIP = NULL;
    $starterPort = 1200;

    foreach (dns_get_record(gethostname()) as $record)
      if (\array_key_exists('ip', $record)) {
        $starterIP = $record['ip'];
        break;
      }

    if (\is_null($starterIP)) {
      System::insertLog("can't get IP-address of this mashine. exiting...");
      exit();
    }

    $this->web = System::createWeb($starterIP, $starterPort);

    $res = $this->web->collect(NULL);
    //$this->assertEquals($res, 'collecting');
    $const = $this->LogicalAnd($this->equalTo('collecting'));
    self::AssertThat($res, $const);
  }
}
?>
