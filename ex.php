<?php

namespace C;

include './ex2.php';

use B\B;
use B\C;

class A {
  public $a;
  function __construct($a) {
    $this->a = $a;
  }
  public function printA() {
    echo $this->a.PHP_EOL;
  }
}

$a = new A(3);
$a->printA();

$b = B::createB(5);
if (is_null($b))
  echo "B is NULL\n";
else
  $b->printB();

$b = new C(7);
$b->printC();
?>
