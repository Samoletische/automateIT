<?php

namespace Clients;

use Facebook\WebDriver\WebDriverPlatform;
use Facebook\WebDriver\WebDriverWait;
use Facebook\WebDriver\WebDriverBy;

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\WebDriverCapabilityType;
use Facebook\WebDriver\Remote\WebDriverBrowserType;
use Facebook\WebDriver\Remote\RemoteWebDriver;

use Facebook\WebDriver\Interactions\WebDriverActions;

use Facebook\WebDriver\Exception\TimeOutException;
use Facebook\WebDriver\Exception\UnrecognizedExceptionException;

require_once('lib/Selenium/autoload.php');

class DynamicCollector extends Collector {

  const PAGE_LOAD_TIMEOUT = 7; // in seconds

  private $driver;
  private $wait;
  private $serverSelenium;

  function __construct($params, $serverSelenium) {
    parent::__construct($params);

    $this->serverSelenium = $serverSelenium;

    $this->driver = NULL;
  }
  //-----------------------------------------------------

  public function doPreCollect() {

    if (\is_null($this->driver))
      if (!$this->initDriver())
        return false;

    if ($this->collectFromChild) {
      $params   = $this->childParams;
      $pageNum  = $this->childPageNum;
    } else {
      $params   = $this->params;
      $pageNum  = $this->pageNum;
    }

    if ((!$params['preCollectOnEachPage']) && ($pageNum != Collector::FIRST_PAGE))
      return true;

    foreach ($params['preCollectElements'] as $element) {

      if ($element['cssSelector'] != '') {
        $links = $this->getExistingElements($this->driver, $element['cssSelector']);
        if (\is_null($links)) {
          System::insertLog("find elements before collect error");
          return false;
        }
        $count = count($links);
        System::insertLog("count of preCollect Links: ".$count);
        foreach ($links as $link) {
          // filters
          if (!$this->filterIt(array('link' => $link, 'element' => $element)))
            continue;
          // do events
          $this->doEvents(array('link' => $link, 'element' => $element));
          // get data
          $this->getValues(array('link' => $link, 'element' => $element));
        }
      }

    }

    return true;

  }
  //-----------------------------------------------------

  public function gotoNextPage() {

    if ($this->isComplete()) {
      if (!\is_null($this->driver) && !$this->collectFromChild)
        $this->clearDriver();
      return true;
    }

    if ($this->collectFromChild) {
      $params   = $this->childParams;
      $pageNum  = &$this->childPageNum;
    } else {
      $params   = $this->params;
      $pageNum  = &$this->pageNum;
    }

    $pagination = $params['pagination'];

    if ($pagination['cssSelector'] == '')
      return true;

    $links = $this->driver->findElements(WebDriverBy::cssSelector($pagination['cssSelector']));
    System::insertLog("do next page: count of pagination element=".count($links));
    foreach ($links as $link) {

      //echo "do next page: get nextPage='".$pagination['nextPage']."' in ".$link->getAttribute('textContent').".\n";
      $nextPage = $this->getExistingElements($link, $pagination['nextPage']);
      if (\is_null($nextPage))
        continue;

      // filters
      //echo "do next page: current nextPage=".$nextPage->getAttribute('textContent').". checking filter.\n";
      $el = array('link' => $nextPage[0], 'element' => $pagination);
      if (!$this->filterIt($el))
        continue;
      System::insertLog("do next page: do events.");

      // do events
      $this->doEvents($el);
      System::insertLog("do next page: get attr='".$pagination['valueAttr']."'");

      // scroll to top of page
      // todo: возможно, скролить наверх нужно только при paginationByScroll = false...
      // а, возможно, это действие можно "зашить" в events...
      //$this->scrollToPageTop();

      // return data
      $url = ($pagination['valueAttr'] == '') ? '' : $nextPage[0]->getAttribute($pagination['valueAttr']);
      if ($url != '')
        $this->driver->get($url);

      $pageNum++;

      \usleep(1000000);

      return true;
    }

    return false;

  }
  //-----------------------------------------------------

  public function getParentElements() {

    if (\is_null($this->driver))
      if (!$this->initDriver())
        return NULL;

    if ($this->collectFromChild) {
      $params           = $this->childParams;
      $firstItemIndex   = &$this->childFirstItemIndex;
      $maxItemsCollect  = &$this->childMaxItemsCollect;
    } else {
      $params           = $this->params;
      $firstItemIndex   = &$this->firstItemIndex;
      $maxItemsCollect  = &$this->maxItemsCollect;
    }

    $resultArray = array();

    $parentElement = $params['parentElement'];
    System::insertLog("parent css: {$parentElement['cssSelector']}");
    $links = $this->getExistingElements($this->driver, $parentElement['cssSelector']);
    if (\is_null($links)) {
      System::insertLog("find parent elements error");
      return NULL;
    }

    $count = count($links);
    System::insertLog("count of parent elements: ".$count);

    if ($count == 0)
      return NULL;

    if ($count <= $firstItemIndex) {
      System::insertLog("collected items not in current page, go to next page...");
      return $resultArray;
    }

    for ($index = $firstItemIndex; $index < $firstItemIndex + $maxItemsCollect; $index++) {
      if ($index == $count)
        break;

      $el = array('link' => $links[$index], 'element' => $parentElement);
      if (!$this->filterIt($el))
        continue;
      $this->doEvents($el);
      $resultArray[] = $el;
    }

    System::insertLog("params before getting parent elements: firstItemIndex={$firstItemIndex}, maxItemsCollect={$maxItemsCollect}");
    $maxItemsCollect -= count($resultArray);
    if ($maxItemsCollect > 0)
      $firstItemIndex = 0;

    System::insertLog("params after getting parent elements: firstItemIndex={$firstItemIndex}, maxItemsCollect={$maxItemsCollect}");

    return $resultArray;
  }
  //-----------------------------------------------------

  public function getChildElements($parent) {
    if (!\is_array($parent)
        || !\array_key_exists('link', $parent)
        || !\array_key_exists('element', $parent))
      return NULL;

    $params = ($this->collectFromChild) ? $this->childParams : $this->params;

    $resultArray = array();

    foreach ($params['childElements'] as $element) {
      if ($element['fromParent'])
        $childLinks = $this->getExistingElements($parent['link'], $element['cssSelector']);
      else
        $childLinks = $this->getExistingElements($this->driver, $element['cssSelector']);

      if (\is_null($childLinks)) {
        System::insertLog("can't get child elements for selector '{$element['cssSelector']}'");
        continue;
      }

      foreach ($childLinks as $childLink) {
        $el = array('link' => $childLink, 'element' => $element);
        if (!$this->filterIt($el))
          continue;
        $this->doEvents($el);
        $resultArray[] = $el;
      }
    }
    System::insertLog("found ".count($resultArray)." child elements");

    return $resultArray;
  }
  //-----------------------------------------------------

  public function filterIt($element) {

    $resultFilter = true;

    if (!\is_array($element)
        || !\array_key_exists('link', $element)
        || !\array_key_exists('element', $element))
      return false;

    //echo "start filtering. Count = ".count($filters)."\n";
    foreach ($element['element']['filter'] as $filter) {
      //echo "filter = '".$filter['value']."'\n";
      if (count($filter['value']) == 0)
        continue;
      $linkValue = $element['link']->getAttribute($filter['attr']);
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
        $resultFilter = false;
    }

    return $resultFilter;

  }
  //-----------------------------------------------------

  public function getValues($element, $newValue=false) {

    if (!\is_array($element)
        || !\array_key_exists('link', $element)
        || !\array_key_exists('element', $element))
      return;

    if ($this->collectFromChild) {
      $valueNum = &$this->childValueNum;
      $result   = &$this->childResult;
    } else {
      $valueNum = &$this->valueNum;
      $result   = &$this->result;
    }

    if ($newValue)
      $valueNum++;

    foreach ($element['element']['values'] as $value) {

      //echo "before check for duplicate value=".$value['fieldName']."\n";
      // check for duplicate fieldname
      $exists = false;
      //System::insertLog("valueNum in getValue={$this->valueNum}");
      if (!array_key_exists('values', $result))
        return false;
      if (\count($result['values']) > $valueNum) {
        foreach ($result['values'][$valueNum] as $res)
          if ($res['name'] == $value['fieldName'])
            $exists = true;
        if ($exists)
          continue;
      } else
        $result['values'][] = array();

      try {
        // get value
        System::insertLog("get by atrr=".$value['attr']);
        $val = $element['link']->getAttribute($value['attr']);

        $result['values'][$valueNum][] = array(
          'name' => $value['fieldName'],
          'value' => $val
        );

        System::insertLog("current field: ".$value['fieldName']."\ncurrent value: $val");
        print_r($result['values'][$valueNum]);
      } catch (NoSuchElementException $e) {
        System::insertLog("NoSuchElementException: ".$e->getMessage());
        continue;
      }
    }
  }
  //-----------------------------------------------------

  public function doEvents($element) {
    if (!\is_array($element)
        || !\array_key_exists('link', $element)
        || !\array_key_exists('element', $element))
      return;

    foreach ($element['element']['events'] as $event) {

      if ($event == '')
        continue;

      // get value
      // if ($params['parentElement']['waitBetweenEvents'])
      //   usleep($this->getRandomDelay());
      switch ($event) {
        case 'click':
          //System::insertLog("click event");
          //$this->scrollToElement($link);
          //$actions = new WebDriverActions($this->driver);
          //$actions->moveToElement($element['link']);
          //System::insertLog("moveToElement: ".$element['link']->getAttribute("textContent"));
          //System::insertLog("before click event");
          //$element['link']->click(); // с этой функцией глюки
          $this->driver->executeScript("arguments[0].click();", array($element['link']));
          //System::insertLog("after click event");
          break;
        case 'moveToElement':
          $element['link']->moveToElement();
          break;
        case 'scrollTo':
          $this->driver->executeScript("arguments[0].scrollIntoView();", array($element['link']));
          break;
        case 'scrollTop':
          $this->driver->executeScript("window.scrollTo(0, 0);");
          break;
        default:
          System::insertLog("no method for event '$event'");
      }

      if ($this->params['waitBetweenEvents'])
        usleep(1000000);
    }
  }
  //-----------------------------------------------------

  public function collectFromChildPages($parent) {

    if (\count($parent['element']['childPages']) == 0)
      return;

    if ($this->collectFromChild) {
      $firstItemIndex   = &$this->childFirstItemIndex;
      $maxItemsCollect  = &$this->childMaxItemsCollect;
      $params           = $this->childParams;
      $result           = &$this->childResult;
      $pageNum          = &$this->childPageNum;
      $valueNum         = &$this->childValueNum;
    } else {
      $firstItemIndex   = &$this->firstItemIndex;
      $maxItemsCollect  = &$this->maxItemsCollect;
      $params           = $this->params;
      $result           = &$this->result;
      $pageNum          = &$this->pageNum;
      $valueNum         = &$this->valueNum;
    }

    // remember from wich level now collecting
    $collectFromChild_old = $this->collectFromChild;
    $firstItemIndex_old   = $firstItemIndex;
    $maxItemsCollect_old  = $maxItemsCollect;
    $params_old           = $params;
    $pageNum_old          = $pageNum;
    $valueNum_old         = $valueNum;

    System::insertLog("count of child pages=".count($parent['element']['childPages']));
    foreach ($parent['element']['childPages'] as $childPageIndex => $childPage) {

      System::insertLog("start collect from child page");
      // get href of child page
      System::insertLog("selector of child page: '{$childPage['cssSelector']}'");
      $childPagelink = ($childPage['cssSelector'] == '') ? $parent['link'] : $this->getExistingElements($parent['link'], $childPage['cssSelector']);
      if (\is_null($childPagelink)) {
        System::insertLog("can't find child page link");
        continue;
      }
      $href = $childPagelink->getAttribute($childPage['attr']);
      System::insertLog("child href: $href");

      // find params for child page
      $this->childParams = NULL;
      foreach ($params['childPages'] as $childPageParams) {
        System::insertLog($childPageParams['pageName']." - ".$childPage['pageName']);
        if ($childPageParams['pageName'] == $childPage['pageName']) {
          $this->childParams = $childPageParams;
          break;
        }
      }

      if (\is_null($this->childParams)) {
        System::insertLog("can't find params of this childPage - {$childPage['pageName']}");
        continue;
      }

      // create new tab this new URL by href and switch to it
      $oldTab = $this->driver->getWindowHandle();
      System::insertLog("oldTab handle: ".$oldTab);
      $this->driver->ExecuteScript("window.open('".$href."','_blank');");
      $tabs = $this->driver->getWindowHandles();
      $newTab = $tabs[count($tabs) - 1]; // если возвращаются в порядке открытия
      System::insertLog("newTab handle: ".$newTab);
      $this->driver->switchTo()->window($newTab);

      // prepare results and collect
      System::insertLog($params['storage']['method']."-".$this->childParams['storage']['method']);

      $storageParent = explode('?', $params['storage']['param']);
      $storageChild = explode('?', $this->childParams['storage']['param']);
      System::insertLog($storageParent[0]."-".$storageChild[0]);

      if ($storageParent[0] == $storageChild[0]) {
        $this->childValueNum = $valueNum - 1;
        $this->childResult = &$result;
      }
      else {
        $this->childResult = &$result["childPages"][$childPageIndex];
        $this->clearResult(Collector::COLLECT_FROM_CHILD);
      }

      $this->childFirstItemIndex   = $this->childParams['firstItemIndex'];
      $this->childMaxItemsCollect  = $this->childParams['maxItemsCollect'];

      System::insertLog("collect from child page");
      $this->collectFromPage(Collector::COLLECT_FROM_CHILD, $childPageIndex);

      $this->driver->close();
      $this->driver->switchTo()->window($oldTab);
    }

    // restore collect level
    $this->collectFromChild = $collectFromChild_old;
    $firstItemIndex         = $firstItemIndex_old;
    $maxItemsCollect        = $maxItemsCollect_old;
    $params                 = $params_old;
    $pageNum                = $pageNum_old;
    $valueNum               = $valueNum_old;

    //++ FDO
    print_r($this->result);
    //--
  }
  //-----------------------------------------------------

  private function getExistingElements($link, $cssSelector) {

    System::showCalledFrom();
    $i = 10; // в общей сложности ждём 5 секунд с периодом по 500 милисекунд
    //System::insertLog("start finding elements '$cssSelector'");
    while ($i-- > 0) {
      $elements = $link->findElements(WebDriverBy::cssSelector($cssSelector));
      $c = count($elements);
      //echo date("H:i:s")." - (getExistingElements - $comment) count of elements=$c\n";
      if ($c == 0) {
        System::insertLog("waiting");
        usleep(500000);
      }
      else {
        //System::insertLog("found");
        return $elements;
      }
    }
    System::insertLog("end finding elements '$cssSelector', count=".count($elements));
    return NULL;

  }
  //-----------------------------------------------------

  private function initDriver() {
    $capabilities = array(
      WebDriverCapabilityType::BROWSER_NAME => WebDriverBrowserType::CHROME,
      WebDriverCapabilityType::PLATFORM => WebDriverPlatform::ANY
    );
    if ($this->params['proxyServer'] != '')
      $capabilities = array_merge($capabilities, array(
        WebDriverCapabilityType::PROXY => array(
          'proxyType' => 'manual',
          'httpProxy' => $this->params['proxyServer'],
          'sslProxy' => $this->params['proxyServer']
        )
      ));

    $this->driver = RemoteWebDriver::create($this->serverSelenium, $capabilities);
    $this->wait = new WebDriverWait($this->driver, 10);
    $this->driver->manage()->timeouts()->pageLoadTimeout(self::PAGE_LOAD_TIMEOUT);

    try {
      System::insertLog("getting page at URL '{$this->params['startPage']}'");
      //$this->page = $this->driver->get($params['startPage']);
      $this->driver->get($this->params['startPage']);
      System::insertLog('try current URL - ');
      System::insertLog($this->driver->getCurrentUrl());

      return true;
    } catch (TimeOutException $te) {
      System::insertLog('catch current URL - ');
      System::insertLog($this->driver->getTitle());
    }

    return false;
  }
  //-----------------------------------------------------

  private function clearDriver() {
    $this->driver->close();
    $this->driver->quit();
    $this->driver = NULL;
  }
  //-----------------------------------------------------

}
//-----------------------------------------------------

?>
