<?php

//-----------------------------------------------------
// в файле conf_s.php должны быть определены следующие переменные
//  $serverDB         = '(адрес сервера базы данных MySQL)';
//  $userDB           = '(имя пользователя БД)';
//  $passwordDB       = '(пароль пользователя БД)';
//  $nameDB           = '(имя БД)';
require_once('conf_s.php');
//-----------------------------------------------------

class WebServer {

}
//-----------------------------------------------------

class Storage {
  public static function save($result) {
    global $serverDB, $userDB, $passwordDB, $nameDB;

    $db = mysqli_connect($serverDB, $userDB, $passwordDB, $nameDB);
    if (!$db)
      return false;
    $db->query("SET NAMES utf8");

    foreach ($result['values'] as $record) {
    	// запрос на проверку уже существующей записи
    	$where = '';
    	// собираем условия для проверки
    	foreach ($result['parentElement']['values'] as $value) {
        foreach ($record as $fields) {
          if ($value['fieldName'] != $fields['name'])
            continue;
          $where .= $where == '' ? '' : ' AND ';
          $where .= $value['fieldName']."='".$fields['value']."'";
        }
      }
    	$queryStr = 'SELECT 1 FROM '.$result['pageName'].' WHERE '.$where;
    	//echo $queryStr;
    	$query = $db->query($queryStr);
    	if (!$query) {
        //echo "Ошибка БД: ".$db->error;
        return false;
    	}
    	// если запись уже есть
    	if ($query->num_rows > 0)
    	break;
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
    	//echo $queryStr."\n";
    	$query = $db->query($queryStr);
    	if (!$query) {
        //echo "Ошибка БД: ".$db->error;
        return false;
      }
    }

    return true;
  }
  //-----------------------------------------------------
}
//-----------------------------------------------------
?>
