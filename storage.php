<?php
require_once("server_inc.php");

$json = json_decode(file_get_contents('php://input'), true);
if (json_last_error() === JSON_ERROR_NONE)
  echo Storage::save($json);
else
  echo "bad enter JSON";
?>
