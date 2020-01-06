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

    if ((!$this->params['preCollectOnEachPage']) && ($this->pageNum != Collector::FIRST_PAGE))
      return true;

    foreach ($this->params['preCollectElements'] as $element) {

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
          if (!is_null($this->result))
            $this->getValues(array('link' => $link, 'element' => $element));
        }
      }

    }

    return true;

  }
  //-----------------------------------------------------

  public function gotoNextPage() {

    if ($this->isComplete()) {
      if (!\is_null($this->driver))
        $this->clearDriver();
      return true;
    }

    $pagination = $this->params['pagination'];

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

      $this->pageNum++;

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

    $result = array();

    $parentElement = $this->params['parentElement'];
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

    if ($count <= $this->firstItemIndex) {
      System::insertLog("collected items not in current page, go to next page...");
      return $result;
    }

    for ($index = $this->firstItemIndex; $index < $this->firstItemIndex + $this->maxItemsCollect; $index++) {
      if ($index == $count)
        break;

      $el = array('link' => $links[$index], 'element' => $parentElement);
      if (!$this->filterIt($el))
        continue;
      $this->doEvents($el);
      $result[] = $el;
    }

    System::insertLog("params before getting parent elements: firstItemIndex={$this->firstItemIndex}, maxItemsCollect={$this->maxItemsCollect}");
    $this->maxItemsCollect -= count($result);
    if ($this->maxItemsCollect > 0)
      $this->firstItemIndex = 0;

    System::insertLog("params after getting parent elements: firstItemIndex={$this->firstItemIndex}, maxItemsCollect={$this->maxItemsCollect}");

    return $result;
  }
  //-----------------------------------------------------

  public function getChildElements($parent) {
    if (!\is_array($parent)
        || !\array_key_exists('link', $parent)
        || !\array_key_exists('element', $parent))
      return NULL;

    $result = array();

    foreach ($this->params['childElements'] as $element) {
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
        $result[] = $el;
      }
    }
    System::insertLog("found ".count($result)." child elements");

    return $result;
  }
  //-----------------------------------------------------

  public function filterIt($element) {
    $result = true;

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
        $result = false;
    }
    return $result;
  }
  //-----------------------------------------------------

  public function getValues($element, $newValue=false) {
    if (!\is_array($element)
        || !\array_key_exists('link', $element)
        || !\array_key_exists('element', $element))
      return;

    if ($newValue)
      $this->valueNum++;

    foreach ($element['element']['values'] as $value) {

      //echo "before check for duplicate value=".$value['fieldName']."\n";
      // check for duplicate fieldname
      $exists = false;
      //System::insertLog("valueNum in getValue={$this->valueNum}");
      if (!array_key_exists('values', $this->result))
        return false;
      if (\count($this->result['values']) > $this->valueNum) {
        foreach ($this->result['values'][$this->valueNum] as $res)
          if ($res['name'] == $value['fieldName'])
            $exists = true;
        if ($exists)
          continue;
      } else
        $this->result['values'][] = array();

      try {
        // get value
        //System::insertLog("get by atrr=".$value['attr']);
        $val = $element['link']->getAttribute($value['attr']);

        $this->result['values'][$this->valueNum][] = array(
          'name' => $value['fieldName'],
          'value' => $val
        );

        //System::insertLog("current field: ".$value['fieldName']."\ncurrent value: $val");
        //print_r($result['values'][$valueNum]);
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

  }
  //-----------------------------------------------------

  private function getExistingElements($link, $cssSelector) {

    $i = 10; // в общей сложности ждём 5 секунд с периодом по 500 милисекунд
    //echo date('H:i:s')." - start finding elements '$cssSelector'\n";
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
