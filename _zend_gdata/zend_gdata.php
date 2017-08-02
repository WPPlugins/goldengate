<?php
  /*
  Plugin Name: Zend GData Framework
  Plugin URI: http://code.google.com/p/goldengate/
  Description: Ports the Zend GData Framework to Wordpress.
  Version: 1.5.1.1
  Author: Jeff Fisher
  Author URI: http://code.google.com/p/goldengate/wiki/AboutAuthor
  */
  
  if(! preg_match('/5\./', phpversion())) {
    add_action('admin_notices', 'zend_gdata_need_php5');
  }
  else {
    $path = dirname(__FILE__);

    set_include_path(get_include_path() . PATH_SEPARATOR . $path);

    require_once 'Zend/Loader.php';
  }
  
  function zend_gdata_need_php5() {
    
    $version = phpversion();
    
    echo <<<END_OF_HTML
    <div class='error'>
    <p><strong>
    Zend Framework: Whoops, you have PHP $version installed. You need PHP 5.1.4 or higher.
    </strong></p>
    </div>
END_OF_HTML;
    
  }

?>
