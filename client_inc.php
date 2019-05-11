<?php

namespace Facebook\WebDriver;

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Interactions\WebDriverActions;

require_once('../lib/vendor/autoload.php');
//-----------------------------------------------------
// в файле conf_c.php должны быть определены следующие переменные
//  $serverSelenium   = 'http://(адрес сервера selenium standalone):4444/wd/hub';
//  $closeAfteFinish  = (bool - закрывать браузер после окончания);
require_once('conf_c.php');
//-----------------------------------------------------

class System {
  // sendRequest
  //  Кодирует получаемый через параметр контент, отправляет его в формате JSON
  //  в php-скрипт, адрес которого передан через параметр
  //  Параметры:
  //    $url - адресная строка до php-скрипта
  //    $content - параметризированный массив
  //  Возвращаемые значения:
  //    Ответ от php-скрипта в формате JSON.
  //    Если входящий контент или ответ невозможно кодировать в/декодировать из JSON, то возвращается NULL.
  public static function sendRequest($url, $content) {
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
  }
}
//-----------------------------------------------------

class Web {

  private $spiders;
  private $startPage;
  private $maxPagesCollect;
  //-----------------------------------------------------

  function __construct($params) {
    global $spiders, $serverSelenium;

    $this->spiders = array();
    foreach($spiders as $token => $url) {
      $result = System::sendRequest($url, array('command' => 'areYouReady', 'token' => $token, 'serverSelenium' => $serverSelenium));
      echo date('h:i:s')."\n";
      if (($result !== NULL) && (array_key_exists('result', $result)) && ($result['result']))
        echo "ready\n";
        $this->spiders[] = array('token' => $token, 'url' => $url);
    }

    // нет смысла продолжать, если некому собирать инфу
    if (count($this->spiders) == 0)
      return NULL;

    $this->startPage            = $params['startPage'];
    $this->maxPagesCollect      = $params['maxPagesCollect'];
  } // __construct
  //-----------------------------------------------------

  function __destruct() {
    foreach($this->spiders as $spider)
      unset($spider);
  } // __destruct
  //-----------------------------------------------------

  public function collect($params) {
    $maxPagesCollect = $this->maxPagesCollect == 0 ? 100 : $this->maxPagesCollect;
    $currPage = $this->startPage;
    $pageNum = 0;

    while (true) {
      foreach($this->spiders as $spider) {
        $status = '';
        $result = System::sendRequest($spider, array('command' => 'getStatus'));
        if (($result !== NULL) && (array_key_exists('result', $result)) && ($result['result']))
          $status = $result['result'];
        if ((($status == 'new') || ($status == 'complete')) && ($currPage != '')) {
          $pageNum++;
          $currPage = '';
          $result = System::sendRequest($spider, array('command' => 'getNextPage', 'currPage' => $currPage, 'pageNum' => $pageNum));
          if (($result !== NULL) && (array_key_exists('result', $result)) && ($result['result']))
            $currPage = $result['result'];
          $spider->setCurrPage($currPage);
          $currPage = $spider->getNextPage();
          $spider->collect($pageNum);
        }
      }

      if ($currPage == '')
        break;
    }
  } // collect
  //-----------------------------------------------------

  public function printResult() {
    if ($this->spiders[0]->getStatus() == "complete")
      $this->spiders[0]->printResult();
  } // printResult
  //-----------------------------------------------------

} // Web
//-----------------------------------------------------

class Spider {

  private $driver;
  private $currPage;
  private $currPageNum;
  private $result;
  private $status; // 'new', 'badEnterData', 'collecting', 'processing', 'storaging', 'complete'

  // private $startPage;
  // private $maxPagesCollect;
  private $storageMethod;
  private $parentElement;
  private $childElements;
  private $childPages;
  private $pagination;
  private $process;
  //-----------------------------------------------------

  function __construct($params) {
    global $serverSelenium;

    $this->status                 = 'new';
    $this->result                 = array();

    try {
      $this->eraseResult($params['pageName']);

      // $this->startPage            = $params['startPage'];
      // $this->maxPagesCollect      = $params['maxPagesCollect'];
      $this->storage              = $params['storage'];
      $this->parentElement        = $params['parentElement'];
      $this->childElements        = $params['childElements'];
      $this->childPages           = $params['childPages'];
      $this->pagination           = $params['pagination'];
      $this->process              = $params['process'];

      $this->driver               = RemoteWebDriver::create($serverSelenium, DesiredCapabilities::chrome());
    }
    catch (Exception $e) {
      $this->status               = 'badEnterData';
    }
} // __construct
  //-----------------------------------------------------

  function __destruct() {
    global $closeAfteFinish;

    if ($closeAfteFinish && $this->driver) {
      $this->driver->quit();
      $this->driver->close();
    }
  } // _destruct
  //-----------------------------------------------------

  public function ReadyToUse($serverSelenium) {
    // проверка на доступность Selenium'а
    return true;
  } // ReadyToUse
  //-----------------------------------------------------

  public function setCurrPage($page) {
    $this->currPage = $page;
  } // setCurrPage
  //-----------------------------------------------------

  // getNextPage()
  //  Функция возвращает ссылку на следующую страницу по параметрам пагинатора
  //  В случае отсутствия элемента со ссылкой на следующую страницу возвращается пустая строка
  public function getNextPage() {
    $page = '';
    $links = $this->driver->findElements(WebDriverBy::cssSelector($this->pagination['cssSelector']));
    //$c = 3;
    foreach ($links as $link) {
      $nextPage = $this->getExistingElement($link, $this->pagination['nextPage']);
      if ($nextPage) {
        $this->doEvents($nextPage, $this->pagination['events']);
        $page = $nextPage->getAttribute($this->pagination['valueAttr']);
      }
    }
    return $page;
  } // getNextPage
  //-----------------------------------------------------

  public function getStatus() {
    return $this->status;
  } // getStatus
  //-----------------------------------------------------

  public function collect($pageNum) {
    $this->status = 'collecting';
    // $pcurrPage = $this->currPage;
    // $lastPage = '';
    // $maxPagesCollect = $this->maxPagesCollect == 0 ? 100 : $this->maxPagesCollect;
    $this->currPageNum = $pageNum;

    if ($this->currPage == '') {
      $this->status = 'complete';
      return;
    }

    // collect
    $this->collectFromPage($this->currPage);

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
      $this->eraseResult($result['pageName']);
      $this->status = 'complete';
    }
  } // collect
  //-----------------------------------------------------

  private function eraseResult($pageName) {
    $this->result['pageName']   = $pageName;
    $this->result['values']     = array();
    $this->result['childPages'] = array();
  } // eraseResult
  //-----------------------------------------------------

  private function collectFromPage($page, $childPage = false, $num = 0) {
    // 1. get parent element
    $this->currPage = $this->driver->get($page);
    $parentElement = $childPage ? $this->childPages[$num]['parentElement'] : $this->parentElement;
    $links = $this->driver->findElements(WebDriverBy::cssSelector($parentElement['cssSelector']));
    //$c = 3;
    foreach ($links as $link) {
      $errorMessage = '';
      $valueNum = count($this->result['values']);
      // 1. a) do events
      $this->doEvents($link, $parentElement['events']);
      $childElements = $childPage ? $this->childPages[$num]['childElements'] : $this->childElements;
      // 1. b) get data
      $this->getValues($link, $parentElement['values'], $valueNum);
      // 1. c) get data from child page

      // 2. collect data from elements
      foreach ($childElements['elements'] as $element) {
        $childLink = $this->getExistingElement($link, $element['cssSelector']);
        if (!$childLink) {
          $errorMessage .= $errorMessage == '' ? '' : '; ';
          $errorMessage .= "find child error: $e";
          continue;
        }
        // 2. a) do events
        $this->doEvents($childLink, $element['events']);
        // 2. b) get data
        $this->getValues($childLink, $element['values'], $valueNum);
        // 2. c) get data from child page

      }
      //$c--;

      $this->result['values'][$valueNum][] = array( 'name' => 'error', 'value' => $errorMessage );
    }
    // 3. go to next page
  } // collect
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
  } // getExistingElement
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
  } // getCurrentDomain
  //-----------------------------------------------------

  private function process() {
    $this->status = 'processing';
    $this->processResult($this->result);
  } // process
  //-----------------------------------------------------

  private function processResult(&$result) {
    foreach ($this->process as $process) {
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
          }
        }
      }
    }
  } // processResult
  //-----------------------------------------------------

  private function storage() {
    $this->status = 'storaging';
    $this->storageResult($this->result);
  } // storage
  //-----------------------------------------------------

  private function storageResult($result) {
    switch ($this->storage['method']) {
      case "JSON":
        $json = json_encode($result);
        if (json_last_error() === JSON_ERROR_NONE) {
          $path = substr($this->storage['param'], strlen($this->storage['param']) - 1, 1) == '/' ? '' : '/';
          $path = $this->storage['param'].$path.$result['pageName']."_p".$this->currPageNum.".json";
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
        $result['parentElement'] = $this->parentElement;
        $echo = file_get_contents($storageURL, false, stream_context_create(array(
          'http' => array(
            'method' => 'POST',
            'header' => 'Content-type: application/json',
            'content' => json_encode($result)
          )
        )));
        echo $echo;
        break;
    }
  } // storageResult
  //-----------------------------------------------------

  private function getRandomDelay() {
    return rand(50000, 1000000);
  } // getRandomWait
  //-----------------------------------------------------

  private function doEvents($link, $events) {
    foreach ($events as $event) {
      if ($event == '')
        continue;
      if ($this->parentElement['waitBetweenEvents'])
        usleep($this->getRandomDelay());
      switch ($event) {
        case 'click':
          $actions = new WebDriverActions($this->driver);
          $actions->moveToElement($link, 10, 5);
          if ($this->parentElement['waitBetweenEvents'])
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
  } // doEvents
  //-----------------------------------------------------

  private function getValues($link, $values, $valueNum) {
    foreach ($values as $value) {
      try {
        $this->result['values'][$valueNum][] = array(
          'name' => $value['fieldName'],
          'value' => $link->getAttribute($value['attr'])
        );
      } catch (NoSuchElementException $e) {
        continue;
      }
    }
  } // getValues
  //-----------------------------------------------------

  public function printResult() {
    if ($this->status == 'complete')
      print_r($this->result);
  } // printResult
  //-----------------------------------------------------

} // Spider
//-----------------------------------------------------

class Link {

  public static function saveLink($source, $destination, $currPage='') {
    if ((substr($source, 0, 5) == 'data:') && (strpos($source, 'base64'))) {
      //print_r('save base64 to '.$destination.'...');
      return static::saveBase64($source, $destination);
    }
  } // saveLink
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
  } // saveBase64

} // Link
//-----------------------------------------------------

class Pixel {
    function __construct($r, $g, $b)
    {
        $this->r = ($r > 255) ? 255 : (($r < 0) ? 0 : (int)($r));
        $this->g = ($g > 255) ? 255 : (($g < 0) ? 0 : (int)($g));
        $this->b = ($b > 255) ? 255 : (($b < 0) ? 0 : (int)($b));
    } // Pixel
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
  } // __construct
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
  } // recognize
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
  } // contrast
  //-----------------------------------------------------
} // Recognize
//-----------------------------------------------------
?>
