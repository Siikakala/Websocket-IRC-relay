<?php
/**
 * PHP IRCBot, classi ja pohja kopioitu jostain päin nettiä, (c) tekijälle
 *
 * @author (c) Miika Ojamo
 * @version 2009-10-11.2
 */
// ircbot.php
define("_DEBUG", 9); //Debug-taso. 1 = tärkeimmät, 9 = flooooood, 0 = no debug

include "irc.class.php"; //irc-classi
include "class.PHPWebSocket.php"; //Websocket-classi
include "db.php"; //yhdistellään tietokantaan
include "functions.php"; //ja alustetaan funktiot

while (1) { // botti pitää joko tappaa, tai käskeä erikseen poistumaan.
	// start the server
	$Server = new PHPWebSocket();
	$Server->bind('message', 'wsOnMessage');
	$Server->bind('open', 'wsOnOpen');
	$Server->bind('close', 'wsOnClose');
	// for other computers to connect, you will probably need to change this to your LAN IP or external IP,
	// alternatively use: gethostbyaddr(gethostbyname($_SERVER['SERVER_NAME']))
	if (strcmp(getHostName(), "hakku") === 0) { // tuotanto
		$Server->wsStartServer(__production, __port);
		$botnick = "tunkki";
		$kanava = "#mansecon";
	} else {
		$Server->wsStartServer(__development, __port);
		$botnick = "testitunkki";
		$kanava = "#mansecon.tekniikka";
	}
	define("_kanava", $kanava);
	$connect_timeout = 60;
	$break = false;
	if (!isset($last)) $last = -1;
	// serveri (useammat arrayna (joista yksi valitaan randomilla)), portti, botin nick, realname, kanava, vhosti, ping timeout sekunteja,viimeksi käytetty serveri (osataan yhdistää jollekin muulle jos useita).
	$irc = new IRC("tampere.fi.b2irc.net", 6667, $botnick, "Traconin chat-relay", $kanava, "", 301, $last);
	$last = $irc->lastserver; //pakko tehä hankalasti et toimii. Storetaan edellinen serveri
	// yhdistetään.
	if ($irc->connect()) {
		$last = $irc->lastserver; //pakko tehä hankalasti et toimii. Storetaan edellinen serveri
		echo("$botnick connected succesfully!\n");
		while (!feof($irc->socket)) { // pysytään silmukassa, kunnes yhteys katkee jostain syystä.
			if (isset($irc->last_ping)) { // *ping* *pong*
				if ($irc->last_ping - (microtime(true) - $irc->timeout) <= 0) { // jos edellisestä pingistä yli määritelty määrä sekunteja
					fclose($irc->socket); //tapetaan socket
					unset($irc->socket);
					print "Ping Timeout \n";
					break;
				}
			}
			$data = $irc->read(); // luetaan uusi rivi.
			$Server->readBuffer();
			if ($irc->initialize($data) == true) { // jollei ole PING, jatkokäsittely
				$err = 0; //nollataan error.
				$nick = $irc->get_nick(); //viestin lähettäjän nick
				$msg = $irc->get_msg(); //..ja viesti
				$host = $irc->get_host(); //..ja ident@host
				$priva = 0; //oletuksena ei ole privaviesti
				$prefix = "!"; //merkki, joka on kaikkien komentojen alussa.
				$kanava = _kanava; //kanava
				$ignore = 0; //oletuksena on komento (alkaa huutomerkillä, paitsi jos priva)
				$priva = check_privmsg($msg, $botnick); //kahtotaan onks se privaviesti
				if ($priva === false) { // ja jos se ei oo privaviesti
					$test = strncmp($prefix, $msg, 1); //katotaan alkaako se huutomerkillä
					if ($test != 0) $ignore = 1; //ja jollei, ignorataan se komento.
				} elseif ($priva) { // Jos on priva
					$kanava = $nick; //vastataan tyypille
					$prefix = ""; //ja komennot ilman huutomerkkiä
					$msg = $priva;
					$priva = 1;
				}
				// print $irc->buffer."\n";
				$irc->echo2console($nick, $msg, $priva); //heitetään myös konsoliin
				$commands = array(); //array("känny","lisää","mobile","join","boing"); //mitä komentoja hyväksytään edes
				// paranneltu urlinmetsästys, regexp kaapattu putty traystä
				// if(preg_match("/(((https?|ftp):\/\/)|www\.)(([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)|localhost|([a-zA-Z0-9\-]+\.)*[a-zA-Z0-9\-]+\.(com|net|org|info|biz|gov|name|edu|[a-zA-Z][a-zA-Z]))(:[0-9]+)?((\/|\?)[^ \"]*[^ ,;\.:\">)])?/",$msg,$url)){$u = url($url,$nick);if($u){$irc->say($kanava, $u);}} //huudetaan wanhaa
				if (strstr($msg, "KICK") && strstr($msg, $botnick)) {
					sleep(5);
					$irc->join($kanava);
				} //rejoin-on-kick, mutta odotetaan 5sec välissä.
				if (strstr($msg, "JOIN") && strstr($msg, "yuri.fi"))$irc->setMode($nick, "+o"); //auto-opataan Siika jos se ei oo opattu mut ite on
				if (strstr($msg, "ACTION")) {
					huuda(" * $nick " . substr($msg, 8));
				} elseif (strstr($msg, "NICK")) {
					$msgi = explode(":", $msg);
					huuda("<$nick is now know as " . $msgi[2] . ">");
				} elseif (strstr($msg, "QUIT")) {
					huuda(" * $nick had enough (quit)");
				} elseif (strstr($msg, "PART")) {
					huuda(" * $nick had enough (part)");
				} else {
					huuda("<$nick> $msg");
				}

				$command = explode(" ", $msg); //erotetaan komento viestistä
				if (strlen($command[0]) < 2)$ignore = 1; //ignorataan yksittäiset huutomerkit
				if ($priva == 0)$command = substr($command[0], 1);
				else $command = substr($command[0], 0); //ja tiputetaan loput viestistä (priva huomioitu)
				if (!$ignore) {
					$c = array_search($command, $commands, true); //katsotaan löytyykö komentoo hyväksytyistä komennoista.
					if ($c !== false) {
						print "Command " . $commands[$c] . " spawned\n";
					} else {
						print"False command, ignoring.. \n";
						$c = null;
					} //Kerrotaan mikä komento löytyi, ja jollei komentoa löydy, tulostetaan False command
					switch ($commands[$c]) { // hyväksyttävien komentojen kutsut.
						case "mobile":
						case "känny":
							$data = mobilenro(trim(substr($msg, strlen($prefix . $command))));
							$irc->say($kanava, $data);
							sendMessage("<botti> $data");
							unset($data);
							break;

						case "add":
						case "lisää":
							$lisaa = add(trim(substr($msg, strlen($prefix . $command))), trim($nick));
							$irc->say($kanava, $lisaa);
							sendMessage("<botti> $lisaa");
							break;

						case "join":
							// if(check_privs($host, "a", $kanava,0)){
							sleep(2);
							print"Privileges ok, joining...\n";
							$irc->join(trim(substr($msg, strlen($prefix . $command))));
							// }
							// print"No privileges, not joining.\n";
							break;
						case "boing":
							if (check_privs($host, "a", $kanava, 0)) {
								$irc->disconnect("Jumping to next server");
								$connect_timeout = 5; //lyhyempi timeout, nollautuu loopin alussa.
								print"Disconnected. Jumping to next server. 5 sec timeout before reconnect.\n";
								$break = true;
							}
							break;

						default:
							// false command.
							break;
					}
				}
			}
			if ($break) break;
			usleep(50000); //not too fast! (50ms)
		}
	}
	print"Got disconnected, waiting for reconnect.\n\n";
	sleep($connect_timeout);
	print"reconnecting...\n";
}

?>