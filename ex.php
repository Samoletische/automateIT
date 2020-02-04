<?php
$ar = array("q" => "2", "w" => "3");
echo "q=".$ar['q'].", w=".$ar['w']."\n";
a($ar);
echo "q={$ar['q']}, w={$ar['w']}\n";

$stat = 1;

echo "1 - $stat\n";
b();
echo "2 - $stat\n";

function a(&$ar) {
  $ar['q'] = 22;
  $ar['w'] = 33;
}

function b() {
  global $stat;

  $stat = 2;
}
?>
