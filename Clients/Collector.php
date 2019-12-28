<?php

namespace Clients;

abstract class Collector {
  protected $params;
  protected $result;
  protected $page;
  protected $pageNum;
  protected $firstItemIndex;
  protected $maxItemsCollect;
  //-----------------------------------------------------

  function __construct($params, $result, $pageNum) {
    $this->params = $params;
    $this->result = $result;
    $this->pageNum = $pageNum;
  }
  //-----------------------------------------------------

  abstract public function doPreCollect();
  abstract public function gotoCurrentPage();
  abstract public function gotoNextPage();
  abstract public function getParentElements();
  abstract public function getChildElements($elements);
  abstract public function filterIt($elements);
  abstract public function getValues($elements);
  abstract public function doEvents($elements);
  abstract public function collectFromChildPages($elements);
  abstract public function getResult();
}
//-----------------------------------------------------

?>
