<?php
$ar = array("q" => "2", "w" => "3");
echo "q=".$ar['q'].", w=".$ar['w']."\n";
a($ar);
echo "q={$ar['q']}, w={$ar['w']}\n";

function a(&$ar) {
  $ar['q'] = 22;
  $ar['w'] = 33;
}
?>
