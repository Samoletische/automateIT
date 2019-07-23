<?php

//-----------------------------------------------------
// в файле conf_s.php должны быть определены следующие переменные
//  $serverDB         = '(адрес сервера базы данных MySQL)';
//  $userDB           = '(имя пользователя БД)';
//  $passwordDB       = '(пароль пользователя БД)';
//  $nameDB           = '(имя БД)';
require_once('conf_s.php');
//-----------------------------------------------------

const TIMEZONE = 6;

class WebServer {

}
//-----------------------------------------------------

abstract class Storage {

  static function save($result) {
    global $serverDB, $userDB, $passwordDB, $nameDB;

    $db = mysqli_connect($serverDB, $userDB, $passwordDB, $nameDB);
    if (!$db)
      return false;
    $db->query("SET NAMES utf8");

    foreach ($result['values'] as $record) {
    	// запрос на проверку уже существующей записи
    	$where = '';
    	// собираем условия для проверки
      foreach ($result['paramsValues'] as $value) {
        if (!$value['index'])
          continue;
        foreach ($record as $fields) {
          if ($value['fieldName'] != $fields['name'])
            continue;
          $where .= $where == '' ? '' : ' AND ';
          $where .= $value['fieldName']."='".$fields['value']."'";
        }
      }
      $queryStr = 'SELECT id FROM '.$result['pageName'].' WHERE '.$where;
    	echo $queryStr."\n";
    	$query = $db->query($queryStr);
    	if (!$query) {
        echo "Ошибка БД 1: ".$db->error;
        return false;
    	}
    	// если запись уже есть
      if ($query->num_rows > 0) {
        if ($result['overwrite']) {
          // обновляем запись
        	$fields = '';
        	foreach ($record as $res) {
          	$fields .= $fields == '' ? '' : ",";
          	$fields .= $res['name']."='".$res['value']."'";
        	}
          $fields .= $fields == '' ? '' : ",";
          $date = date('Y-m-d H:i:s', time() + TIMEZONE*3600);
          $fields .= "dateNow='".$date."'";
        	$queryStr = 'UPDATE '.$result['pageName']." SET $fields WHERE $where";
        	echo $queryStr."\n";
        	$query = $db->query($queryStr);
        	if (!$query) {
            echo "Ошибка БД 3: ".$db->error;
            return false;
          }
        } else
          continue;
      } else {
      	// добавляем запись
      	$fields = '';
      	$values = '';
      	foreach ($record as $res) {
        	$fields .= $fields == '' ? '' : ",";
        	$fields .= $res['name'];
        	$values .= $values == '' ? "'" : ",'";
        	$values .= $res['value']."'";
      	}
      	$queryStr = 'INSERT INTO '.$result['pageName'].'('.$fields.') VALUES('.$values.')';
      	echo $queryStr."\n";
      	$query = $db->query($queryStr);
      	if (!$query) {
          echo "Ошибка БД 2: ".$db->error;
          return false;
        }
      }
    }

    return true;
  } // Storage::save
  //-----------------------------------------------------

} // Storage
//-----------------------------------------------------
?>
