<html>
<head>
<style type="text/css">
</style>
</head>
<body></body>

<?php
### certificator.php / richard@borwinius.de / 2024
### connect to a ms-caserver and download a certificates
#########################################################
// Formular mit Fehlerauswertung

$search1 = "<A Href=\"certnew.cer?ReqID=";
$errorFelder = array();
$error = null;
$felder = array("CNAME","CA_SRV","CA_TMPL","CA_USER","CA_PASSWORD","CA_DOMAIN");
$ClientIP = $_Server['REMOTE_ADDR'];
$Server = gethostname();
#########################################################
function deleteLineInFile($file,$string)
{
    $i=0;$array=array();

    $read = fopen($file, "r") or die("can't open the file");
    while(!feof($read)) {
        $array[$i] = fgets($read);
        ++$i;
    }
    fclose($read);

    $write = fopen($file, "w") or die("can't open the file");
    foreach($array as $a) {
        if(!strstr($a,$string)) fwrite($write,$a);
    }
    fclose($write);
}
##### ab hier startet die Ueberpruefung von den Formulareingabe #####

if(isset($_POST['clean'])) {
        array_map("unlink", glob( "*.config" ));
        array_map("unlink", glob( "*.key" ));
        array_map("unlink", glob( "*.csr" ));
        array_map("unlink", glob( "*.cer" ));
        array_map("unlink", glob( "*.pem" ));
        array_map("unlink", glob( "*.p7b" ));
    }

if(isset($_POST['Ueberpruefung'])) {
  $error = false;

  foreach($felder as $feld) {
    if(empty($_POST[$feld])) {
      $error = true;
      $errorFelder[$feld] = true;
    }
  }
}

##### Ausgabe ########################################################
if($error === false) {

  echo "Data:<hr>";
  $CNAME = $_POST['CNAME'];
  $SAN = $_POST['SAN'];
  $CA_SRV = $_POST['CA_SRV'];
  $CA_TMPL = $_POST['CA_TMPL'];
  $CA_USER = $_POST['CA_USER'];
  $CA_PASSWORD = $_POST['CA_PASSWORD'];
  $CA_DOMAIN = $_POST['CA_DOMAIN'];


$subject =    "/CN=$CNAME.$CA_DOMAIN".
                "/C=DE".
                "/ST=NRW".
                "/L=myTown".
                "/O=myCompany".
                "/OU=IT".
                "/emailAddress=myemail@my.domain".
                "/postalCode=123456".
                "/street=mystreet 1234";
    
echo "<br>";
##### $CA_SRV prüfen #####
$ch = curl_init("HTTPS://" . $CA_SRV);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    #curl_setopt($ch, CURLOPT_USERPWD, "$CA_USER:$CA_PASSWORD");
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
$ret = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ( !$ret ) {
    die("ERROR: https://$CA_SRV not available<br>");
    }
##### SAN fixen #####
if (!preg_match('/^[A-Za-z]/',$SAN)) {
    unset ($SAN);
     }
else {
        $SAN = preg_replace(
          array('/,/', '/;/', '/ /', '/&/', '/\|/'   ), /* Nach diesen suchen ...  */
          array(':', ':', ':', ':', ':'), /* ... durch diese ersetzen ... */
          $SAN );
        $SAN = rtrim($SAN, ':'); //Letztes falsches Zeichen löschen
        $a = explode(":",$SAN);
        $SAN = "";
        foreach ($a as $v) { $SAN .=  "DNS:$v,"; }
        $SAN = rtrim($SAN, ',');
        $COMMA = ',';
    }

if ($SAN) {
            echo "<table width='800' ><tr><td style=\"white-space:nowrap;\" width='250'>additional SAN:</td><td>$SAN</td></tr></table><br>";
          }
echo "Subjects:<hr>$subject";
echo "<br>Files:<hr>";
##### SAN gefixt #####
##### Configfile erstellen #####
$conf = "[ req ]\n";
$conf .= "distinguished_name = req_distinguished_name\n";
$conf .= "req_extensions = v3_req\n";
$conf .= "default_bits = 4096\n";
$conf .= "[ req_distinguished_name ]\n";
$conf .= "[ v3_req ]\n";
#basicConstraints = CA:FALSE
#keyUsage = nonRepudiation, digitalSignature, keyEncipherment
$conf .= "extendedKeyUsage = serverAuth, clientAuth\n";
$conf .= "subjectAltName = DNS:$CNAME,DNS:$CNAME.$CA_DOMAIN$COMMA$SAN\n";
if (file_put_contents("$CNAME.config",$conf)) {
        echo "<table width='350'><tr><td width='250'>$CNAME.config</td><td>saved</td></tr></table>"; }
else { die("ERROR: $CNAME.config could not be saved<br>"); }
##### Ende Configfile ######
##### Anfang create key and csr  ###########################
 $myexecute = shell_exec("openssl req -nodes -newkey rsa:4096 -keyout $CNAME.key -out $CNAME.csr ".
                        "-subj \"$subject\" ".
                        "-addext \"subjectAltName = DNS:$CNAME,DNS:$CNAME.$CA_DOMAIN$COMMA$SAN \" ".
                        "-addext \"extendedKeyUsage = clientAuth\" ");
 if (file_exists("$CNAME.key")) {
     echo "<table width='350'><tr><td width='250'>$CNAME.key</td><td>saved</tr></table>"; }
 else { die("ERROR: Failed to write $CNAME.key<br>");}

 $ret = file_get_contents("$CNAME.key");
 if (!str_contains("$ret","-----BEGIN PRIVATE KEY")) {
        die("ERROR: $CNAME.key not a valid key<br>");
    }
 if (file_exists("$CNAME.csr")) {
     echo "<table width='350'><tr><td width='250'>$CNAME.csr</td><td>saved</tr></table>"; }
 else { die("ERROR: Failed to write $CNAME.csr<br>");}

 $ret = file_get_contents("$CNAME.csr");
 if (!str_contains("$ret","-----BEGIN CERTIFICATE REQUEST")) {
        die("ERROR: $CNAME.csr not a valid csr<br>");
    }
##### end create key and csr ###############################
##### Anfang request #######################################
##### Ersetzung in csr von + durch %2B #####
$CERT = file_get_contents("$CNAME.csr");
$CERT = str_replace("+","%2B",$CERT);
##### Ersetzung in csr von Leerzeichen durch + #####
$CERT = str_replace(" ","+",$CERT);
$CERTATTRIB = "CertificateTemplate:$CA_TMPL%0D%0A";
$header = "-H \'Accept: text/html,application/xhtml+xml,application/xml\;q=0.9,*/*\;q=0.8\' ".
    "-H \'Accept-Encoding: gzip, deflate\' ".
    "-H \'Accept-Language: en-US,en\;q=0.5\' ".
    "-H \'Connection: keep-alive\' ".
    "-H \'Host: $Server\' ".
    "-H \'Referer: https://$c/certsrv/certrqxt.asp\' ".
    "-H \'User-Agent: Mozilla/5.0 \(Windows NT 6.3\; WOW64\; Trident/7.0\; rv:11.0\) like Gecko\' ".
    "-H \'Content-Type: application/x-www-form-urlencoded\' ";

##### post the csr and ask for a cert #####
$myexecute = shell_exec("curl -k -u $CA_USER:$CA_PASSWORD https://$CA_SRV/certsrv/certfnsh.asp " .$header.
    "--data \"Mode=newreq&CertRequest=".$CERT."&CertAttrib=".$CERTATTRIB."&TargetStoreFlags=0&SaveCert=yes&ThumbPrint=\" ".
    " \| grep -A 1 \'function handleGetCert\(\) \{\' \| tail -n 1 ");
if(str_contains($myexecute,"401")) {
        die(var_dump($myexecute));
    }
###### search for requestID ################
$p = (strpos($myexecute,$search1));
$b = strstr(substr($myexecute,$p,50),'&',true);
$t = strstr($b,"\"",false);
$outputlink = ltrim($t,"\"");
##### download the cer #####################
$myexecute = shell_exec("curl -k -u $CA_USER:$CA_PASSWORD https://$CA_SRV/certsrv/$outputlink\& ".$header." >$CNAME.cer");
if (file_exists("$CNAME.cer")) {
    echo "<table width='350'><tr><td width='250'>$CNAME.cer</td><td>saved</tr></table>"; }
else { die("ERROR: Failed to write $CNAME.cer<br>");}

$ret = file_get_contents("$CNAME.cer");
if (!str_contains("$ret","-----BEGIN CERTIFICATE")) {
        die("ERROR: $CNAME.cer not a cert<br>");
    }
##### convert cer to pem ####################
$myexecute = shell_exec("openssl x509 -in $CNAME.cer -out $CNAME.pem");
if (file_exists("$CNAME.pem")) {
    echo "<table width='350'><tr><td width='250'>$CNAME.pem</td><td>saved</tr></table>"; }
else {
        die("ERROR: Failed to write $CNAME.pem<br>");
     }
$cname_ret = file_get_contents("$CNAME.pem");
if (!str_contains("$cname_ret","-----BEGIN CERTIFICATE")) {
        die("ERROR: $CNAME.pem not a cert<br>");
    }
##### get ca-cert #############################
$myexecute = shell_exec("curl -k -u $CA_USER:$CA_PASSWORD https://$CA_SRV/certsrv/certnew.p7b?ReqID=CACert&Renewal=2&Enc=bin");
if (!str_contains("$myexecute","-----BEGIN CERTIFICATE")) {
        die("ERROR: $CA_SRV.p7b not a cert<br>");
    }
if (file_put_contents("$CA_SRV.p7b",$myexecute)) {
        echo "<table width='350'><tr><td width='250'>$CA_SRV.p7b</td><td>saved</td></tr></table>"; }
else {
        die("ERROR: $CA_SRV.p7b could not be saved<br>");
     }
##### convert p7b to pem ########################
$myexecute = shell_exec("openssl pkcs7 -print_certs  -in $CA_SRV.p7b -out $CA_SRV.pem");
if (file_exists("$CA_SRV.pem")) {
    echo "<table width='350'><tr><td width='250'>$CA_SRV.pem</td><td>saved</tr></table>"; }
else {
        die("ERROR: Failed to write $CA_CERT.pem<br>");
     }
deleteLineInFile("$CA_SRV.pem","subject=");
deleteLineInFile("$CA_SRV.pem","issuer=");

$ca_ret = file_get_contents("$CA_SRV.pem");
if (!str_contains($ca_ret,"-----BEGIN CERTIFICATE")) {
        die("ERROR: $CA_SRV.pem not a cert<br>");
    }
##### concat cname.pem with cachain.pem ##########
if (file_put_contents("$CNAME.chain.pem",$ca_ret . $cname_ret)) {
        echo "<table width='350'><tr><td width='250'>$CNAME.chain.pem</td><td>saved</td></tr></table>";
     }
else {
        die("ERROR: $CNAME_chain.pem could not be saved<br>");
     }
##### Ende curlscripte ############################
echo "<br><br><br><table><tr>";
echo '<td><form name="download" id="2" enctype="text/html"></td>
    <td><input type="button" value=" download '.$CNAME.'.config " onclick="location.href=\''.$CNAME.'.config\';"></td>
    <td><input type="button" value=" download '.$CNAME.'.key " onclick="location.href=\''.$CNAME.'.key\';"></td>
    <td><input type="button" value=" download '.$CNAME.'.csr " onclick="location.href=\''.$CNAME.'.csr\';"></td>
    <td><input type="button" value=" download '.$CNAME.'.cer " onclick="location.href=\''.$CNAME.'.cer\';"></td>
    <td><input type="button" value=" download '.$CNAME.'.pem " onclick="location.href=\''.$CNAME.'.pem\';"></td>
    <td><input type="button" value=" download '.$CNAME.'.chain.pem " onclick="location.href=\''.$CNAME.'.chain.pem\';"></td>
    <td><input type="button" value=" download '.$CA_SRV.'.p7b " onclick="location.href=\''.$CA_SRV.'.p7b\';"></td>
    <td><input type="button" value=" download '.$CA_SRV.'.pem " onclick="location.href=\''.$CA_SRV.'.pem\';"></td>
      </form></td></tr></table>';
echo "<br>  ";
echo '<form method="post">
         <input type="submit" name="clean" value=" clean directory and back " />
      </form>';
}
else {
  if($error === true)
   echo "<b>ERROR: All Fields are filled?</b>";
############### ENDE Ausgabe ######################
  ?>
<!--
############### Anfang Eingabemaske ###############
 -->
   <center>
 <form  method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'])?>">
 <h2><font color=red>CERTIFICATOR</font></h2>
 <br><br>
<h3>holt Domaincertificate from an Windows-CA<br></h3>
your Computer-IP: "<?php echo $_SERVER['REMOTE_ADDR']; ?>"<br><br>
<table>
<tr>
<td>Common Name:</td>
<td><input name="CNAME" type="text" size="55" value="mywebsrv"
    <?php if(isset($errorFelder['Client'])) echo 'class="error"'; ?>>
</td>
</tr>
<tr>
<td>Subject Alternative Names:</td>
<td><input name="SAN" type="text" size="55" value="myalias1 myalias1.my.dom.ain myalias2 myalias2.mysecond.dom.ain"
    <?php if(isset($errorFelder['SAN'])) echo 'class="error"'; ?>>
</td>
</tr>
<tr>
<td>CA-Server:</td>
<td>
  <input name="CA_SRV" type="text" size="55" value="myWinCASRV.my.dom.ain"
    <?php if(isset($errorFelder['CA_SRV'])) echo 'class="error"'; ?>>
</td>
</tr>
<tr>
 <td> CA-Template:</td>
<td>
  <input name="CA_TMPL" type="text" size="55" value="myTemplate_1year"
    <?php if(isset($errorFelder['CA_TMPL'])) echo 'class="error"'; ?>>
 </td>
</tr>
<tr>
  <td>CA-User:</td>
<td>
  <input name="CA_USER" type="text" size="55" value="mycauser@my.dom.ain"
    <?php if(isset($errorFelder['CA_USER'])) echo 'class="error"'; ?>>
</td>
</tr>
<tr>
  <td>CA-Password:</td>
<td>
  <input name="CA_PASSWORD" type="password" size="55" value=""
    <?php if(isset($errorFelder['CA_PASSWORD'])) echo 'class="error"'; ?>>
</td>
</tr>
<tr>
  <td>CA-Domain:</td>
<td>
  <input name="CA_DOMAIN" type="text" size="55" value="my.dom.ain"
    <?php if(isset($errorFelder['CA_DOMAIN'])) echo 'class="error"'; ?>>
</td>
</tr>
</table>
<br><br>
  <input type="hidden" name="Ueberpruefung" value="1">
  <input type="submit" name="CERTIFICATOR" value="send Request">
  </form>
   </center>
  <?php
 }
  ?>
</body>
</html>
