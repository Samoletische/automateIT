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

    $db = self::getDB();
    if (is_null($db))
      return false;

    foreach ($result['values'] as $record) {
    	// запрос на проверку уже существующей записи
      $where = '';
    	$queryStr = self::getQueryStrOfIDByIndexesFields($result, $record, $where);
    	$query = $db->query($queryStr);
    	if (!$query) {
        echo "Ошибка БД 1: ".$db->error."\n";
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
            echo "Ошибка БД 3: ".$db->error."\n";
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
          echo "Ошибка БД 2: ".$db->error."\n";
          return false;
        }
      }
    }

    return true;

  } // Storage::save
  //-----------------------------------------------------

  static function check($result) {

    $result = array(); // формат такой же как поле filter в web.json

    $db = self::getDB();
    if (is_null($db))
      return $result;

    foreach ($result['values'] as $record) {
    	$queryStr = self::getQueryStrOfIDByIndexesFields($result, $record);
    	$query = $db->query($queryStr);
    	if (!$query) {
        echo "Ошибка БД 1: ".$db->error."\n";
        return $result;
    	}

    	// добавляем в результат, если записи ещё нет в базе
      if ($query->num_rows == 0)
        foreach($record as $fields) {
          $attrExists = false;
          $attrKey = -1;
          foreach($result as $key => $attr)
            if ($attr['attr'] == $fields['name']) {
              $attrExists = true;
              $attrKey = $key;
              break;
            }
          if ($attrExists)
            $result[$attrKey]['value'][] = $fields['value'];
          else
            $result[] = array( "attr" => $fields['name'], "value" => array($fields['value']) );
        }
    }
    print_r($result);

    return $result;

  } // Storage::check
  //-----------------------------------------------------

  static function getDB() {

    global $serverDB, $userDB, $passwordDB, $nameDB;

    $db = mysqli_connect($serverDB, $userDB, $passwordDB, $nameDB);
    if (!$db)
      return NULL;
    $db->query("SET NAMES utf8");

    return $db;

  } // Storage::getDB
  //-----------------------------------------------------

  static function getQueryStrOfIDByIndexesFields($result, $record, &$where='') {
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

    return $queryStr;
  } // Storage::getQueryStrOfIDByIndexesFields
  //-----------------------------------------------------

} // Storage
//-----------------------------------------------------
?>
