<?php

namespace Clients;

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
  }
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
  }
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
  }
  //-----------------------------------------------------
}
//-----------------------------------------------------

?>
