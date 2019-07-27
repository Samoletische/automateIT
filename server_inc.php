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

abstract class System {

  // checkJSON
  //  Проверяет массив параметров из файла типа storage.json на корректность структуры.
  //  Параметры:
  //    $params   - array   - параметризированный массив
  //    $error    - string  - при возникновении ошибки сюда запишется её текст
  //  Возвращаемые значения:
  //    bool - true, если соответствует эталонной структуре, и false, если НЕ соответствует.
  static function checkJSON($params, &$error='') {

    global $storageJsonEthalon;

    if (!file_exists($storageJsonEthalon)) {
      $error = "Ethalon storage.json file '$storageJsonEthalon' not exists";
      return false;
    }

    $ethalon = json_decode(file_get_contents($storageJsonEthalon), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $error = "Can't load ethalon storage.json file '$storageJsonEthalon'. Bad JSON format.";
      return false;
    }

    // check for nessesary fields in JSON
    if ((empty($params)) || (!is_array($params))
        || (empty($ethalon)) || (!is_array($ethalon))) {
      $error = 'Bad storage.json structure';
      if (empty($params)) {
        $error .= "\nparams\n";
        print_r($params);
      }
      return false;
    }

    $firstNotExistingField = '';
    if (!System::checkJsonStructure($ethalon, $params, $firstNotExistingField)) {
      $error = "Bad storage.json structure: not exists '$firstNotExistingField' field";
      return false;
    }

    return true;

  } // System::checkJSON
  //-----------------------------------------------------

  // checkJsonStructure
  //  Сравнивает две структуры параметризированных массивов. Одна структура эталонная, другая - проверяемая.
  //  Параметры:
  //    $ethalon                - array   - параметризированный массив эталонной структуры
  //    $check                  - array   - параметризированный массив проверяемой структуры
  //    $firstNotExistingField  - string  - при отсутствии в проверяемой структуре поля из эталонной
  //                                        сюда запишется его (поля) имя
  //  Возвращаемые значения:
  //    bool - флаг идентичности структур.
  static function checkJsonStructure($ethalon, $check, &$firstNotExistingField) {

    foreach ($ethalon as $key => $value) {
      if (!array_key_exists($key, $check)) {
        $firstNotExistingField = $key;
        return false;
      }
      if (is_array($ethalon[$key]))
        if (!self::checkJsonStructure($ethalon[$key], $check[$key], $firstNotExistingField))
          return false;
    }

    return true;

  } // System::checkJsonStructure
  //-----------------------------------------------------

}
//-----------------------------------------------------

abstract class Storage {

  static function save($result) {

    $error = '';
    if (!System::checkJSON($result, $error)) {
      echo "Ошибка сохранения результата: $error\n";
      return false;
    }

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

    $res = array(); // формат такой же как поле filter в web.json

    $error = '';
    if (!System::checkJSON($result, $error)) {
      echo "Ошибка проверки результата: $error\n";
      return $res;
    }

    $db = self::getDB();
    if (is_null($db))
      return $res;

    foreach ($result['values'] as $record) {
    	$queryStr = self::getQueryStrOfIDByIndexesFields($result, $record);
    	$query = $db->query($queryStr);
    	if (!$query) {
        echo "Ошибка БД 1: ".$db->error."\n";
        return $res;
    	}

    	// добавляем в результат, если записи ещё нет в базе
      if ($query->num_rows == 0)
        foreach($record as $fields) {
          // check for index fields
          $isIndex = false;
          foreach ($result['paramsValues'] as $param)
            if (($param['fieldName'] == $fields['name']) && ($param['index'])) {
              $isIndex = true;
              break;
            }
          if (!$isIndex)
            continue;
            
          // add to result
          $attrExists = false;
          $attrKey = -1;
          foreach($res as $key => $attr) {
            echo $attr['attr'].'=='.$fields['name']."\n";
            if ($attr['attr'] == $fields['name']) {
              $attrExists = true;
              $attrKey = $key;
              break;
            }
          }
          if ($attrExists)
            $res[$attrKey]['value'][] = $fields['value'];
          else
            $res[] = array( "attr" => $fields['name'], "value" => array($fields['value']) );
        }
    }
    print_r($res);

    return $res;

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
