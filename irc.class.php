<?php
// class.irc.php

class IRC{
    // Common variables
    public $server;
    public $port;
    public $nick;
    public $name;
    public $channel;

    public $socket;
    public $buffer;
	public $last_ping;
	public $timeout;
    public $firsttime;

    // Class constructor
    public function __construct($p_server,$p_port,$p_nick,$p_name,$p_channel,$p_vhost,$p_timeout,$p_last){
        $this->server = $p_server;
        $this->port = $p_port;
        $this->nick = $p_nick;
        $this->name = $p_name;
        $this->channel = $p_channel;
		$this->vhost = $p_vhost;
		$this->timeout = $p_timeout;
        $this->firsttime = true;
        $this->userserver = null;
        $this->lastserver = $p_last;
        error_reporting(0);
    }
    // This function connects to server, bool
    //Edit 2009-10-11: Added functionality to determine server pool, and round robin system to connect them.
    public function connect(){
        if(isset($this->server, $this->port)){
        if(is_array($this->server)){
        	if(!isset($this->lastserver))
        		$this->lastserver = -1;
			$roundrobin = count($this->server)-1;//we don't need one extra
			$rand = rand(0,$roundrobin);
			print "randomizer: $rand \n";
			while(1){
				if($rand === $this->lastserver){
					$rand = rand(0,$roundrobin);
					print "randomizer: $rand \n";
				}else{
					$this->useserver = $this->server[$rand];
					$this->lastserver = $rand;
					break;
				}
			}
		}else{
			$this->useserver = $this->server;
		}
		print "Using server ".$this->useserver."\n";
		$ip = gethostbyname($this->vhost);
	    $opts = array('socket' => array('bindto' => "$ip:0"));
	    $context = stream_context_create($opts);
            if($this->socket = @stream_socket_client($this->useserver.":".$this->port,$errno,$errstr, 30, STREAM_CLIENT_CONNECT, $context)){
                fwrite($this->socket,"USER ".$this->nick." botit.toimii.org botit.toimii.org :".$this->name."\r\n");
                fwrite($this->socket,"NICK ".$this->nick." botit.toimii.org\r\n");
                set_time_limit(0);
                stream_set_blocking($this->socket,0);
                return true;
            }
            else{
		print "Error: ".$errstr."\n";
                $this->lastserver = $this->useserver;
				return false;
            }
        }
        else{
            return false;
        }
    }
    // This function disconnects from the server, void
    public function disconnect($message = "Quitting..."){
        fwrite($this->socket,"QUIT :$message\r\n");
        usleep(1500000);
        fclose($this->socket);
    }

    // Joins the channel and replies to PING/PONG events
    public function initialize($buffy){
        if(strstr($buffy,"/MOTD") && $this->firsttime == true){
            print "Now joining to ".$this->channel."...\n";
            fwrite($this->socket,"JOIN ".$this->channel."\r\n");
            $this->firsttime = false;
        }
        if(substr($buffy,0,6) == "PING :"){
            fwrite($this->socket,"PONG :".substr($buffy,6)."\r\n");
			$this->last_ping = microtime(TRUE);
            return false;
        }else{
            return true;
        }
    }


    // IRC Functions [BEGIN]

    // Joins channel
    public function join($channel){
        fwrite($this->socket,"JOIN ". $channel ."\r\n");
    }
    // Leaves the channel
    public function part($channel){
        fwrite($this->socket,"PART ". $channel ."\r\n");
    }
    // send message to channel/user
    public function say($to,$msg){
		if(!empty($msg) && $this->firsttime == false){
    		$msgs = explode(" ",$msg);
    		$msgs2 = array();
    		foreach($msgs as $key => $message){
                $msgs2[$key] = $this->utf8($message);
            }
            $msg = "";
            foreach($msgs2 as $message){
                $msg .= $message." ";
            }
            $msg = trim($msg);
	        fwrite($this->socket,$this->utf8("PRIVMSG $to :$msg\r\n"));
			$this->echo2console($this->nick,$to.": ".$msg,0);
			$this->last_ping = microtime(TRUE);
		}
    }
	public function notice($to,$msg){
		if(!empty($msg)){
	        fwrite($this->socket,$this->utf8("NOTICE $to :$msg\r\n"));
			$this->echo2console($this->nick,$to.": ".$msg,0);
	    	$this->last_ping = microtime(TRUE);
	    }
    }
    // modes: +o, -o, +v, -v, etc.
    public function setMode($user,$mode){
        fwrite($this->socket,"MODE ".$this->channel." $mode $user\r\n");
    }
    // kicks user from the channel
    public function kick($user,$from,$reason = ""){
        fwrite($this->socket,"KICK $from $user :$reason\r\n");
    }
    // changes the channel topic
    public function topic($channel,$topic){
        fwrite($this->socket,"TOPIC $channel :$topic\r\n");
    }
	 public function nick($nick){
        fwrite($this->socket,"NICK ".$nick." shibuya.mine.nu\r\n");
    }

    private function utf8_compliant($str) {
   	if ( strlen($str) == 0 ) {
       	return TRUE;
   	}
   	return (preg_match('/^.{1}/us',$str,$ar) == 1);
	}

	public function utf8($str){
		if($this->utf8_compliant($str) == 1){
			$return = $str;
		}else{
			$return = utf8_encode($str);
		}
		return $return;
	}

    // Read stream from the server
    public function read(){
   	    $this->buffer = trim(fgets($this->socket, 4096));
        return $this->buffer;
    }
    // get nick of msg sender
    public function get_nick(){
        return $this->utf8(substr($this->buffer,strpos($this->buffer,":")+1,strpos($this->buffer,"!")-1));
    }
    // get msg of msg sender
    public function get_msg(){
        if(strstr($this->buffer,$this->channel)){
            $msg = explode(":",$this->buffer);
            $message = "";
            for($i = 2;$i < count($msg);$i++){
                $message .= $msg[$i];
                if($i+1 == count($msg)) $message .= "";
                else $message .= ":";
            }
            $return = $this->utf8($message);
        }else{
            $return = $this->utf8($this->buffer);
        }
        return $return;
    }

	public function get_host(){
		$host = explode(" ",$this->buffer);
		$host = explode("!",$host[0]);
		return $host[1];
	}




    // Console functions [BEGIN]

    // Prints nick's msg to console
    public function echo2console($nick,$msg,$priva=0){
    	if(!strstr($nick," ")){
    	    if(trim($nick) != ""){
				if($priva == 0){
					if(strstr($msg,"ACTION")){
						echo(date("(H:i:s)")." * $nick ".substr($msg,8)."\n");
					}elseif(strstr($msg,"NICK")){
						$msgi = explode(":",$msg);
						echo(date("(H:i:s)")." $nick is now know as ".$msgi[2]."\n");
					}elseif(strstr($msg,"QUIT")){
						echo(date("(H:i:s)")." * $nick had enough (quit)\n");
					}elseif(strstr($msg,"PART")){
						echo(date("(H:i:s)")." * $nick had enough (part)\n");
					}

					else{
	            		echo(date("(H:i:s)")." <$nick> $msg\n");
	            	}
    	    	}elseif($priva == 1){
					echo(date("(H:i:s)")." (private)<$nick> $msg\n");
				}
			}
		}
    }
}
?>