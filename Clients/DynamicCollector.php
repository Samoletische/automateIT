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

  function __construct($params, $result, $pageNum) {
    parent::__construct($params, $result, $pageNum);

    $capabilities = array(
      WebDriverCapabilityType::BROWSER_NAME => WebDriverBrowserType::CHROME,
      WebDriverCapabilityType::PLATFORM => WebDriverPlatform::ANY
    );
    if ($params['proxyServer'] != '')
      $capabilities = array_merge($capabilities, array(
        WebDriverCapabilityType::PROXY => array(
          'proxyType' => 'manual',
          'httpProxy' => $params['proxyServer'],
          'sslProxy' => $params['proxyServer']
        )
      ));

    $this->driver = RemoteWebDriver::create($serverSelenium, $capabilities);
    $this->wait = new WebDriverWait($this->driver, 10);
    $this->driver->manage()->timeouts()->pageLoadTimeout(self::PAGE_LOAD_TIMEOUT);

    try {
      echo "getting page at URL '{$this->currPage}'\n";
      $this->page = $this->driver->get($params['startPage']);
      echo 'try current URL - '."\n";
      echo $this->driver->getCurrentUrl()."\n";
    } catch (TimeOutException $te) {
      echo 'catch current URL - '."\n";
      echo $this->driver->getTitle()."\n";
    }
  }
  //-----------------------------------------------------

  public function doPreCollect() {

    foreach ($this->params[Params::PRE_COLLECT]['elements'] as $element) {

      if ($element[Params::CSS_SELECTOR] != '') {
        $links = $this->getExistingElements($this->driver, $element[Params::CSS_SELECTOR]);
        if (!$links) {
          echo "find elements before collect error\n";
          return false;
        }
        $count = count($links);
        echo "count of preCollect Links: ".$count."\n";
        foreach ($links as $link) {
          // filters
          if (!$this->filterIt($link, $mainElement['filter']))
            continue;
          // do events
          $this->doEvents($link, $mainElement['events'], $params);
          // get data
          if (!is_null($result))
            $this->getValues($link, $mainElement['values'], 0, $result);
        }
      }

    }

    return true;

  }
  //-----------------------------------------------------

  public function gotoCurrentPage() {

  }
  //-----------------------------------------------------

  public function gotoNextPage() {

  }
  //-----------------------------------------------------

  public function getParentElements() {

  }
  //-----------------------------------------------------

  public function getChildElements($elements) {

  }
  //-----------------------------------------------------

  public function filterIt($elements) {

  }
  //-----------------------------------------------------

  public function getValues($elements) {

  }
  //-----------------------------------------------------

  public function doEvents($elements) {

  }
  //-----------------------------------------------------

  public function collectFromChildPages($elements) {

  }
  //-----------------------------------------------------

  public function getResult() {

  }
  //-----------------------------------------------------

  private function getPreCollectElements() {

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
        echo "waiting\n";
        usleep(500000);
      }
      else {
        echo "found\n";
        return $elements;
      }
    }
    echo date('H:i:s')." - end finding elements '$cssSelector', count=".count($elements)."\n";
    return false;

  }
  //-----------------------------------------------------

}
//-----------------------------------------------------

?>
