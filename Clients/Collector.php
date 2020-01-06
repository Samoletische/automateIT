<?php

namespace Clients;

abstract class Collector {
  const NEW_VALUE   = true;
  const FIRST_PAGE  = 0;
  const FIRST_VALUE = -1;

  protected $params;
  protected $result;
  protected $pageNum;
  protected $valueNum;
  protected $firstItemIndex;
  protected $maxItemsCollect;
  //-----------------------------------------------------

  function __construct($params) {
    $this->params = $params;

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
    return ($this->maxItemsCollect <= 0);
  }
  //-----------------------------------------------------
  public function clearResult() {
    $this->valueNum = self::FIRST_VALUE;

    $this->result = array();
    $this->result['pageName']   = $this->params['pageName'];
    $this->result['values']     = array();
    $this->result['childPages'] = array();
  }
  //-----------------------------------------------------
}
//-----------------------------------------------------

?>
