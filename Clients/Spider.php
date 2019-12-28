<?php

namespace Clients;

require_once("Collector.php");
require_once("StaticCollector.php");
require_once("DynamicCollector.php");

class Spider {

  private $status; // 'ready', 'collecting', 'processing', 'storaging'
  private $token;

  private $params; // enter data
  private $result; // outer data
  //-----------------------------------------------------

  //function __construct($token, $params) {
  function __construct($token) {

    echo "creating Spider with token=$token\n";

    $this->status = 'ready';
    $this->token  = $token;

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
    $this->driver = NULL;
    $this->wait   = NULL;

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

  public function collect($pageNum, $collectAfterCheck=false, &$params=NULL) {

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

  private function eraseResult(&$result, $pageName) {
    $result = array();
    $result['pageName']   = $pageName;
    $result['values']     = array();
    $result['childPages'] = array();
  }
  //-----------------------------------------------------

  private function collectFromPageStatic(&$params, &$result, $valueNum=NULL) {

    $pageText = new Curl();
    $page = phpQuery::newDocument($pageText->get_page($this->currPage));
    $elements = $page->find($params['parentElement']['cssSelector']);

    $count = count($elements->elements);
    $countS = count($elements);
    $firstItemIndex = $params['firstItemIndex'];
    $maxItemsCollect = $params['maxItemsCollect'];
    $finishIndex = $firstItemIndex + $maxItemsCollect;

    echo "count=$count, countS=$countS, currItemIndex=$firstItemIndex, finishIndex=$finishIndex\n";

    for ($index = $firstItemIndex; $index < $finishIndex; $index++) {
      echo date('H:i:s')." - index=$index, firstItemIndex=$firstItemIndex, finishIndex=$finishIndex\n";
      if ($index >= $count)
         break;

      $result['values'][] = array();

      $element = $elements[$index];
      $errorMessage = '';
      // filters
      if (!$this->filterIt($link, $parentElement['filter']))
        continue;
      // 1. a) do events
      $this->doEvents($link, $parentElement['events'], $params);
      $childElements = $params['childElements'];
      // 1. b) get data
      echo date('H:i:s')." - values of parentElement:\n";
      //print_r($parentElement['values']);
      $this->getValues($link, $parentElement['values'], $valueNum, $result);
      // 1. c) get data from child page

      // 2. collect data from child elements
      foreach ($childElements['elements'] as $element) {
        if ($element['fromParent'])
          //$childLink = $this->getExistingElement($link, $element['cssSelector']);
          $childLinks = $this->getExistingElements($link, $element['cssSelector'], "ChildFromParent");
        else
          $childLinks = $this->getExistingElements($this->driver, $element['cssSelector'], "ChildFromTop");
        //echo date('H:i:s').' - count of child element '.$element['cssSelector'].' = '.count($childLinks)."\n";
        //if (!$childLink) {
        if (!$childLinks) {
          $errorMessage .= $errorMessage == '' ? '' : '; ';
          $errorMessage .= "find child elements error: ";
          continue;
        }
        foreach ($childLinks as $childLink) {
          // filters
          //echo "filter child\n";
          if (!$this->filterIt($childLink, $element['filter']))
            continue;
          //echo "event child\n";
          // 2. a) do events
          $this->doEvents($childLink, $element['events'], $params);
          //echo "get value child. valueNum=$valueNum\n";
          // 2. b) get data
          $this->getValues($childLink, $element['values'], $valueNum, $result);
          //echo "getted value\n";
        }
        // 2. c) get data from child page
      }

      // 3. collect from child pages
      echo date('H:i:s').' - count of child pages='.count($parentElement['childPages'])."\n";
      foreach ($parentElement['childPages'] as $childPageIndex => $childPage) {

        echo "start collect from child page\n";
        // get href of child page
        $childPagelink = $this->getExistingElement($link, $childPage['cssSelector']);
        if (!$childPagelink) {
          $errorMessage .= $errorMessage == '' ? '' : '; ';
          $errorMessage .= "find child pages error: ";
          continue;
        }
        if ($childPage['attr'] == 'text')
          $href = $childPagelink->getText();
        else
          $href = $childPagelink->getAttribute($childPage['attr']);

        echo "child href: $href\n";

        // find params for child page
        $childParams = NULL;
        foreach ($params['childPages'] as $childPageParams) {
          echo $childPageParams['pageName']." - ".$childPage['pageName']."\n";
          if ($childPageParams['pageName'] == $childPage['pageName']) {
            $childParams = $childPageParams;
            break;
          }
        }

        if ($childParams !== NULL) {
          // create new tab this new URL by href and switch to it
          $oldTab = $this->driver->getWindowHandle();
          echo "oldTab handle: ".$oldTab."\n";
          $this->driver->ExecuteScript("window.open('".$href."','_blank');");
          $tabs = $this->driver->getWindowHandles();
          $newTab = $tabs[count($tabs) - 1]; // если возвращаются в порядке открытия
          echo "newTab handle: ".$newTab."\n";
          $this->driver->switchTo()->window($newTab);

          // prepare results and collect
          echo $params['storage']['method']."-".$childParams['storage']['method']."\n";
          if (($params['storage']['method'] == "DB") && ($childParams['storage']['method'] == "DB")) {
            $storageParent = explode('?', $params['storage']['param']);
            $storageChild = explode('?', $childParams['storage']['param']);

            echo $storageParent[0]."-".$storageChild[0]."\n";
            if ($storageParent[0] == $storageChild[0])
              $childResult = &$result;
            else {
              $childResult = &$result["childPages"][$childPageIndex];
              $this->eraseResult($childResult, $childParams['pageName']);
            }

            echo "collect from child page\n";
            $this->collectFromPageDynamic($childParams, $childResult, $valueNum);
          }

          $this->driver->close();
          $this->driver->switchTo()->window($oldTab);
        }
      }

      //$result['values'][$valueNum++][] = array( 'name' => 'error', 'value' => $errorMessage );
      $valueNum++;
      --$maxItemsCollect;

      if ($maxItemsCollect <= 0) {
        $this->currPage = '';
        //break;
        return true;
      }

    }

  }
  //-----------------------------------------------------

  private function collectFromPageDynamic(&$params, &$result, $valueNum=NULL) {

    $firstItemIndex = $params['firstItemIndex'];
    $maxItemsCollect = $params['maxItemsCollect'];
    $count_last = 0;

    echo "pageName=".$params['pageName']."\n";
    //print_r($params);

    try {
      if ($valueNum === NULL)
        $valueNum = count($result['values']);

      echo "(collectFromPage) alsoOnCurrentPage=".$params['alsoOnCurrentPage']."\n";
      if (!$params['alsoOnCurrentPage']) {
        if (!$this->doPreCollect($params, $result))
          throw new UnrecognizedExceptionException();

        if ($params['paginationHaveSameAddress']) {
          $pageResult = array('firstItemIndex' => $params['firstItemIndex'], 'maxItemsCollect' => $params['maxItemsCollect']);
          $this->goToCurrentPage($params, $pageResult);
          $firstItemIndex = $pageResult['currFirstItemIndex'];
          $maxItemsCollect = $pageResult['currMaxItemsCollect'];
        }
      }

      //echo 'after pre-collect, on current page - firstItemIndex = '.$pageResult['currFirstItemIndex'].', maxItemsCollect = '.$pageResult['currMaxItemsCollect']."\n";

      // 1. get parent element
      $parentElement = $params['parentElement'];
      echo date('H:i:s')." - parent css: ".$parentElement['cssSelector']."\n";
  //    while (true) {
        //$links = $this->driver->findElements(WebDriverBy::cssSelector($parentElement['cssSelector']));
        //echo 'current URL - '.$this->driver->getCurrentUrl()."\n";
        $links = $this->getExistingElements($this->driver, $parentElement['cssSelector'], "parentElement");
        if (!$links) {
          echo "find parent elements error\n";
          //break;
          return false;
        }

        $count = count($links);
        echo "count of Links: ".$count."\n";

        if ($count == 0) {
          //$result['values'][$valueNum][] = array( 'name' => 'error', 'value' => "parent elements by '".$parentElement['cssSelector']."' not found" );
          return true;
        }

        // $finishIndex = $currItemIndex + $maxItemsCollect;
        $finishIndex = $firstItemIndex + $maxItemsCollect;
        echo "scrollPage=".$params['paginationByScroll'].", currItemIndex=$firstItemIndex, finishIndex=$finishIndex\n";

        //for ($index = $currItemIndex; $index < $finishIndex; $index++) {
        for ($index = $firstItemIndex; $index < $finishIndex; $index++) {
        //foreach ($links as $index => $link) {
          echo date('H:i:s')." - index=$index, firstItemIndex=$firstItemIndex, finishIndex=$finishIndex\n";
          if ($index >= $count)
             break;

          $result['values'][] = array();

          $link = $links[$index];
          $errorMessage = '';
          // filters
          if (!$this->filterIt($link, $parentElement['filter']))
            continue;
          // 1. a) do events
          $this->doEvents($link, $parentElement['events'], $params);
          $childElements = $params['childElements'];
          // 1. b) get data
          echo date('H:i:s')." - values of parentElement:\n";
          //print_r($parentElement['values']);
          $this->getValues($link, $parentElement['values'], $valueNum, $result);
          // 1. c) get data from child page

          // 2. collect data from child elements
          foreach ($childElements['elements'] as $element) {
            if ($element['fromParent'])
              //$childLink = $this->getExistingElement($link, $element['cssSelector']);
              $childLinks = $this->getExistingElements($link, $element['cssSelector'], "ChildFromParent");
            else
              $childLinks = $this->getExistingElements($this->driver, $element['cssSelector'], "ChildFromTop");
            //echo date('H:i:s').' - count of child element '.$element['cssSelector'].' = '.count($childLinks)."\n";
            //if (!$childLink) {
            if (!$childLinks) {
              $errorMessage .= $errorMessage == '' ? '' : '; ';
              $errorMessage .= "find child elements error: ";
              continue;
            }
            foreach ($childLinks as $childLink) {
              // filters
              //echo "filter child\n";
              if (!$this->filterIt($childLink, $element['filter']))
                continue;
              //echo "event child\n";
              // 2. a) do events
              $this->doEvents($childLink, $element['events'], $params);
              //echo "get value child. valueNum=$valueNum\n";
              // 2. b) get data
              $this->getValues($childLink, $element['values'], $valueNum, $result);
              //echo "getted value\n";
            }
            // 2. c) get data from child page
          }

          // 3. collect from child pages
          echo date('H:i:s').' - count of child pages='.count($parentElement['childPages'])."\n";
          foreach ($parentElement['childPages'] as $childPageIndex => $childPage) {

            echo "start collect from child page\n";
            // get href of child page
            $childPagelink = $this->getExistingElement($link, $childPage['cssSelector']);
            if (!$childPagelink) {
              $errorMessage .= $errorMessage == '' ? '' : '; ';
              $errorMessage .= "find child pages error: ";
              continue;
            }
            if ($childPage['attr'] == 'text')
              $href = $childPagelink->getText();
            else
              $href = $childPagelink->getAttribute($childPage['attr']);

            echo "child href: $href\n";

            // find params for child page
            $childParams = NULL;
            foreach ($params['childPages'] as $childPageParams) {
              echo $childPageParams['pageName']." - ".$childPage['pageName']."\n";
              if ($childPageParams['pageName'] == $childPage['pageName']) {
                $childParams = $childPageParams;
                break;
              }
            }

            if ($childParams !== NULL) {
              // create new tab this new URL by href and switch to it
              $oldTab = $this->driver->getWindowHandle();
              echo "oldTab handle: ".$oldTab."\n";
              $this->driver->ExecuteScript("window.open('".$href."','_blank');");
              $tabs = $this->driver->getWindowHandles();
              $newTab = $tabs[count($tabs) - 1]; // если возвращаются в порядке открытия
              echo "newTab handle: ".$newTab."\n";
              $this->driver->switchTo()->window($newTab);

              // prepare results and collect
              echo $params['storage']['method']."-".$childParams['storage']['method']."\n";
              if (($params['storage']['method'] == "DB") && ($childParams['storage']['method'] == "DB")) {
                $storageParent = explode('?', $params['storage']['param']);
                $storageChild = explode('?', $childParams['storage']['param']);

                echo $storageParent[0]."-".$storageChild[0]."\n";
                if ($storageParent[0] == $storageChild[0])
                  $childResult = &$result;
                else {
                  $childResult = &$result["childPages"][$childPageIndex];
                  $this->eraseResult($childResult, $childParams['pageName']);
                }

                echo "collect from child page\n";
                $this->collectFromPageDynamic($childParams, $childResult, $valueNum);
              }

              $this->driver->close();
              $this->driver->switchTo()->window($oldTab);
            }
          }

          //$result['values'][$valueNum++][] = array( 'name' => 'error', 'value' => $errorMessage );
          $valueNum++;
          --$maxItemsCollect;

          if ($maxItemsCollect <= 0) {
            $this->currPage = '';
            //break;
            return true;
          }

        }

        // go to next page
        echo "for ended, index=$index, maxItemsCollect=$maxItemsCollect\n";
        if (!$params['allPagesInOneSpider']) {
          $this->currPage = '';
          //break;
          return false;
        }

        // у нас три варианта пагинаторов:
        // - новый контент появляется когда доскролим до конца
        // - новый контент подгружается ajax'ом, адрес траницы при этом не меняется.
        // Сюда же относятся страницы, где контент появляется по кнопке "Ещё", например.
        // - классический пагинатор: берём адрес следующей страницы из кнопки "Следующая".
        $params['alsoOnCurrentPage'] = true;
        if ($params['paginationByScroll'])
          $this->scrollToElement($link);
        elseif ($params['paginationHaveSameAddress']) {
          echo "paginationHaveSameAddress. goToCurrentPage: index=$index, maxItemsCollect=$maxItemsCollect\n";
          $pageResult = array('firstItemIndex' => $index, 'maxItemsCollect' => $maxItemsCollect);
          if (!$this->goToCurrentPage($params, $pageResult)) {
            $this->currPage = '';
            //break;
            return false;
          }
          // $firstItemIndex = $pageResult['firstItemIndex'];
          // $maxItemsCollect = $pageResult['maxItemsCollect'];
          $params['firstItemIndex'] = $pageResult['firstItemIndex'];
          $params['maxItemsCollect'] = $pageResult['maxItemsCollect'];
          echo "paginationHaveSameAddress. go From CurrentPage: firstItemIndex={$params['firstItemIndex']}, maxItemsCollect={$params['maxItemsCollect']}\n";
          $resNextPage = $this->doNextPage($params);
          if (is_null($resNextPage)) {
            $this->currPage = '';
            //break;
            return false;
          }
        } else {
          $resNextPage = $this->doNextPage($params);
          if (is_null($resNextPage)) {
            $this->currPage = '';
            //break;
            return false;
          }
          $this->setCurrPage($resNextPage, ++$this->currPageNum, $params['allPagesInOneSpider'] ? 0 : $index, $maxItemsCollect);
          $this->currPage = $this->driver->get($this->currPage);
          $params['alsoOnCurrentPage'] = false;
        }
//        break;
//      }
    } catch (UnrecognizedExceptionException $uee) {
      echo "UnrecognizedExceptionException: ".$uee->getMessage()."\n";
      $this->currPage = '';
      return false;
    } catch (StaleElementReferenceException $sere) {
      echo "StaleElementReferenceException: ".$uee->getMessage()."\n";
      $this->currPage = '';
      return false;
    }
    return true;
    // echo "results of ".$params['pageName'].":\n";
    // print_r($result);
  }
  //-----------------------------------------------------

  private function collectFromPage(&$params, &$result, $pageNum) {

    $collector = $params['needInteractive'] ? new DynamicCollector($params, $result, $pageNum) : new StaticCollector($params, $result, $pageNum);

    if (!$collector->doPreCollect())
      return false;

    if (!$collector->gotoCurrentPage())
      return false;

    $elements = $collector->getParentElements();
    if (count($elements) == 0)
      return false;

    foreach ($elements as $element) {

      if (!$collector->filterIt($element))
        continue;
      $collector->doEvents($element);
      $collector->getValues($element);

      $childElements = $collector->getChildElements($element);
      foreach ($childElement as $childElement) {
        if (!$collector->filterIt($childElement))
          continue;
        $collector->doEvents($childElement);
        $collector->getValues($childElement);
      }

      $collector->collectFromChildPages($element);

    }

    $collector->gotoNextPage();

    $collector->getResult($params, $result);

    return true;
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

  private function process() {
    echo "process\n";
    $this->status = 'processing';
    $this->processResult($this->params, $this->result);
  }
  //-----------------------------------------------------

  private function processResult($params, &$result) {
    foreach ($params['process'] as $process) {
      foreach ($result['values'] as &$record) {
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

  private function storage($params=NULL) {
    echo "storaging\n";
    $this->status = 'storaging';
    return $this->storageResult($this->result, $params);
  }
  //-----------------------------------------------------

  private function storageResult($result, $params=NULL) {

    $params = is_null($params) ? $this->params : $params;

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
        $storageURL = 'http://192.168.0.20/automateIT/storage.php?';

        // prepare storage.json
        $result['paramsValues'] = array();
        $result['paramsValues'] = array_merge($result['paramsValues'], $params['parentElement']['values']);
        foreach($params['childElements']['elements'] as $element)
          $result['paramsValues'] = array_merge($result['paramsValues'], $element['values']);
        foreach($params['childPages'] as $element) {
          // сделать проверку на совпадение имён таблиц БД
          $result['paramsValues'] = array_merge($result['paramsValues'], $element['parentElement']['values']);
          // пробежаться по childElements
          // пробежаться по childPages ???
        }
        $result['collectAllData'] = $params['collectAllData'];
        $result['insertOnly'] = $params['insertOnly'];

        echo "send to storage:\n";
        echo "count of result = ".count($result['values'])."\n";
        print_r($result);
        $echo = file_get_contents($storageURL, false, stream_context_create(array(
          'http' => array(
            'method' => 'POST',
            'header' => 'Content-type: application/json',
            'content' => json_encode($result)
          )
        )));
        echo "storage return:\n";

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
