<?php

namespace Clients;

require_once("Clients/System.php");
require_once("Collector.php");
require_once("StaticCollector.php");
require_once("DynamicCollector.php");

class Spider {
  const READY = 'ready';
  const ERROR = 'error';
  const COLLECTING = 'collecting';
  const COLLECTED = 'collected';
  const PROCESSING = 'processing';
  const PROCESSED = 'processed';
  const STORAGING = 'storaging';
  const STORAGED = 'storaged';

  private $status; // 'ready', 'collecting', 'processing', 'storaging'
  private $token;
  private $collector;
  private $serverDB;

  private $params; // enter data
  private $result; // outer data
  //-----------------------------------------------------

  //function __construct($token, $params) {
  //function __construct($token) {
  function __construct() {

    // echo "creating Spider with token=$token\n";

    // $this->status = 'ready';
    $this->token = $token;
    $this->collector = NULL;
    $this->params = NULL;

    // if ((!array_key_exists('pageName', $params))
    //   || (!array_key_exists('storage', $params))
    //   || (!array_key_exists('parentElement', $params))
    //   || (!array_key_exists('childElements', $params))
    //   || (!array_key_exists('childPages', $params))
    //   || (!array_key_exists('pagination', $params))
    //   || (!array_key_exists('process', $params)))
    //   return NULL;
    //
    // $this->params = $params;
    // $this->driver = NULL;
    // $this->wait   = NULL;


    // $this->eraseResult($this->result, $this->params['pageName']);
  }
  //-----------------------------------------------------

  function __destruct() {
    global $closeAfteFinish;

    if ($closeAfteFinish && $this->driver) {
      $this->driver->close();
      $this->driver->quit();
    }
  }
  //-----------------------------------------------------

  static function ReadyToUse($seleniumServer) {
    // проверка на доступность Selenium'а
    return true;
  }
  //-----------------------------------------------------

  public function setParams($params, $serverDB=NULL, $serverSelenium=NULL) {
    $this->params = $params;
    $this->serverDB = $serverDB;
    // if (!\is_null($this->collector))
    //   unset($this->collector);
    $this->collector = $params['needInteractive'] ? new DynamicCollector($params, $serverSelenium) : new StaticCollector($params);
    return true;
  }
  //-----------------------------------------------------

  public function setCurrPage($page, $pageNum, $firstItemIndex, $maxItemsCollect, $changeStatus=false) {

    if ($changeStatus)
      $this->status = 'setting current page';

    $this->currPage = $page;
    $this->currPageNum = $pageNum;
    $this->params['firstItemIndex'] = $firstItemIndex;
    $this->params['maxItemsCollect'] = $maxItemsCollect;

    return true;

  }
  //-----------------------------------------------------

  // getNextPage()
  //  Функция возвращает ссылку на следующую страницу по параметрам пагинатора (только с пагинатором)
  //  В случае отсутствия элемента со ссылкой на следующую страницу или когда собрали уже все элементы
  //  возвращается пустая строка. Для страниц с прокруткой вернёт ту же страницу.
  //  В случае ошибок вернутся те же данные, что и пришли.
  public function getNextPage() {

    $this->status = 'getting current page';

    $pageResult = array('currPage' => '', 'firstItemIndex' => $this->params['firstItemIndex'], 'maxItemsCollect' => $this->params['maxItemsCollect']);
    if ($this->params['paginationHaveSameAddress'])
      $pageResult['currPage'] = $this->params['startPage'];

    $pagination = $this->params['pagination'];

    echo "Spider: get next page\n";
    if (($pagination['cssSelector'] == '')
        || ($pagination['nextPage'] == '')
        || ($pagination['valueAttr'] == '')) {
      $pageResult['error'] = 'nesessary fields are empty';
      return $pageResult;
    }

    if ($this->params['allPagesInOneSpider'])
      return $pageResult;

    if ($this->driver === NULL)
      $this->initDriver();

    if ($this->params['needInteractive']) {
      echo "Spider: do pre-collect\n";
      if (!$this->doPreCollect($this->params)) {
        $pageResult['error'] = 'bad pre-collect events';
        return $pageResult;
      }

      echo 'paginationHaveSameAddress='.$this->params['paginationHaveSameAddress']."\n";
      if ($this->params['paginationHaveSameAddress']) {
        echo "Spider: go to current page\n";
        $this->goToCurrentPage($this->params, $pageResult);
        if (isset($pageResult['error']))
          return $pageResult;
        $this->params['firstItemIndex'] = $pageResult['currFirstItemIndex'];
        $this->params['maxItemsCollect'] = $pageResult['currMaxItemsCollect'];
        $this->params['alsoOnCurrentPage'] = true;
      }
      else {
        echo "Spider: get next page\n";
        $resNextPage = $this->doNextPage();
        $pageResult['currPage'] = is_null($resNextPage) ? '' : $resNextPage;
      }
    } else {
      // получение следующей страницы с помощью PHPQuery
    }

    return $pageResult;
  }
  //-----------------------------------------------------

  public function getStatus() {
    return $this->status;
  }
  //-----------------------------------------------------

  public function setStatus($status) {
    echo "new status: $status\n";
    if ($status == 'complete')
      $this->complete();
    else
      $this->status = $status;
    return true;
  }
  //-----------------------------------------------------

  private function complete() {
    $this->driver->close();
    $this->driver->quit();
    $this->driver = NULL;
    $this->status = 'ready';
  }
  //-----------------------------------------------------

  public function isComplete() {
    return $this->collector->isComplete();
  }
  //-----------------------------------------------------

  private function goToCurrentPage($params=NULL, &$pageResult) {

    $params = is_null($params) ? $this->params : $params;

    $firstItemIndex = $params['firstItemIndex'];
    $firstItemOnCurrPage = $firstItemIndex;
    $maxItemsCollect = $params['maxItemsCollect'] + $firstItemIndex;
    $parentElement = $params['parentElement'];

    $lastResponse = time();
    while (true) {
      $links = $this->getExistingElements($this->driver, $parentElement['cssSelector'], "goToCurrentPage");
      if (!$links) {
        $pageResult['error'] = 'can not find pre collect cssSelector for parent element';
        return false;
      }
      $count = count($links);

      echo "go to next page: count=$count, firstItemOnCurrPage=$firstItemOnCurrPage, maxItemsCollect=$maxItemsCollect\n";
      $firstItemOnCurrPage = $params['paginationByScroll'] ? $firstItemIndex : $firstItemOnCurrPage;
      echo "go to next page: count=$count, firstItemOnCurrPage=$firstItemOnCurrPage, maxItemsCollect=$maxItemsCollect\n";
      if ($count <= $firstItemOnCurrPage) {
        $firstItemOnCurrPage -= $params['paginationByScroll'] ? 0 : $count;
        echo "go to next page: count=$count, firstItemOnCurrPage=$firstItemOnCurrPage, maxItemsCollect=$maxItemsCollect\n";
        echo "do next page\n";
        $resNextPage = $this->doNextPage($params);
        if (is_null($resNextPage)) {
          $pageResult['error'] = 'can not go to current page';
          return false;
        }
        $lastResponse = time();
      } else {
        if (is_array($pageResult)) {
          $pageResult['currFirstItemIndex'] = $firstItemOnCurrPage;
          $pageResult['currMaxItemsCollect'] = $maxItemsCollect;
          $pageResult['firstItemIndex'] = $firstItemIndex + $count - $firstItemOnCurrPage;
          $pageResult['maxItemsCollect'] = $maxItemsCollect - $count;// - $firstItemOnCurrPage;
          echo "this is current page. currFirstItemIndex=".$pageResult['currFirstItemIndex'].", currMaxItemsCollect=".$pageResult['currMaxItemsCollect']."\n";
          echo "this is current page. firstItemIndex=".$pageResult['firstItemIndex'].", maxItemsCollect=".$pageResult['maxItemsCollect']."\n";
        }
        break;
      }

      //sleep(1);

      if ($lastResponse + self::PAGE_LOAD_TIMEOUT < time()) {
        $pageResult['error'] = 'timeout when trying to go to current page';
        return false;
      }
    }

    return true;

  }
  //-----------------------------------------------------

  private function doNextPage($params=NULL) {

    $params = is_null($params) ? $this->params : $params;
    $pagination = $params['pagination'];

    $links = $this->driver->findElements(WebDriverBy::cssSelector($pagination['cssSelector']));
    echo "do next page: count of pagination element=".count($links)."\n";
    foreach ($links as $link) {
      //echo "do next page: get nextPage='".$pagination['nextPage']."' in ".$link->getAttribute('textContent').".\n";
      $nextPage = $this->getExistingElement($link, $pagination['nextPage']);
      if ($nextPage) {
        // filters
        //echo "do next page: current nextPage=".$nextPage->getAttribute('textContent').". checking filter.\n";
        if (!$this->filterIt($nextPage, $pagination['filter']))
          continue;
        echo "do next page: do events.\n";
        // do events
        $this->doEvents($nextPage, $pagination['events'], $params);
        echo "do next page: get attr='".$pagination['valueAttr']."'.\n";
        // scroll to top of page
        //$this->scrollToPageTop();
        // get data
        return $nextPage->getAttribute($pagination['valueAttr']);
      }
    }
    return NULL;
  }
  //-----------------------------------------------------

  private function scrollToPageTop() {
    $this->driver->executeScript("window.scrollTo(0, 0);");
  } // scrollToPageTop
  //-----------------------------------------------------

  public function collect_old($pageNum, $collectAfterCheck=false, &$params=NULL) {

    return true;
    $params = is_null($params) ? $this->params : $params;

    $this->status = 'collecting';
    // collect
    try {
      echo "insertOnly before collect=".$params['insertOnly']."\n";
      if ($collectAfterCheck)
        $this->eraseResult($this->result, $params['pageName']);
      if (!$this->collectFromPage($params, $this->result, $pageNum))
        return NULL;
    } catch (Exception $ex) {
      return NULL;
    } catch (Error $er) {
      return NULL;
    }
    if ($this->status == 'error')
      return NULL;

    // process
    $this->process();
    if ($this->status == 'error')
      return;

    // storage
    $storageResult = $this->storage($params);

    echo "collectAfterCheck=$collectAfterCheck\n";
    if ($collectAfterCheck)
      echo "storage after save:\n";
    else
      echo "storage after check:\n";
    print_r($storageResult);

    if ($collectAfterCheck)
      return;
    elseif (!$params['collectAllData']) { // вернулись параметры только тех данных, которых нет в БД
      $filters = json_decode($storageResult, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        echo "bad data returns from storage\n";
        return;
      }
      if (count($filters) != 0) {
        // обновляем filter в $params и отправляем на новый виток
        $params['parentElement']['filter'] = array();
        foreach($params['parentElement']['values'] as $value)
          foreach($filters as $filter)
            if ($filter['attr'] == $value['fieldName'])
              $params['parentElement']['filter'][] = array('attr' => $value['attr'], 'value' => $filter['value'], 'xor' => false);
        // foreach ($params['childElements']['elements'] as $element) {
        //   $element['filter'] = array();
        //   foreach($element['values'] as $value)
        //     foreach($filters as $filter)
        //       if ($filter['attr'] == $value['fieldName'])
        //         $element['filter'][] = array('attr' => $value['attr'], 'value' => $filter['value'], 'xor' => false);
        // }
        echo "insertOnly=".$params['insertOnly'].", change on true\n";
        $params['insertOnly'] = true;
        $this->collect($pageNum, true, $params);
      }
    }
    if ($this->status == 'error')
      return NULL;

    // finish
    if ($params['allPagesInOneSpider']) {
      $this->eraseResult($this->result, $this->params['pageName']);
      echo "collect from next page...\n";
      echo "(collect) alsoOnCurrentPage=".$params['alsoOnCurrentPage']."\n";
      $this->collect(++$pageNum, false, $params); // collect from next page
      echo "complete\n";
      $this->complete();
    }

    return true;
  }
  //-----------------------------------------------------

  /**
  * Выполняет сбор данных с текущей установленной страницы
  * @param
  * @return bool false - произошли ошибки; true - успешный сбор
  */
  public function collect() {

    System::insertLog("starting collect");

    if (\is_null($this->params)) {
      System::insertLog("no params sets, can't collect");
      return false;
    }

    if (\is_null($this->collector)) {
      System::insertLog("no collector init");
      return false;
    }

    //$this->status = 'collecting';
    // collect
    try {
      // echo "insertOnly before collect=".$params['insertOnly']."\n";
      // if ($collectAfterCheck)
      //   $this->eraseResult($this->result, $params['pageName']);
      // if (!$this->collectFromPage($params, $this->result, $pageNum))
      if (!$this->collector->collectFromPage()) {
        System::insertLog("can't collect from current page");
        return false;
      }
    } catch (Exception $ex) {
      System::insertLog("catch exception: ".$ex->getMessage());
      return false;
    } catch (Error $er) {
      System::insertLog("catch error: ".$er->getMessage());
      return false;
    }
    // if ($this->status == 'error')
    //   return NULL;

    return true;

    // process
    $this->process();
    if ($this->status == 'error')
      return;

    // storage
    $storageResult = $this->storage($params);

    echo "collectAfterCheck=$collectAfterCheck\n";
    if ($collectAfterCheck)
      echo "storage after save:\n";
    else
      echo "storage after check:\n";
    print_r($storageResult);

    if ($collectAfterCheck)
      return;
    elseif (!$params['collectAllData']) { // вернулись параметры только тех данных, которых нет в БД
      $filters = json_decode($storageResult, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        echo "bad data returns from storage\n";
        return;
      }
      if (count($filters) != 0) {
        // обновляем filter в $params и отправляем на новый виток
        $params['parentElement']['filter'] = array();
        foreach($params['parentElement']['values'] as $value)
          foreach($filters as $filter)
            if ($filter['attr'] == $value['fieldName'])
              $params['parentElement']['filter'][] = array('attr' => $value['attr'], 'value' => $filter['value'], 'xor' => false);
        // foreach ($params['childElements']['elements'] as $element) {
        //   $element['filter'] = array();
        //   foreach($element['values'] as $value)
        //     foreach($filters as $filter)
        //       if ($filter['attr'] == $value['fieldName'])
        //         $element['filter'][] = array('attr' => $value['attr'], 'value' => $filter['value'], 'xor' => false);
        // }
        echo "insertOnly=".$params['insertOnly'].", change on true\n";
        $params['insertOnly'] = true;
        $this->collect($pageNum, true, $params);
      }
    }
    if ($this->status == 'error')
      return NULL;

    // finish
    if ($params['allPagesInOneSpider']) {
      $this->eraseResult($this->result, $this->params['pageName']);
      echo "collect from next page...\n";
      echo "(collect) alsoOnCurrentPage=".$params['alsoOnCurrentPage']."\n";
      $this->collect(++$pageNum, false, $params); // collect from next page
      echo "complete\n";
      $this->complete();
    }

    return true;
  }
  //-----------------------------------------------------

  private function eraseResult(&$result, $pageName) {
    $result = array();
    $result['pageName']   = $pageName;
    $result['values']     = array();
    $result['childPages'] = array();
  }
  //-----------------------------------------------------

  private function scrollToElement($link) {
    // $elements = $this->driver->findElements(WebDriverBy::cssSelector($params['pagination']['cssSelector']));
    // echo "result elements=";
    // print_r($elements);
    //$count = count($links);
    //$this->driver->executeScript("arguments[0].scrollIntoView();", array($links[$count - 1]));
    $this->driver->executeScript("arguments[0].scrollIntoView();", array($link));
    // $this->driver->executeScript("arguments[0].style.display='block';
    //   alert('class=' + arguments[0].getAttribute('class'));
    //   alert('innerText=' + arguments[0].innerText);
    //   alert('display=' + arguments[0].style.display);", array($links[$count - 1]));
    //sleep(2);
  }
  //-----------------------------------------------------

  private function getExistingElement($link, $cssSelector) {
    $i = 10; // в общей сложности ждём 5 секунд с периодом по 500 милисекунд
    while ($i-- > 0) {
      echo 'current element class='.$link->getAttribute('class')."\n";
      $elements = $link->findElements(WebDriverBy::cssSelector($cssSelector));
      echo "child element '".$cssSelector."' count=".count($elements)."\n";
      if (count($elements) == 0)
        usleep(500000);
      else
        break;
    }
    return count($elements) == 0 ? false : $elements[0];
  }
  //-----------------------------------------------------

  private function getCurrentDomainURL($url) {
    $domain = '';
    $num = strpos($url, '//');
    if ($num) {
      $num = strpos($url, '/', ++$num);
      if ($num)
        $domain = substr($url, 0, ++$num);
      else
        $domain = $url;
    }
    return $domain;
  }
  //-----------------------------------------------------

  public function process() {
    System::insertLog("starting process");
    System::insertLog("is collector null - ".\is_null($this->collector));

    $result = $this->collector->getResult();
    $this->processResult($this->params, $result);
    $this->collector->setResult($result);

    return true;
  }
  //-----------------------------------------------------

  private function processResult($params, &$result) {
    foreach ($result['values'] as &$record) {
      foreach ($params['process'] as $process) {
        foreach ($record as &$field) {
          if ($field['name'] != $process['fieldName'])
            continue;
          switch ($process['command']) {
            case 'save':
              $path = substr($process['param'], strlen($process['param']) - 1, 1) == '/' ? '' : '/';
              $path = $process['param'].$path.$result['pageName'].'/';
              $fileName = $field['name'].uniqid('');
              if (!file_exists($path))
                mkdir($path);
              if (Link::saveLink($field['value'], $path.$fileName))
                $field['value'] = $path.$fileName;
              break;
            case 'recognize':
              $path = '../temp/';
              $fileName = $field['name'].uniqid('').".png";
              if (!file_exists($path))
                mkdir($path);
              if (Link::saveLink($field['value'], $path.$fileName)) {
                $recog = new Recognize($path.$fileName);
                $field['value'] = $recog->recognize();
              }
              break;
            case 'trim':
              $field['value'] = trim($field['value']);
              break;
            case 'left':
              $param = (int) $process['param'];
              $field['value'] = substr($field['value'], 0, $param);
              break;
            case 'right':
              $param = (int) $process['param'];
              $field['value'] = substr($field['value'], strlen($field['value']) - $param);
              break;
            case 'cutLeft':
              $param = (int) $process['param'];
              $field['value'] = substr($field['value'], $param);
              break;
            case 'cutRight':
              $param = (int) $process['param'];
              $field['value'] = substr($field['value'], 0, strlen($field['value']) - $param);
              break;
          }
        }
      }
    }
    foreach ($params['childPages'] as $childPageIndex => $childPage)
      $this->processResult($childPage, $result['childPages'][$childPageIndex]);
  }
  //-----------------------------------------------------

  public function storage($params=NULL) {
    System::insertLog("starting storage");
    $resultStorage = true;

    $result = $this->collector->getResult();
    if (!$this->storageResult($this->params, $result))
      $resultStorage = false;

    $this->collector->clearResult();

    return $resultStorage;
  }
  //-----------------------------------------------------

  private function storageResult($params, $result) {

    switch ($params['storage']['method']) {
      case "JSON":
        $json = json_encode($result);
        if (json_last_error() === JSON_ERROR_NONE) {
          $path = substr($params['storage']['param'], strlen($params['storage']['param']) - 1, 1) == '/' ? '' : '/';
          $path = $params['storage']['param'].$path.$result['pageName']."_p".$this->currPageNum.".json";
          $f = fopen($path, 'w');
          if ($f) {
            fwrite($f, $json);
            fclose($f);
          }
        }
        else
          echo "bad outer JSON";
        break;
      case "DB":
        $storageURL = "{$this->serverDB}/storage.php?";

        // prepare storage.json
        $result['paramsValues'] = array();
        $result['paramsValues'] = array_merge($result['paramsValues'], $params['parentElement']['values']);
        foreach($params['childElements'] as $element)
          $result['paramsValues'] = array_merge($result['paramsValues'], $element['values']);
        foreach($params['childPages'] as $element) {
          // сделать проверку на совпадение имён таблиц БД
          $result['paramsValues'] = array_merge($result['paramsValues'], $element['parentElement']['values']);
          // пробежаться по childElements
          // пробежаться по childPages ???
        }
        $result['collectAllData'] = $params['collectAllData'];
        $result['insertOnly'] = $params['insertOnly'];

        System::insertLog("send to storage:");
        System::insertLog("count of result = ".count($result['values']));
        print_r($result);
        $echo = file_get_contents($storageURL, false, stream_context_create(array(
          'http' => array(
            'method' => 'POST',
            'header' => 'Content-type: application/json',
            'content' => json_encode($result)
          )
        )));
        System::insertLog("storage return: $echo");

        // обработка ошибок сохранения/проверки

        return $echo;
    }

  }
  //-----------------------------------------------------

  private function getRandomDelay() {
    return rand(50000, 1000000);
  }
  //-----------------------------------------------------

  private function filterIt($link, $filters) {
    $result = true;
    //echo "start filtering. Count = ".count($filters)."\n";
    foreach ($filters as $filter) {
      //echo "filter = '".$filter['value']."'\n";
      if (count($filter['value']) == 0)
        continue;
      $linkValue = $link->getAttribute($filter['attr']);
      //$valueExists = false;
      $valueExists = $filter['xor'];
      foreach($filter['value'] as $filterValue) {
        //echo "$filterValue - $linkValue\n";
        // if ((!$filter['xor'] && ($filterValue == $linkValue))
        //     || ($filter['xor'] && ($filterValue != $linkValue)))
        if ($filterValue == $linkValue)
          //$valueExists = true;
          $valueExists = !$filter['xor'];
      }
      //echo "valueExists = $valueExists\n";
      if (!$valueExists)
        $result = false;
    }
    return $result;
  }
  //-----------------------------------------------------

  private function doEvents($link, $events, &$params) {

    foreach ($events as $event) {

      if ($event == '')
        continue;

      // get value
      // if ($params['parentElement']['waitBetweenEvents'])
      //   usleep($this->getRandomDelay());
      switch ($event) {
        case 'click':
          echo "click event\n";
          //$this->scrollToElement($link);
          $actions = new WebDriverActions($this->driver);
          $actions->moveToElement($link, 10, 5);
          echo "moveToElement\n";
          echo "before click event\n";
          $link->click();
          echo "after click event\n";
          break;
        case 'moveToElement':
          $link->moveToElement();
          break;
        case "scrollTo":
          $this->scrollToElement($link);
          break;
        default:
          echo "no method for event '$event'";
      }

      if ($params['parentElement']['waitBetweenEvents'])
        usleep(1000000);
    }

  }
  //-----------------------------------------------------

  private function getValues($link, $values, $valueNum, &$result) {

    //print_r($values);
    foreach ($values as $value) {

      //echo "before check for duplicate value=".$value['fieldName']."\n";
      // check for duplicate fieldname
      $exists = false;
      echo "valueNum in getValue=$valueNum\n";
      if (!array_key_exists('values', $result) && !array_key_exists($valueNum, $result['values']))
        return false;
      foreach ($result['values'][$valueNum] as $res)
        if ($res['name'] == $value['fieldName'])
          $exists = true;
      if ($exists)
        return false;

      try {
        // get value
        echo "get by atrr=".$value['attr']."\n";
        $val = $link->getAttribute($value['attr']);

        $result['values'][$valueNum][] = array(
          'name' => $value['fieldName'],
          'value' => $val
        );

        echo "current field: ".$value['fieldName']."\ncurrent value: $val\n";
        //print_r($result['values'][$valueNum]);
      } catch (NoSuchElementException $e) {
        echo "NoSuchElementException: ".$e->getMessage();
        continue;
      }
    }

  }
  //-----------------------------------------------------

  public function printResult() {
    if ($this->status == 'complete')
      print_r($this->result);
  }
  //-----------------------------------------------------

}
//-----------------------------------------------------

?>
