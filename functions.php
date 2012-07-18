<?php
/**
 * Osa PHP IRCBottia.
 * (c) Miika Ojamo
 */

/**
 * Käsittelee kaikki queryt, ja reconnectaa tietokantaan tarvittaessa.
 *
 * @param SQL $ -lause
 * @return tulos -array tai true
 */
function mysql_do_query($sql)
{
	// $sql = mysql_real_escape_string($sql);
	$return = -1;
	$return = @mysql_query($sql);
	if (mysql_errno() != 0) print "ERROR! Mysql: " . mysql_errno() . " " . mysql_error() . "\nSQL: " . $sql . "\n";
	if (mysql_errno() == 2006 || mysql_errno() == 1045) { // SE TOIMII! 2006 = mysql has gone away; 1045 = access denied (koska user väärin)
		print"\nTrying to reconnect database...\n";
		@mysql_close();
		sleep(2);
		$con = @mysql_pconnect (__db_host, __db_user, __db_pass) or print "\nI cannot connect to the database because: " . mysql_error() . "\n";
		@mysql_select_db (__db) or $panic = 1; //PANIIIC!!

		$return = @mysql_query($sql);
		if (mysql_errno() == 0) {
			$panic = 0;
			print"\nReconnected successfully! \o/\n";
		} else {
			print"\nReconnect failed :|\n";
			// $return = "Tietokanta petti meidät! :|";
		}
	}
	return $return;
}

/**
 * Haetaan tietokannasta puhelinnumeroita
 *
 * @param Haluttu $ henkilö
 * @param true $ =tekstimuotoinen palautus, ja löytyessä myös numero; false=palautetaan true/false, riippuen löytyykö numeroa vai ei, true = löytyy
 * @return puhelinnro
 */
function mobilenro($kuka, $textreturn = true)
{
	$kuka = explode(" ", trim($kuka));
	$kuka = trim($kuka[0]);

	$sql = "SELECT nro,hlo FROM puhelimet WHERE hlo LIKE '%$kuka%' LIMIT 1";
	$result = mysql_do_query($sql);
	$nro = @mysql_fetch_row($result);
	if (mysql_num_rows($result) === 0) {
		if ($textreturn) {
			$return = "Numeroa ei löytynyt..";
		} else {
			$return = false;
		}
	} else {
		if ($textreturn) {
			$return = "" . $nro[1] . ":n numero on " . $nro[0];
		} else {
			$return = true;
		}
	}
	return $return;
}

/**
 * Funktio, jolla lisätään deadlinejä, sekä oma numero kantaan.
 *
 * @param Komento $ -osa, eri osat erotettu välilyönnein
 * @param Komennon $ sanoneen henkilön nick
 * @return feedback -viesti
 */
function add($cmd, $nick)
{
	$return = false; //oletus
	$sql = null;
	unset($cmds);
	$cmds = explode(" ", $cmd); //erotellaan
	$allowed = array("deadline", "känny", "mobile", "pysäkki"); //vastaavaan tapaan kuin main-loopissa.
	$c = array_search($cmds[0], $allowed, true);
	if ($c !== false) {
		switch ($allowed[$c]) {
			case "mobile": // WTF-8:ia puhuvia varten
			case "känny":
				print_r($cmds);
				if (!empty($cmds[2])) {
					$lisaysesto = true;
					print"lisäysesto on \n";
				} else {
					$lisaysesto = false; //jos halutaan muuttaa, asetetaan lisaysesto todeksi, jolloin insertointi kantaan on mahdotonta
					print"lisäysesto off \n";
				}
				if (mobilenro($nick, false) && isset($cmds[2]) == false) { // tarkistetaan toisella funktiolla, onko numero jo kannassa, ja halutaanko sitä muuttaa
					$return = "Numerosi on jo tietokannassa! Jos haluat muuttaa sitä, lisää komennon perään sana \"muutos\"";
					$lisaysesto = true;
				} else {
					print "numero numeerinen? " . is_numeric($cmds[1]) . " montako merkkiä pitkä: " . strlen($cmds[1]) . "\n";
					if (is_numeric($cmds[1]) && strlen($cmds[1]) == 10) { // jos toinen attribuutti ei ole numero ja mikäli siinä on välejä (se ei ole kymmenen merkkiä pitkä), huudetaan
						if (strstr($cmds[2], "muutos")) {
							$sql = "UPDATE puhelimet SET nro = '" . $cmds[1] . "' WHERE hlo = '$nick'";
							$muutos = true;
							print"Muutetaan numeroa \n";
						} elseif (!$lisaysesto) {
							$sql = "INSERT INTO puhelimet (hlo,nro) VALUES ('$nick','" . $cmds[1] . "')";
							print"Lisätään numero \n";
						} else {
							if (is_numeric($cmds[2]))$return = "Syötä numero ilman välilyöntejä";
							else$return = "Viimeinen sana ei ole \"muutos\".";
							print"väärä vika sana \n";
						}
						if ($sql != null) {
							$result = mysql_do_query($sql);
							if (mysql_errno() != 0) {
								print mysql_error() . "\n";
								if ($muutos)$return = "Numeron muokkaus failasi.";
								else$return = "Numeron lisäys failasi.";
							} else {
								if ($muutos)$return = "$nick:n numeron muokkaus onnistui! (" . $cmds[1] . ")";
								else$return = "$nick:n numero (" . $cmds[1] . ") lisättiin kantaan.";
							}
						}
					} else {
						$return = "Syötä numero ilman välilyöntejä.";
					}
				}
				break;
			case "deadline":
				array_shift($cmds);
				$osia = count($cmds);
				$put = array();
				print "1=aika, 2=päivä, 3=kuvausta \n";
				foreach($cmds as $key => $value) {
					if (strstr($value, ":"))$property = 1; //1=aika
					elseif (strstr($value, "."))$property = 2; //2=päivä
					else $property = 3; //3=kuvausta
					print $value . " joka on " . $property . "\n";
					switch ($property) {
						case 1:
							$failsafe = explode(":", $value);
							if (!is_numeric($failsafe[0])) {
								$put["msg"] .= $value . " "; //fail-safe, mikäli viestissä on kaksoispiste.
								print"$value ei olekkaan 1 vaan 3, sanassa kaksoispiste.\n";
							} else {
								$put["time"] = $value;
							}
							break;
						case 2:
							$paivat = explode(".", $value); //..ja muutetaan päivä tietokannan muotoon.
							if (!is_numeric($paivat[0])) {
								$put["msg"] .= $value . " "; //fail-safe, mikäli viestissä on piste.
								print"$value ei olekkaan 2 vaan 3, sanassa piste.\n";
							} else {
								if (empty($paivat[2])) $paivat[2] = date("Y"); //mikäli vuotta ei ole määritelty, defaultataan nykyinne
								if (strlen($paivat[1]) == 1)$paivat[1] = "0" . $paivat[1]; //jos päivä on yksinumeroinen, lisätään etunolla
								if (strlen($paivat[0]) == 1)$paivat[0] = "0" . $paivat[0]; //sama kuukaudelle
								$paiva = $paivat[2] . "-" . $paivat[1] . "-" . $paivat[0]; //muunnetaan päivä muotoon YYYY-DD-MM
								print "parsitty päivä: " . $paiva . "\n";
								$put["day"] = $paiva;
							}
							break;
						case 3:
							$put["msg"] .= $value . " "; //nappaillaan välistä viestin osaset, ja koska käsitellään
							break;
						default:
							break;
					}
				}
				$addlock = false; // jos kaikki on kunnossa, lisäyslukko on false. muussa tapauksessa asetettaan lisäyslukko.
				if (empty($put["time"]) || empty($put["day"]) || empty($put["msg"])) {
					$return = "Tietoja puuttuu (päivä, aika tai viesti)";
					$addlock = true;
				} //jos tietoja puuttuu, addlock päälle
				$timestamp = $put["day"] . " " . $put["time"] . ":00";
				$msg = trim($put["msg"]);
				print $timestamp . " " . $msg . "\n";
				if (!$addlock) {
					$sql = "INSERT INTO deadlinet (stamp, msg, adder, addingtime) VALUES ('$timestamp','$msg','$nick',NOW())";
					$result = mysql_do_query($sql);
					if (mysql_errno() != 0) {
						print mysql_error() . "\n";
						$return = "Jotain meni nyt pieleen, databaseen lisäys failasi";
					} else {
						$return = "Deadline lisätty.";
					}
				}
				break;

			case "pysäkki":
				print_r($cmds);
				if (!empty($cmds[3])) {
					$lisaysesto = true;
					print"lisäysesto on \n";
				} else {
					$lisaysesto = false; //jos halutaan muuttaa, asetetaan lisaysesto todeksi, jolloin insertointi kantaan on mahdotonta
					print"lisäysesto off \n";
				}
				$sql = "SELECT pysakki
                			  ,pysakki_name
                        FROM   pysakit
                        WHERE  nick = '$nick'
                           AND pysakki = '";
				if (is_numeric($cmds[1])) $sql .= $cmds[1] . "'";
				elseif (is_numeric($cmds[2])) $sql .= $cmds[2] . "'";

				$result = mysql_do_query($sql);

				if (mysql_num_rows > 0) $olemassa = true;
				else $olemassa = false;

				if ($olemassa && isset($cmds[3]) == false) { // tarkistetaan toisella funktiolla, onko numero jo kannassa, ja halutaanko sitä muuttaa
					$return = "Tuon niminen tai numeroinen oma pysäkki on jo tietokannassa! Jos haluat muuttaa sitä, lisää komennon perään sana \"muutos\"";
					$lisaysesto = true;
				} else {
					if (strstr($cmds[3], "muutos")) {
						if (is_numeric($cmds[1])) $sql = "UPDATE pysakit SET pysakki = '" . $cmds[1] . "', pysakki_name = '" . $cmds[2] . "' WHERE nick = '$nick'";
						elseif (is_numeric($cmds[2])) $sql = "UPDATE pysakit SET pysakki = '" . $cmds[2] . "', pysakki_name = '" . $cmds[1] . "' WHERE nick = '$nick'";
						$muutos = true;
						print"Muutetaan pysäkkiä \n";
					} elseif (!$lisaysesto) {
						if (is_numeric($cmds[1])) $sql = "INSERT INTO pysakit (nick,pysakki,pysakki_name) VALUES ('$nick','" . $cmds[1] . "','" . $cmds[2] . "')";
						elseif (is_numeric($cmds[2])) $sql = "INSERT INTO pysakit (nick,pysakki,pysakki_name) VALUES ('$nick','" . $cmds[2] . "','" . $cmds[1] . "')";
						print"Lisätään pysäkki \n";
					} else {
						$return = "Viimeinen sana ei ole \"muutos\".";
						print"väärä vika sana \n";
					}
					if ($sql != null) {
						$result = mysql_do_query($sql);
						if (mysql_errno() != 0) {
							print mysql_error() . "\n";
							if ($muutos)$return = "Pysäkin muokkaus failasi.";
							else$return = "Pysäkin lisäys failasi.";
						} else {
							if ($muutos)$return = "$nick:n pysäkin muokkaus onnistui! (" . $cmds[1] . " == " . $cmds[2] . ")";
							else$return = "$nick:n pysäkki (" . $cmds[1] . " == " . $cmds[2] . ") lisättiin kantaan.";
						}
					} else
						print "apua lol, hallitsematon else!\n";
				}
				break;

			default:
				break;
		}
	}
	return $return;
}

function check_privmsg($msg, $botnick)
{
	if (strstr($msg, "PRIVMSG $botnick")) { // tarkistetaan, onko privaviesti
		$msg = str_replace("PRIVMSG $botnick :", "", substr($msg, strrpos($msg, "PRIVMSG $botnick :")));
		return $msg;
	} else {
		return false;
	}
}

function check_nick($host, $channel) // Haetaan nick hostin perusteella tietokannasta. "BUG":jos käyttäjää ei ole botilla, ei löydy
{
	$sql = "SELECT * FROM oer_users, oer_hostmasks WHERE oer_users.handle = oer_hostmasks.handle AND oer_users.channel = '$channel' AND oer_hostmasks.type = '2'";
	$result = mysql_do_query($sql);
	if (mysql_errno() != 0) print mysql_error();
	if (mysql_num_rows($result) == 0) return false;
	while ($row = mysql_fetch_assoc($result)) {
		if (fnmatch($row['hostmask'], $host)) {
			$handle = $row['handle'];
		}
	}
	return $handle;
}
// when a client sends data to the server
function wsOnMessage($clientID, $message, $messageLength, $binary)
{
	global $Server;
	global $irc;
	$ip = long2ip($Server->wsClients[$clientID][6]);
	// check if message length is 0
	if ($messageLength == 0) {
		$Server->wsClose($clientID);
		return;
	}

	foreach ($Server->wsClients as $id => $client) {
		if ($id != $clientID)
			$Server->wsSend($id, date("H:i:s", time()) . " " . $message . "<br/>");
	}
	$irc->say(_kanava, html_entity_decode($message));
}

function huuda($message)
{
	global $Server;
	global $irc;
	$clientID = 0;
	$messageLength = strlen($message);
	$binary = false;
	if (strlen($message) > 3 && $irc->firsttime == false)
		foreach ($Server->wsClients as $id => $client)
		$Server->wsSend($id, date("H:i:s", time()) . " " . htmlentities($message) . "<br/>");
}
// when a client connects
function wsOnOpen($clientID)
{
	global $Server;
	$ip = long2ip($Server->wsClients[$clientID][6]);
	$Server->log("$ip ($clientID) has connected.");
}
// when a client closes or lost connection
function wsOnClose($clientID, $status)
{
	global $Server;
	$ip = long2ip($Server->wsClients[$clientID][6]);
	$Server->log("$ip ($clientID) has disconnected.");
}

/**
 * UTF-8-muuntaja on-demand
 *
 * @param string $str Muunnettava teksti
 * @return string Teksti varmasti UTF-8:na
 */
function utf8($str)
{
	if (Controller_Admin::utf8_compliant($str) == 1) {
		$return = $str;
	} else {
		$return = utf8_encode($str);
	}
	return $return;
}

/**
 * utf8:n kaveri. Tunnistaa, onko teksti utf-8:ia vai jotain muuta
 *
 * @param string $str Tunnistettava teksti
 * @return True /null, true, jos utf-8, kuolee hiljaa jollei.
 */
function utf8_compliant($str)
{
	if (strlen($str) == 0) {
		return true;
	}
	return (preg_match('/^.{1}/us', $str, $ar) == 1);
}

?>