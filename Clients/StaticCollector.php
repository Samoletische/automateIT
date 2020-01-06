<?php

namespace Clients;

require_once("lib/phpQuery/phpQuery.php");

class StaticCollector extends Collector {
  // function __construct($params, $result, $pageNum) {
  //   parent::__construct($params, $result, $pageNum);
  function __construct($params) {
    parent::__construct($params);
  }
  //-----------------------------------------------------

  public function doPreCollect() {
  }
  //-----------------------------------------------------

  public function gotoNextPage() {

  }
  //-----------------------------------------------------

  public function getParentElements() {

  }
  //-----------------------------------------------------

  public function getChildElements($parent) {

  }
  //-----------------------------------------------------

  public function filterIt($element) {

  }
  //-----------------------------------------------------

  public function getValues($element, $newValue=false) {

  }
  //-----------------------------------------------------

  public function doEvents($element) {

  }
  //-----------------------------------------------------

  public function collectFromChildPages($parent) {

  }
  //-----------------------------------------------------
}
//-----------------------------------------------------

?>
