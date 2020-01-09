<?php

namespace Clients;

abstract class Collector {
  const NEW_VALUE           = true;
  const FIRST_PAGE          = 0;
  const FIRST_VALUE         = -1;
  const COLLECT_FROM_CHILD  = true;

  protected $params;
  protected $result;
  protected $pageNum;
  protected $valueNum;
  protected $firstItemIndex;
  protected $maxItemsCollect;
  protected $collectFromChild;
  protected $childParams;
  protected $childResult;
  protected $childPageNum;
  protected $childValueNum;
  protected $childFirstItemIndex;
  protected $childMaxItemsCollect;
  //-----------------------------------------------------

  function __construct($params) {
    $this->params = $params;

    $this->childFirstItemIndex   = $this->childParams['firstItemIndex'];
    $this->childMaxItemsCollect  = $this->childParams['maxItemsCollect'];

    $this->clearResult();

    $this->firstItemIndex   = $params['firstItemIndex'];
    $this->maxItemsCollect  = $params['maxItemsCollect'];

    $this->pageNum  = self::FIRST_PAGE;
  }
  //-----------------------------------------------------

  abstract public function doPreCollect();
  abstract public function gotoNextPage();
  abstract public function getParentElements();
  abstract public function getChildElements($parent);
  abstract public function filterIt($element);
  abstract public function getValues($element, $newValue=false);
  abstract public function doEvents($element);
  abstract public function collectFromChildPages($parent);
  //-----------------------------------------------------
  public function getResult() {
    //System::insertLog("result of collection:");
    //print_r($this->result);
    return $this->result;
  }
  //-----------------------------------------------------
  public function setResult($result) {
    $this->result = $result;
  }
  //-----------------------------------------------------
  public function isComplete() {
    $maxItemsCollect = $this->collectFromChild ? $this->childMaxItemsCollect : $this->maxItemsCollect;
    return ($maxItemsCollect <= 0);
  }
  //-----------------------------------------------------
  public function clearResult($child=false) {
    if ($child) {
      $this->childValueNum = self::FIRST_VALUE;
      $this->childPageNum  = self::FIRST_PAGE;
      $params = $this->childParams;
      $result = &$this->childResult;
    } else {
      $this->valueNum = self::FIRST_VALUE;
      $params = $this->params;
      $result = &$this->result;
    }

    $result = array();
    $result['pageName']   = $params['pageName'];
    $result['values']     = array();
    $result['childPages'] = array();
  }
  //-----------------------------------------------------
  public function collectFromPage($child=false, $childPageIndex=NULL) {

    if ($child && \is_null($childPageIndex)) {
      System::insertLog("not init child page index");
      return false;
    }

    $this->collectFromChild = $child;

    if (!$this->doPreCollect()) {
      System::insertLog("can't do preCollect");
      return false;
    }

    $elements = $this->getParentElements();
    if (\is_null($elements)) {
      System::insertLog("can't get parent elements");
      return false;
    }

    foreach ($elements as $element) {

      $this->getValues($element, Collector::NEW_VALUE);

      $childElements = $this->getChildElements($element);

      if (\is_null($childElements)) {
        $text = $element['link']->getAttribute("textContent");
        System::insertLog("can't get child elements from $text");
        $childElements = array();
      }

      foreach ($childElements as $childElement) {
        //++ FDO
        System::insertLog("get values from cssSelector '{$childElement['element']['cssSelector']}'");
        //--
        $this->getValues($childElement);
      }

      $this->collectFromChildPages($element);

    }

    $this->gotoNextPage();

    return true;
  }
  //-----------------------------------------------------
}
//-----------------------------------------------------

?>
