<?php

namespace Clients;

abstract class Link {

  public static function saveLink($source, $destination, $currPage='') {
    if ((substr($source, 0, 5) == 'data:') && (strpos($source, 'base64'))) {
      //print_r('save base64 to '.$destination.'...');
      return static::saveBase64($source, $destination);
    }
  }
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
  }

}
//-----------------------------------------------------

?>
