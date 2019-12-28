<?php

namespace Clients;

require_once("lib/phpQuery/phpQuery.php");

class StaticCollector extends Collector {
  function __construct($params, $result, $pageNum) {
    parent::__construct($params, $result, $pageNum);
  }
  //-----------------------------------------------------

  public function doPreCollect() {
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
}
//-----------------------------------------------------

?>
