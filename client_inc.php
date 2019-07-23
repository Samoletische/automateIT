<?php

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

require_once('../lib/vendor/autoload.php');
//-----------------------------------------------------
// в файле conf_c.php должны быть определены следующие переменные
//  $serverSelenium   = 'http://(адрес сервера selenium standalone):4444/wd/hub';
//  $closeAfteFinish  = (bool - закрывать браузер после окончания);
require_once('conf_c.php');
//-----------------------------------------------------

// ini_set('error_reporting', E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

abstract class System {

  // sendRequest
  //  Кодирует получаемый через параметр контент, отправляет его в формате JSON
  //  в php-скрипт, адрес которого передан через параметр
  //  Параметры:
  //    $url      - string  - адресная строка до php-скрипта
  //    $content  - array   - параметризированный массив
  //  Возвращаемые значения:
  //    array - ответ от php-скрипта в виде параметризированного массива.
  //    Если входящий контент или ответ невозможно кодировать в/декодировать из JSON, то возвращается NULL.
  static function sendRequest($url, $content) {
    $json = json_encode($content);

    if (json_last_error() === JSON_ERROR_NONE) {
      $result = json_decode(file_get_contents($url, false, stream_context_create(array(
        'http' => array(
          'method'  => 'POST',
          'header'  => 'Content-type: application/json',
          'content' => $json
        )
      ))), true);
      if (json_last_error() === JSON_ERROR_NONE)
        return $result;
      else
        return NULL;
    }
    else
      return NULL;
  } // System::sendRequest
  //-----------------------------------------------------

  // createWeb
  //  Создаёт экземпляр класса Web, предварительно проверив файл параметров типа web.json
  //  на корректность структуры.
  //  Параметры:
  //    $params   - array   - параметризированный массив
  //    $error    - string  - при возникновении ошибки сюда запишется её текст
  //  Возвращаемые значения:
  //    Web - объект, если ошибок не было.
  //    Если были ошибки (проблемы с файлом настроек), то возвращается NULL.
  static function createWeb($params, &$error='') {

    global $webJsonEthalon;

    if (!file_exists($webJsonEthalon)) {
      $error = "Ethalon web.json file '$webJsonEthalon' not exists";
      return NULL;
    }

    $ethalon = json_decode(file_get_contents($webJsonEthalon), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $error = "Can't load ethalon web.json file '$webJsonEthalon'. Bad JSON format.";
      return NULL;
    }

    // check for nessesary fields in JSON
    if ((empty($params)) || (!is_array($params))
        || (empty($ethalon)) || (!is_array($ethalon))) {
      $error = 'Bad web.json structure';
      return NULL;
    }

    $firstNotExistingField = '';
    if (!System::checkJsonStructure($ethalon, $params, $firstNotExistingField)) {
      $error = "Bad web.json structure: not exists '$firstNotExistingField' field";
      return NULL;
    }

    return new Web($params);

  } // System::createWeb
  //-----------------------------------------------------

  // checkJsonStructure
  //  Сравнивает две структуры файлов типа web.json. Одна структура эталонная, другая - проверяемая.
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

} // System
//-----------------------------------------------------

class Web {

  const TIMEOUT = 3; // если 3 минуты нет 'вестей' от Сборщика то выходим из сборки данных

  private $spiders;
  private $startPage;
  private $maxPagesCollect;
  //-----------------------------------------------------

  function __construct($params) {
    global $spiders, $serverSelenium;

    // spiders initialization
    $this->spiders = array();
    foreach($spiders as $token => $url) {
      $result = System::sendRequest($url, array('command' => 'areYouReady', 'token' => $token, 'params' => $params));
      if (($result !== NULL) && (array_key_exists('result', $result)) && ($result['result']))
        echo "ready\n";
        $this->spiders[] = array('token' => $token, 'url' => $url);
    }

    // нет смысла продолжать, если некому собирать инфу
    if (count($this->spiders) == 0)
      return NULL;

    $this->startPage            = $params['startPage'];
    $this->maxPagesCollect      = $params['maxPagesCollect'];
  } // Web::__construct
  //-----------------------------------------------------

  function __destruct() {
    foreach($this->spiders as $spider)
      unset($spider);
  } // Web::__destruct
  //-----------------------------------------------------

  public function collect() {
    $maxPagesCollect = $this->maxPagesCollect == 0 ? 100 : $this->maxPagesCollect;
    $currPage = $this->startPage;
    $pageNum = 0;
    $lastResponse = time();

    while (true) {
      foreach($this->spiders as $spider) {
        if ($currPage == '')
          break;
        echo "getStatus\n";
        $status = $this->sendCommandToSpider($spider, 'getStatus');
        echo "'".$status."'\n";
        if ($status == 'ready') {
          $pageNum++;
          echo 'setCurrPage: '.$currPage."\n";
          $this->sendCommandToSpider($spider, 'setCurrPage', array('currPage' => $currPage, 'pageNum' => $pageNum));
          echo "getNextPage\n";
          $currPage = $this->sendCommandToSpider($spider, 'getNextPage');
          echo "'".$currPage."'\n";
          echo "collect\n";
          $this->sendCommandToSpider($spider, 'collect');
          $lastResponse = time();
        }
      }

      if (($currPage == '') || (($lastResponse + self::TIMEOUT) < time()))
        break;

      sleep(1);
    }
  } // Web::collect
  //-----------------------------------------------------

  private function sendCommandToSpider($spider, $command, $additionParams=NULL) {
    $result = '';
    $params = array('command' => $command, 'token' => $spider['token']);
    if (isset($additionParams))
      $params = array_merge($params, $additionParams);
    $answer = System::sendRequest($spider['url'], $params);
    if (($answer !== NULL) && (array_key_exists('result', $answer)))
      $result = $answer['result'];
    return $result;
  } // Web::sendCommandToSpider
  //-----------------------------------------------------

  public function printResult() {
    if ($this->spiders[0]->getStatus() == "complete")
      $this->spiders[0]->printResult();
  } // Web::printResult
  //-----------------------------------------------------

} // Web
//-----------------------------------------------------

class Spider {

  const PAGE_LOAD_TIMEOUT = 7; // in seconds

  private $driver;
  private $wait;
  private $currPage;
  private $currPageNum;
  private $status; // 'ready', 'collecting', 'processing', 'storaging'
  private $token;

  private $params; // enter data
  private $result; // outer data
  // private $parentElement;
  // private $childElements;
  // private $childPages;
  // private $pagination;
  // private $process;
  //-----------------------------------------------------

  function __construct($token, $params) {
    global $serverSelenium;

    $this->status = 'ready';
    $this->token  = $token;

    if ((!array_key_exists('pageName', $params))
      || (!array_key_exists('storage', $params))
      || (!array_key_exists('parentElement', $params))
      || (!array_key_exists('childElements', $params))
      || (!array_key_exists('childPages', $params))
      || (!array_key_exists('pagination', $params))
      || (!array_key_exists('process', $params)))
      return NULL;

    $this->params = $params;
    $this->driver = NULL;
    $this->wait   = NULL;

    $this->eraseResult($this->result, $this->params['pageName']);
} // Spider::__construct
  //-----------------------------------------------------

  function __destruct() {
    global $closeAfteFinish;

    if ($closeAfteFinish && $this->driver) {
      $this->driver->close();
      $this->driver->quit();
    }
  } // Spider::_destruct
  //-----------------------------------------------------

  public function ReadyToUse() {
    // проверка на доступность Selenium'а
    return true;
  } // Spider::ReadyToUse
  //-----------------------------------------------------

  public function setCurrPage($page, $pageNum) {
    $this->currPage = $page;
    $this->currPageNum = $pageNum;
    return true;
  } // Spider::setCurrPage
  //-----------------------------------------------------

  private function initDriver() {
    global $serverSelenium;

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
    //$this->driver = RemoteWebDriver::create($serverSelenium, DesiredCapabilities::chrome());
    $this->driver = RemoteWebDriver::create($serverSelenium, $capabilities);
    $this->wait = new WebDriverWait($this->driver, 10);
    $this->driver->manage()->timeouts()->pageLoadTimeout(self::PAGE_LOAD_TIMEOUT);
    //$this->driver = RemoteWebDriver::create($serverSelenium, $capabilities);
    try {
      echo "getting page at URL '{$this->currPage}'\n";
      $this->currPage = $this->driver->get($this->currPage);
      echo 'try current URL - '."\n";
      echo $this->driver->getCurrentUrl()."\n";
    } catch (TimeOutException $te) {
      echo 'catch current URL - '."\n";
      echo $this->driver->getTitle()."\n";
    }
  } // Spider::initDriver
  //-----------------------------------------------------

  // getNextPage()
  //  Функция возвращает ссылку на следующую страницу по параметрам пагинатора (только с пагинатором)
  //  В случае отсутствия элемента со ссылкой на следующую страницу возвращается пустая строка
  //  Для страниц с прокруткой вернёт пустую страницу.
  public function getNextPage() {

    $page = '';

    if ($this->driver === NULL)
      $this->initDriver();

    $pagination = $this->params['pagination'];
    if ((!array_key_exists('cssSelector', $pagination))
      || (!array_key_exists('nextPage', $pagination))
      || (!array_key_exists('valueAttr', $pagination))
      || (!array_key_exists('events', $pagination)))
      return $page;

    if (($pagination['cssSelector'] == '')
      || ($pagination['nextPage'] == '')
      || ($pagination['valueAttr'] == ''))
      return $page;

    $links = $this->driver->findElements(WebDriverBy::cssSelector($pagination['cssSelector']));
    foreach ($links as $link) {
      $nextPage = $this->getExistingElement($link, $pagination['nextPage']);
      if ($nextPage) {
        $this->doEvents($nextPage, $pagination['events'], $pagination['filter'], $this->params);
        $page = $nextPage->getAttribute($pagination['valueAttr']);
      }
    }

    return $page;
  } // Spider::getNextPage
  //-----------------------------------------------------

  public function getStatus() {
    return $this->status;
  } // Spider::getStatus
  //-----------------------------------------------------

  public function collect() {
    $this->status = 'collecting';
    // $pcurrPage = $this->currPage;
    // $lastPage = '';
    // $maxPagesCollect = $this->maxPagesCollect == 0 ? 100 : $this->maxPagesCollect;
    // $this->currPageNum = $pageNum;

    if ($this->currPage == '') {
      $this->status = 'complete';
      return;
    }

    // collect
    try {
      if (!$this->collectFromPage($this->params, $this->result, 0))
        return NULL;
    } catch (Exception $ex) {
      return NULL;
    } catch (Error $er) {
      return NULL;
    }

    // process
    if ($this->status == 'error')
      return;
    else
      $this->process();

    // storage
    if ($this->status == 'error')
      return;
    else
      $this->storage();

    // finish
    if ($this->status == 'error')
      return;
    else {
      $this->eraseResult($this->result, $this->params['pageName']);
      $this->status = 'complete';
    }

    return true;
  } // Spider::collect
  //-----------------------------------------------------

  private function eraseResult(&$result, $pageName) {
    $result = array();
    $result['pageName']   = $pageName;
    $result['values']     = array();
    $result['childPages'] = array();
  } // Spider::eraseResult
  //-----------------------------------------------------

  private function collectFromPage($params, &$result, $valueNum=NULL) {

    if ($this->driver === NULL)
      $this->initDriver();

    $scrollPage = false;
    foreach ($params['pagination']['events'] as $event)
      if ($event == 'scrollTo') {
        $scrollPage = true;
        break;
      }

    $firstItemIndex = $params['firstItemIndex'];
    $maxItemsCollect = $params['maxItemsCollect'];
    $count_last = 0;

    echo "pageName=".$params['pageName']."\n";
    //print_r($params);

    try {
      // 1. get parent element
      if ($valueNum === NULL)
        $valueNum = count($result['values']);
      $parentElement = $params['parentElement'];
      echo date('H:i:s')." - parent css: ".$parentElement['cssSelector']."\n";
      while (true) {
        //$links = $this->driver->findElements(WebDriverBy::cssSelector($parentElement['cssSelector']));
        //echo 'current URL - '.$this->driver->getCurrentUrl()."\n";
        $links = $this->getExistingElements($this->driver, $parentElement['cssSelector']);
        if (!$links) {
          echo "find parent elements error\n";
          break;
        }

        $count = count($links);
        echo "count of Links: ".$count."\n";

        if ($count == 0) {
          $result['values'][$valueNum][] = array( 'name' => 'error', 'value' => "parent elements by '".$parentElement['cssSelector']."' not found" );
          return true;
        }

        $currItemIndex = $firstItemIndex;

        // for pagination with scroll event
        if ($scrollPage) {
          if ($count == $count_last)
            break;
          echo "for scroll, $count_last - $count - $firstItemIndex\n";
          $count_last = $count;
          if ($count > $firstItemIndex) {
            echo "befor $firstItemIndex - ";
            $firstItemIndex = $count;
            echo "after $firstItemIndex\n";
          }
        }

        $finishIndex = $currItemIndex + $maxItemsCollect;
        echo "scrollPage=$scrollPage, currItemIndex=$currItemIndex, finishIndex=$finishIndex\n";

        for ($index = $currItemIndex; $index < $finishIndex; $index++) {
        //foreach ($links as $index => $link) {

          if ($index >= $count)
             break;

          $link = $links[$index];
          $errorMessage = '';
          // filters
          if (!$this->filterIt($link, $parentElement['filter']))
            continue;
          // 1. a) do events
          $this->doEvents($link, $parentElement['events'], $params);
          $childElements = $params['childElements'];
          // 1. b) get data
          echo "values of parentElement:\n";
          print_r($parentElement['values']);
          $this->getValues($link, $parentElement['values'], $valueNum, $result);
          // 1. c) get data from child page

          // 2. collect data from elements
          foreach ($childElements['elements'] as $element) {
            //$childLink = $this->getExistingElement($link, $element['cssSelector']);
            $childLinks = $this->getExistingElements($link, $element['cssSelector']);
            echo date('H:i:s').' - count of child element '.$element['cssSelector'].' = '.count($childLinks);
            //if (!$childLink) {
            if (!$childLinks) {
              $errorMessage .= $errorMessage == '' ? '' : '; ';
              $errorMessage .= "find child elements error: ";
              continue;
            }
            foreach ($childLinks as $childLink) {
              // filters
              if (!$this->filterIt($childLink, $element['filter']))
                continue;
              // 2. a) do events
              $this->doEvents($childLink, $element['events'], $params);
              // 2. b) get data
              $this->getValues($childLink, $element['values'], $valueNum, $result);
            }
            // 2. c) get data from child page
          }

          // 3. collect from child pages
          echo 'count of child pages='.count($parentElement['childPages'])."\n";
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
                $this->collectFromPage($childParams, $childResult, $valueNum);
              }

              $this->driver->close();
              $this->driver->switchTo()->window($oldTab);
            }
          }

          //$result['values'][$valueNum++][] = array( 'name' => 'error', 'value' => $errorMessage );
          $valueNum++;
          --$maxItemsCollect;

        }

        echo "for ended, index=$index, maxItemsCollect=$maxItemsCollect\n";
        if ($maxItemsCollect <= 0)
          break;

        if ($scrollPage) {
          $this->scrollToNextPage($links);
        }
        else
          break;
      }
    } catch (UnrecognizedExceptionException $uee) {
      return NULL;
    }
    return true;
    // echo "results of ".$params['pageName'].":\n";
    // print_r($result);
  } // Spider::collectFromPage
  //-----------------------------------------------------

  private function scrollToNextPage($links) {
    // $elements = $this->driver->findElements(WebDriverBy::cssSelector($params['pagination']['cssSelector']));
    // echo "result elements=";
    // print_r($elements);
    $count = count($links);
    $this->driver->executeScript("arguments[0].scrollIntoView();", array($links[$count - 1]));
    // $this->driver->executeScript("arguments[0].style.display='block';
    //   alert('class=' + arguments[0].getAttribute('class'));
    //   alert('innerText=' + arguments[0].innerText);
    //   alert('display=' + arguments[0].style.display);", array($links[$count - 1]));
    sleep(2);
  } // Spider::scrollToNextPage
  //-----------------------------------------------------

  private function getExistingElement($link, $cssSelector) {
    $i = 10; // в общей сложности ждём 5 секунд с периодом по 500 милисекунд
    while ($i-- > 0) {
      $elements = $link->findElements(WebDriverBy::cssSelector($cssSelector));
      if (count($elements) == 0)
        usleep(500000);
      else
        break;
    }
    return count($elements) == 0 ? false : $elements[0];
  } // Spider::getExistingElement
  //-----------------------------------------------------

  private function getExistingElements($link, $cssSelector) {
    $i = 10; // в общей сложности ждём 5 секунд с периодом по 500 милисекунд
    //echo date('H:i:s')." - start finding elements '$cssSelector'\n";
    while ($i-- > 0) {
      $elements = $link->findElements(WebDriverBy::cssSelector($cssSelector));
      if (count($elements) == 0)
        usleep(500000);
      else
        break;
    }
    //echo date('H:i:s')." - end finding elements '$cssSelector', count={count($elements)}\n";
    return count($elements) == 0 ? false : $elements;
  } // Spider::getExistingElement
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
  } // Spider::getCurrentDomain
  //-----------------------------------------------------

  private function process() {
    echo "process\n";
    $this->status = 'processing';
    $this->processResult($this->params, $this->result);
  } // Spider::process
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
  } // Spider::processResult
  //-----------------------------------------------------

  private function storage() {
    echo "storaging\n";
    $this->status = 'storaging';
    $this->storageResult($this->result);
  } // Spider::storage
  //-----------------------------------------------------

  private function storageResult($result) {
    switch ($this->params['storage']['method']) {
      case "JSON":
        $json = json_encode($result);
        if (json_last_error() === JSON_ERROR_NONE) {
          $path = substr($this->params['storage']['param'], strlen($this->params['storage']['param']) - 1, 1) == '/' ? '' : '/';
          $path = $this->params['storage']['param'].$path.$result['pageName']."_p".$this->currPageNum.".json";
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
        $result['paramsValues'] = array_merge($result['paramsValues'], $this->params['parentElement']['values']);
        foreach($this->params['childElements']['elements'] as $element)
          $result['paramsValues'] = array_merge($result['paramsValues'], $element['values']);
        foreach($this->params['childPages'] as $element) {
          // сделать проверку на совпадение имён таблиц БД
          $result['paramsValues'] = array_merge($result['paramsValues'], $element['parentElement']['values']);
          // пробежаться по childElements
          // пробежаться по childPages ???
        }
        $result['overwrite'] = $this->params['storage']['overwrite'];

        echo "send to storage:\n";
        print_r($result);
        $echo = file_get_contents($storageURL, false, stream_context_create(array(
          'http' => array(
            'method' => 'POST',
            'header' => 'Content-type: application/json',
            'content' => json_encode($result)
          )
        )));
        echo "storage return:\n".$echo;
        break;
    }
  } // Spider::storageResult
  //-----------------------------------------------------

  private function getRandomDelay() {
    return rand(50000, 1000000);
  } // Spider::getRandomWait
  //-----------------------------------------------------

  private function filterIt($link, $filters) {
    $result = true;
    echo "start filtering\n";
    foreach ($filters as $filter) {
      $linkValue = $link->getAttribute($filter['attr']);
      $valueExists = false;
      foreach($filter['value'] as $filterValue) {
        echo "$filterValue - $linkValue\n";
        if ($filterValue == $linkValue)
          $valueExists = true;
      }
      echo "valueExists = $valueExists\n";
      if (!$valueExists)
        $result = false;
    }
    return $result;
  } // Spider::filterIt
  //-----------------------------------------------------

  private function doEvents($link, $events, &$params) {

    foreach ($events as $event) {

      if ($event == '')
        continue;

      // get value
      if ($params['parentElement']['waitBetweenEvents'])
        usleep($this->getRandomDelay());
      switch ($event) {
        case 'click':
          $actions = new WebDriverActions($this->driver);
          $actions->moveToElement($link, 10, 5);
          if ($params['parentElement']['waitBetweenEvents'])
            usleep(500000);
          $link->click();
          break;
        case 'moveToElement':
          $link->moveToElement();
          break;
        default:
          echo "no method for event '$event'";
      }
    }

  } // Spider::doEvents
  //-----------------------------------------------------

  private function getValues($link, $values, $valueNum, &$result) {

    foreach ($values as $value) {

      // check for duplicate fieldname
      $exists = false;
      foreach ($result['values'][$valueNum] as $res)
        if ($res['name'] == $value['fieldName'])
          $exists = true;
      if ($exists)
        return false;

      try {
        // get value
        $val = $link->getAttribute($value['attr']);

        $result['values'][$valueNum][] = array(
          'name' => $value['fieldName'],
          'value' => $val
        );

        //echo "current field: ".$value['fieldName']."\ncurrent value: $val\n";
        print_r($result['values'][$valueNum]);
      } catch (NoSuchElementException $e) {
        continue;
      }
    }

  } // Spider::getValues
  //-----------------------------------------------------

  public function printResult() {
    if ($this->status == 'complete')
      print_r($this->result);
  } // Spider::printResult
  //-----------------------------------------------------

} // Spider
//-----------------------------------------------------

abstract class Link {

  public static function saveLink($source, $destination, $currPage='') {
    if ((substr($source, 0, 5) == 'data:') && (strpos($source, 'base64'))) {
      //print_r('save base64 to '.$destination.'...');
      return static::saveBase64($source, $destination);
    }
  } // Link::saveLink
  //-----------------------------------------------------

  private static function saveBase64($source, $destination) {
    // пример строки base64: 'data:image/png;base64,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';

    // Grab the MIME type and the data with a regex for convenience
    if (!preg_match('/data:([^;]*);base64,(.*)/', $source, $matches))
        return false;

    // Decode the data
    $content = base64_decode($matches[2]);

    //$ext = explode('/', $matches[1])[1];

    //$f = fopen($destination.".$ext", 'w');
    $f = fopen($destination, 'w');
    if ($f) {
      fwrite($f, $content);
      fclose($f);
    }

    return true;
  } // Link::saveBase64

} // Link
//-----------------------------------------------------

class Pixel {
    function __construct($r, $g, $b)
    {
        $this->r = ($r > 255) ? 255 : (($r < 0) ? 0 : (int)($r));
        $this->g = ($g > 255) ? 255 : (($g < 0) ? 0 : (int)($g));
        $this->b = ($b > 255) ? 255 : (($b < 0) ? 0 : (int)($b));
    } // Pixel::__construct
} // Pixel
//-----------------------------------------------------

class Recognize {
  private $image;
  private $parts;
  private $width;
  private $height;
  private $tempDir;
  private $spaceWidth;
  private $infelicity;
  private $result;
  private $gage;
  //-----------------------------------------------------

  function __construct($fileName, $tempDir='temp') {
    //echo "\n".$fileName;
    if (!file_exists($fileName))
      return NULL;
    $this->image = imagecreatefrompng($fileName);
    if (!$this->image)
      return NULL;

    $this->spaceWidth = 8;
    $this->infelicity = 1;
    $this->tempDir = $tempDir;
    $this->width = imagesx($this->image);
    $this->height = imagesy($this->image);

    $startPos = strpos($fileName, '/') + 1;
    $lastPos = strpos($fileName, '.png');
    $this->result = substr($fileName, $startPos, $lastPos - $startPos);

    $this->gage = array(0 => ' ', 24 => '-', 208 => '0', 87 => '1', 165 => '2', 186 => '3', 152 => '4', 194 => '5', 220 => '6', 125 => '7', 227 => '8');
  } // Recognize::__construct
  //-----------------------------------------------------

  public function recognize() {
    $result = '';
    $this->contrast();
    $this->parts = array();
    $lastSumm = 0;
    $c = 0;
    $spaces = 0;
    for ($x = 0; $x < $this->width; $x++) {
      $currSumm = 0;
      for ($y = 0; $y < $this->height; $y++) {
        $rgb = imagecolorat($this->image, $x, $y);
        $currSumm += $rgb == 0 ? 1 : 0;
      }
      if (($currSumm == 0) && ($lastSumm != 0)) {
        $c++;
        $spaces = 0;
      }
      elseif (($currSumm != 0) && ($lastSumm == 0))
        $this->parts[$c] = array();
      if ($currSumm != 0)
        $this->parts[$c][] = $currSumm;
      else {
        $spaces++;
        if ($spaces > $this->spaceWidth) {
          $spaces = 0;
          $this->parts[$c][] = 0;
          $c++;
        }
      }
      $lastSumm = $currSumm;
    }
    foreach($this->parts as $part) {
      $summ = 0;
      foreach($part as $col)
        $summ += $col;
      //echo "\n".$summ;
      foreach($this->gage as $k => $v) {
        if ((($k - $this->infelicity) <= $summ) && (($k + $this->infelicity) >= $summ)) {
          if ($v == '6') {
            $c = count($part) - 1;
            if ($part[0] > $part[$c])
              $result .= '6';
            else
              $result .= '9';
          }
          else
            $result .= $v;
          break;
        }
      }
    }
    return $result;
  } // Recognize::recognize
  //-----------------------------------------------------

  private function contrast() {
    for ($x = 0; $x < $this->width; $x++) {
      for ($y = 0; $y < $this->height; $y++) {
        $rgb = imagecolorat($this->image, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $pixel = new Pixel($r, $g, $b);
        $color = imagecolorallocate($this->image, $pixel->r, $pixel->g, $pixel->b);
        imagesetpixel($this->image, $x, $y, $color);
      }
    }
  } // Recognize::contrast
  //-----------------------------------------------------
} // Recognize
//-----------------------------------------------------
?>
