<?php
require_once("server_inc.php");

$json = json_decode(file_get_contents('php://input'), true);
if (json_last_error() === JSON_ERROR_NONE) {
  System::insertLog("storage.php: insertOnly=".$json['insertOnly']);
  System::insertLog("storage.php: collectAllData=".$json['collectAllData']);
  if ($json['insertOnly'])
    echo Storage::save($json, true);
  else
    if ($json['collectAllData'])
      echo Storage::save($json, false);
    else
      echo json_encode(Storage::check($json));
} else
  echo "bad enter JSON";
?>
