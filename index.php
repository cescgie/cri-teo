<?php

require 'env.php';
require 'Storage.php';
error_reporting( error_reporting() & ~E_NOTICE );
date_default_timezone_set('Europe/Berlin');

$double_auftrag = '';
/**
  * Get a web file (HTML, XHTML, XML, image, etc.) from a URL.  Return an
  * array containing the HTTP server response header fields and content.
*/
function get_web_page( $url )
{
  $user_agent='Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0';

  $options = array(
      CURLOPT_CUSTOMREQUEST  =>"GET",        //set request type post or get
      CURLOPT_POST           =>false,        //set to GET
      CURLOPT_USERAGENT      => $user_agent, //set user agent
      CURLOPT_COOKIEFILE     =>"cookie.txt", //set cookie file
      CURLOPT_COOKIEJAR      =>"cookie.txt", //set cookie jar
      CURLOPT_RETURNTRANSFER => true,     // return web page
      CURLOPT_HEADER         => false,    // don't return headers
      CURLOPT_FOLLOWLOCATION => true,     // follow redirects
      CURLOPT_ENCODING       => "",       // handle all encodings
      CURLOPT_AUTOREFERER    => false     // set referer on redirect
  );

  $ch      = curl_init( $url );
  curl_setopt_array( $ch, $options );
  $content = curl_exec( $ch );
  $err     = curl_errno( $ch );
  $errmsg  = curl_error( $ch );
  $header  = curl_getinfo( $ch );
  curl_close( $ch );

  $header['errno']   = $err;
  $header['errmsg']  = $errmsg;
  $header['content'] = $content;
  return $header;
}

/**
* Get the VIEWSTATE the EVENTVALIDATION from the value
*/
function get_login_data(){
  //Read a web page and check for errors:
  $result = get_web_page('https://advertising.criteo.com/login.aspx');
  if ( $result['errno'] != 0 )
      die("error: bad url, timeout, redirect loop ". $result['errno']);

  if ( $result['http_code'] != 200 )
      die("error: no page, no permissions, no service". $result['http_code']);

  $page = $result['content'];

  preg_match('~<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="(.*?)" />~', $page, $viewstate);
  preg_match('~<input type="hidden" name="__EVENTVALIDATION" id="__EVENTVALIDATION" value="(.*?)" />~', $page, $eventValidation);

  $viewstate = $viewstate[1];
  $eventValidation = $eventValidation[1];

  $data['viewstate']=$viewstate;
  $data['eventValidation']=$eventValidation;
  return $data;
}

/*
* Access login page with collected data
*/
function access($url){
  $datas = get_login_data();
  $viewstate = $datas['viewstate'];
  $eventValidation = $datas['eventValidation'];

  $f = fopen('log.txt', 'w'); // file to write request header for debug purpose
  $useragent = 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/533.2 (KHTML, like Gecko) Chrome/5.0.342.3 Safari/533.2';
  $username = USERNAME;
  $password = PASSWORD;

  /**
   *Start Login process
   */
  $ch = curl_init($url);

  // curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
  curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_REFERER, $url);
  curl_setopt($ch, CURLOPT_VERBOSE, 1);
  curl_setopt($ch, CURLOPT_STDERR, $f);
  curl_setopt($ch, CURLOPT_USERAGENT, $useragent);

  // Collecting all POST fields
  $postfields = array();
  $postfields['__EVENTTARGET'] = "";
  $postfields['__EVENTARGUMENT'] = "";
  $postfields['__VIEWSTATE'] = $viewstate;
  $postfields['__EVENTVALIDATION'] = $eventValidation;
  $postfields['m1$globalContent$ctlLogin$UserName'] = $username;
  $postfields['m1$globalContent$ctlLogin$Password'] = $password;
  $postfields['m1$globalContent$ctlLogin$Login'] = 'Log in';

  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);

  $content = curl_exec( $ch );
  $err     = curl_errno( $ch );
  $errmsg  = curl_error( $ch );
  $header  = curl_getinfo( $ch );

  curl_close( $ch );

  $header['errno']   = $err;
  $header['errmsg']  = $errmsg;
  $header['content'] = $content;
  return $header;
}


/**
*login and access destination page
*/
function execute($datum,$url,$forDate){
  //connect to database
  $_db = new Storage();

  $htmlContent = access($url);

  $dom = new \DOMDocument('1.0', 'UTF-8');

  $internalErrors = libxml_use_internal_errors(true);

  //load the html
  $html = $dom->loadHTML($htmlContent['content']);

  //discard white space
  $dom->preserveWhiteSpace = false;

  //the table by its tag name
  $tables = $dom->getElementsByTagName('table');

  //get all rows from the table
  $rows = $tables->item(0)->getElementsByTagName('tr');

  //get each column by tag name
  $cols = $rows->item(0)->getElementsByTagName('th');
  $row_headers = NULL;
  foreach ($cols as $node) {
      $row_headers[] = trim($node->nodeValue);
  }

  $table = array();
  //get all rows from the table
  $rows = $tables->item(0)->getElementsByTagName('tr');
  foreach ($rows as $row)
  {
     // get each column by tag name
      $cols = $row->getElementsByTagName('td');
      $row = array();
      $i=0;
      foreach ($cols as $node) {
          $newstr = str_replace('.','',filter_var($node->nodeValue, FILTER_SANITIZE_STRING,FILTER_FLAG_STRIP_HIGH));
          if($row_headers==NULL)
              $row[] = trim($newstr);
          else
              $row[$row_headers[$i]] = trim($newstr);
          $i++;
      }
      $table[] = $row;
  }
  /**
  * Ignore $i = 0 & $i = 1
  * $i = 0 => NULL
  * $i = 1 => Total
  */
  for ($i=1; $i <=count($table) ; $i++) {
    unset($daten);
    if($i!=1 && isset($table[$i])) {
      $auftrag = $table[$i]['Name'];
      $impr = $table[$i]['Impr.'];
      $click = $table[$i]['Clicks'];
      $select = $_db->select("SELECT Auftragsnummer,Auftragsposition,id_extern FROM absolutebusy.gregtool_auftrag_position WHERE LENGTH(id_extern) > 3 AND LOCATE('".trim($auftrag)."',id_extern)>0 AND ende >= '".$datum."'");

      $double_imp_ad = '';
      $double_click_ad = '';
      if($select){
        echo "\n\nAuftrag : ".$auftrag."\n";
        //get value from imp_ad & click_ad if double Auftragsnummer & Auftragsposition
        if($double_auftrag['Auftragsnummer'] == $select[0]['Auftragsnummer'] && $double_auftrag['Auftragsposition'] == $select[0]['Auftragsposition']){
          $select_gregtool_erfuellung_1 = $_db->select("SELECT * FROM gregtool_erfuellung
  				WHERE Auftragsnummer='".$double_auftrag['Auftragsnummer']."'
  				AND Auftragsposition='".$double_auftrag['Auftragsposition']."'
  				AND DATE_ADD(datum, INTERVAL 0 DAY) = '".$forDate."'");

          $double_imp_ad = $select_gregtool_erfuellung_1[0]['imp_ad'];
          $double_click_ad = $select_gregtool_erfuellung_1[0]['click_ad'];
        }
        $daten[$select[0]['Auftragsnummer']."_".$select[0]['Auftragsposition']]["impressions"] += $impr;
        $daten[$select[0]['Auftragsnummer']."_".$select[0]['Auftragsposition']]["clicks"] += $click;
      }

      if(isset($daten))
			foreach($daten AS $key => $data)
			{
				$auftrag = explode("_",$key);

				$diffimp_ad = $data["impressions"];
				$diffclick_ad = $data["clicks"];

				$Auftragsnummer=$auftrag[0];
				$Auftragsposition=$auftrag[1];

        echo "Auftragsnummer : ". $Auftragsnummer."\n";
        echo "Auftragsposition : ". $Auftragsposition."\n";
        echo "diffimp_ad : ". $diffimp_ad."\n";
        echo "diffclick_ad : ". $diffclick_ad."\n";
        echo "forDate : ".$forDate."\n";

        $select_gregtool_erfuellung_2 = $_db->select("SELECT * FROM gregtool_erfuellung
				WHERE Auftragsnummer='".$Auftragsnummer."'
				AND Auftragsposition='".$Auftragsposition."'
				AND DATE_ADD(datum, INTERVAL +1 DAY) = '".$forDate."'");

        //check double Auftragsnummer and Auftragsposition and set corrected imp_ad, click_ad
        if($double_auftrag['Auftragsnummer'] == $Auftragsnummer && $double_auftrag['Auftragsposition'] ==$Auftragsposition){
          $update_data_erfullung['imp_ad'] = $diffimp_ad+$double_impr_ad;
          $update_data_erfullung['click_ad'] = $diffclick_ad+$double_click_ad;
        }else{
          $update_data_erfullung['imp_ad'] = $select_gregtool_erfuellung_2[0]['imp_ad']+$diffimp_ad;
          $update_data_erfullung['click_ad'] = $select_gregtool_erfuellung_2[0]['click_ad']+$diffclick_ad;
        }

        //update column imp_ad and click_ad in gregtool_erfuellung
        $where = "Auftragsnummer='".$Auftragsnummer."' AND Auftragsposition='".$Auftragsposition."' AND datum= '".$forDate."'";
        $update_gregtool_erfuellung = $_db->update("gregtool_erfuellung",$update_data_erfullung,$where);

        //update column erfdat in gregtool_auftrag_position
        $update_data_erfdat['erfdat'] = date("Y-m-d",strtotime($forDate)+86400);
        $where_erfdat = "Auftragsnummer='".$Auftragsnummer."' AND Auftragsposition='".$Auftragsposition."'";
        $update_gregtool_auftrag_position = $_db->update('gregtool_auftrag_position',$update_data_erfdat,$where_erfdat);
      }
      $double_auftrag = array('Auftragsnummer'  => $select[0]['Auftragsnummer'],'Auftragsposition'=>$select[0]['Auftragsposition']);
    }
  }

  libxml_use_internal_errors($internalErrors);
}

function init(){
  /**
  * 3 letzte Tage
  */
  $current_date = date('Y-m-d');
  $result_date = new DateTime($current_date);
  $result_date->modify('-3 day');
  $_min3_date = $result_date->format('Y-m-d');
  $tag['_min3_date'] = $_min3_date;

  $current_date = date('Y-m-d');
  $result_date = new DateTime($current_date);
  $result_date->modify('-2 day');
  $_min2_date = $result_date->format('Y-m-d');
  $tag['_min2_date'] = $_min2_date;

  $current_date = date('Y-m-d');
  $result_date = new DateTime($current_date);
  $result_date->modify('-1 day');
  $_min1_date = $result_date->format('Y-m-d');
  $tag['_min1_date'] = $_min1_date;
  echo "<pre>";
  echo "=========Netpoint Media DE===========\n";
  foreach ($tag as $key => $date) {
    $exec_date = new DateTime($date);
    $begindate = $exec_date->format('Y-m-d');
    $exec_date->modify('+1 day');
    $enddate = $exec_date->format('Y-m-d');

    $url_netpoint = 'https://advertising.criteo.com/login.aspx?ReturnUrl=%2fstats%2fdefault.aspx%3fbreakdown%3dAffiliate%26history%3dNA%26period%3dYesterday%26begindate%3d'.$begindate.'%26enddate%3d'.$enddate.'%26useIncompleteStats%3dFalse%26networkid%3d119%3faccountid%3d1040&breakdown=Affiliate&history=NA&period=Yesterday&begindate='.$begindate.'&enddate='.$enddate.'&useIncompleteStats=False&networkid=119&accountid=1040';
    echo "\n\n++++++++ Datum : ".$date." ++++++++";
    execute($current_date,$url_netpoint,$date);
  }

  echo "\n\n\n\n++++++++++++++++++++++++++++++++++++++++\n\n\n\n";
  echo "=========Netpoint Media DE RTA=========";
  foreach ($tag as $key => $date) {
    $exec_date = new DateTime($date);
    $begindate = $exec_date->format('Y-m-d');
    $exec_date->modify('+1 day');
    $enddate = $exec_date->format('Y-m-d');

    $url_netpoint_rta = 'https://advertising.criteo.com/login.aspx?ReturnUrl=%2fstats%2fdefault.aspx%3fbreakdown%3dZone%26history%3dNA%26period%3dYesterday%26begindate%3d'.$begindate.'%26enddate%3d'.$enddate.'%26useIncompleteStats%3dFalse%26networkid%3d1329%3faccountid%3d26055&breakdown=Zone&history=NA&period=Yesterday&begindate='.$begindate.'&enddate='.$enddate.'&useIncompleteStats=False&networkid=1329&accountid=26055';
    echo "\n\n++++++++ Datum : ".$date." ++++++++";
    execute($current_date,$url_netpoint_rta,$date);
  }
  echo '</pre>';
}

/**
* App starten
*/
init();

?>
