<?php
require_once("server_inc.php");

$json = json_decode(file_get_contents('php://input'), true);
if (json_last_error() === JSON_ERROR_NONE)
  if ($json['insertOnly'])
    echo Storage::save($json, true);
  else
    if ($json['collectAllData'])
      echo Storage::save($json);
    else
      echo json_encode(Storage::check($json));
else
  echo "bad enter JSON";
?>
