#!/home/y/bin/php

<?php

/* Constants */
define('ACK_TIMEOUT', 60); // time the tech/op is given to respond to a request
define('OP_WARNING_LIMIT', 3); // how many times an op is allowed to be warning about a requestor
                               // before ops mode is removed from him/her


/* Variables that determine server, channel, etc */
$CONFIG = array();
$CONFIG['server']       = 'irc.corp.yahoo.com'; // server (i.e. irc.gamesnet.net)
$CONFIG['nick']         = 'nocbot'; // nick (i.e. demonbot
$CONFIG['port']         = 6667; // port (standard: 6667)
$CONFIG['channel']      = '#oc-request'; // channel  (i.e. #php)
$CONFIG['name']         = 'nocbot'; // bot name (i.e. demonbot)
$CONFIG['admin_pass']   = 'asus';
$CONFIG['password']     = 'c0wboy!';

/* Let it run forever (no timeouts) */
set_time_limit(0);

/* The connection */
$con = array();

/* ops variables*/
$ops                = array(); // list of ops
$ops_index          = 0;
$warnings_per_req   = array();
$warnings_per_op    = array();

/* start the bot... */
init();

function init()
{
    global $con, $CONFIG, $ops, $warnings_per_req;
    /* We need this to see if we need to JOIN (the channel) during
    the first iteration of the main loop */
    $firstTime = true;

    /* other variables */
    $old_buffer = '';

    /* Connect to the irc server */
    $con['socket'] = fsockopen($CONFIG['server'], $CONFIG['port']);

    /* Check that we have connected */
    if (!$con['socket']) {
        print ("Could not connect to: ". $CONFIG['server'] ." on port ". $CONFIG['port']);
    } else {
        /* Send the username and nick */
        cmd_send("USER ". $CONFIG['nick'] ." Operations Center :". $CONFIG['name']);
        cmd_send("NICK ". $CONFIG['nick'] ." Y!OC");
        cmd_send('PRIVMSG NickServ :IDENTIFY ' . $CONFIG['password']);
        cmd_send("JOIN ". $CONFIG['channel']);

        /* Here is the loop. Read the incoming data (from the socket connection) */
        while (!feof($con['socket']))
        {
            /* Think of $con['buffer']['all'] as a line of chat messages.
            We are getting a 'line' and getting rid of whitespace around it. */
            $con['buffer']['all'] = trim(fgets($con['socket'], 4096));

            /* Pring the line/buffer to the console
            I used <- to identify incoming data, -> for outgoing. This is so that
            you can identify messages that appear in the console. */
            print date("[m/d @ H:i:s]")."<- ".$con['buffer']['all'] ."\n";

            // if there are requestors waiting
            // remove the prints and else for the release
            if (!empty($warnings_per_req))
            {
                print (date("[m/d @ H:i:s]") ."<-> ops_warning is NOT empty\n\r");
                process_requests();
            }
            else
            {
                print (date("[m/d @ H:i:s]") ."<-> ops_warning IS empty\n\r");
            }



            /* If the server is PINGing, then PONG. This is to tell the server that
            we are still here, and have not lost the connection */
            if(substr($con['buffer']['all'], 0, 6) == 'PING :') {
                /* PONG : is followed by the line that the server
                sent us when PINGing */
                cmd_send('PONG :'.substr($con['buffer']['all'], 6));
                /* If this is the first time we have reached this point,
                then JOIN the channel */
/*
                if ($firstTime == true){
                        cmd_send("JOIN ". $CONFIG['channel']);
                        // The next time we get here, it will NOT be the firstTime
                        $firstTime = false;
                }
*/
                /* Make sure that we have a NEW line of chats to analyse. If we don't,
                there is no need to parse the data again */
            } elseif ($old_buffer != $con['buffer']['all']) {
                /* Determine the patterns to be passed
                to parse_buffer(). buffer is in the form:
                :username!~identd@hostname JOIN :#php
                :username!~identd@hostname PRIVMSG #PHP :action text
                :username!~identd@hostname command channel :text */

                //preg_replace_callback()

                // log the buffer to "log.txt" (file must have
                // already been created).
                // log_to_file($con['buffer']['all']);

                // make sense of the buffer
                parse_buffer();

                // now process any commands issued to the bot
                //process_commands();

				// if there's a mode change, a quit, a part, or a nick change find users that have ops
				if (($con['buffer']['command'] == 'MODE' ||
				     $con['buffer']['command'] == 'QUIT' ||
				     $con['buffer']['command'] == 'PART' ||
				     $con['buffer']['command'] == 'NICK') && $con['buffer']['username'] != 'nocbot')
				{
                    process_state_change();
					//print (date("[m/d @ H:i:s]") ."<-> Detected a change: ". $con['buffer']['command']. "\n\r");
					//cmd_send('NAMES ' . $CONFIG['channel']);
				}

				// if there's a response to a NAMES command update the ops list
				elseif ($con['buffer']['command'] == '353')
				{
					find_ops();
					print (date("[m/d @ H:i:s]") ."<-> Updated Ops list:\n\r");
					var_dump($ops);
				}

				// if someone joins the channel
				elseif ($con['buffer']['command'] == 'JOIN' && $con['buffer']['username'] != 'nocbot') inform_tech($con['buffer']['username']);

				// if someone sends the !ACK command
				elseif (substr(strtoupper($con['buffer']['text']), 0, 4) == '!ACK') process_tech_ack();

                // if someone sends the !OPME or !DEOPME command directly to nocbot
                elseif ((strtoupper($con['buffer']['text']) == '!OPME' ||
                        strtoupper($con['buffer']['text']) == '!DEOPME') &&
                        $con['buffer']['channel'] == 'nocbot') process_tech_op();

            } // end elseif
            $old_buffer = $con['buffer']['all'];
        } // end while
    } // end else
}

/*
 * This function removes a user that just joined from the requests list
 * if the user gets chanops so the bot won't warn other ops.
 * It also updates the ops list/array
 *
 * $con['buffer']['text'] is in the format:
 * command_issuer_uid sets mode: <mode> <usernames separated by space>
 */
function process_state_change()
{
    global $CONFIG, $con, $warnings_per_req;

    print (date("[m/d @ H:i:s]") ."<-> Detected a change: ". $con['buffer']['command']. "\n\r");

    if ($con['buffer']['command'] == 'MODE')
    {
        list(,,,, $uids) = explode(' ', $con['buffer']['text'], 5);
        $uids = explode(' ', $uids);

        // check if the new op or de-op is in the new requestor list and remove them.
        foreach($uids as $uid)
            if(array_key_exists($uid, $warnings_per_req))
                unset($GLOBALS['warnings_per_req'][$uid]);
    }
    cmd_send('NAMES ' . $CONFIG['channel']);
}

function process_requests()
{
	global $warnings_per_req;

	// for each requestor
	foreach ($warnings_per_req as $requestor => $warnings_per_requestor)
	{
		// look at the last warning and measure its time
		// if the time is great that 60 seconds warn the next op in line
		//$last_warning_index = count($warnings_per_requestor) - 1;
        $curr_time = time();
        $last_warning_key = end(array_keys($warnings_per_requestor)); // the key here is the op_uid
        $last_warning_time = $last_warning_key;
        //$last_warning_time = $warnings_per_requestor[$last_warning_key];
        $diff_time = $curr_time - $last_warning_time;
        print (date("[m/d @ H:i:s]") . "<-> $requestor: $last_warning_time, Current Time: $curr_time, Diff: $diff_time\n\r");
		if ((time() - $last_warning_time) >= ACK_TIMEOUT)
		{
			print (date("[m/d @ H:i:s]") ."<-> Informing next OP of requestor: $requestor\n\r");
			inform_tech($requestor);
		}
	}
}

function process_tech_op()
{
    /*
     * command format is:
     * !OPME or !DEOPME
     */

     global $con, $CONFIG;
     $op_uid = $con['buffer']['username'];
     $command = strtoupper($con['buffer']['text']);

     switch ($command)
     {
         case '!OPME':
             cmd_send('MODE ' . $CONFIG['channel'] . " +o $op_uid");
             break;
         case '!DEOPME':
             cmd_send('MODE ' . $CONFIG['channel'] . " -o $op_uid");
             break;
     }
}

function process_tech_ack()
{
	/*
	 * command format is:
	 * !ACK req_uid
	 */

	global $con, $CONFIG, $ops, $warnings_per_req;
	$op_uid = $con['buffer']['username'];

    // was the !ack command issued by an op? if so, process it, else display a warning
    if (in_array($op_uid, $ops))
    {
        $command = explode(" ", $con['buffer']['text']);
    	$req_uid = $command[1];

        var_dump($GLOBALS['warnings_per_req']);

        // check if the req_uid exists -- the following is no longer valid: and this op has been warned about this req
        if (array_key_exists($req_uid, $warnings_per_req)) // && array_key_exists($op_uid, $warnings_per_req[$req_uid]))
        {
            cmd_send('PRIVMSG ' . $op_uid . " :You have responded to a valid request for uid: $req_uid");
            unset($GLOBALS['warnings_per_req'][$req_uid]);
        }
        else
        {
            cmd_send('PRIVMSG ' . $op_uid . " :You have responded to an INVALID request for uid: $req_uid");
        }

        var_dump($GLOBALS['warnings_per_req']);
    }
    else
    {
        cmd_send('PRIVMSG ' . $op_uid . " :You are not an operator in channel $CONFIG[channel] and, therefore, cannot issue the !ack command!");
    }
}

function inform_tech($req_uid)
{
	global $ops, $ops_index, $warnings_per_req, $warnings_per_op, $CONFIG;
	if ($ops_index >= count($ops)) $ops_index = 0;
	//$req_uid = $con['buffer']['username'];
	$op_uid = $ops[$ops_index];
	//cmd_send('PRIVMSG '. $CONFIG['channel'] .' :' . $op_uid . ': someone just joined the channel');
	cmd_send('PRIVMSG ' . $op_uid . " :$req_uid has joined the channel and is waiting to be helped!!");
    cmd_send('PRIVMSG ' . $CONFIG['channel'] . " :$req_uid: Thanks for joining the $CONFIG[channel] channel. " .
             "The OC techs have been notified and will assist you shortly. Thank you for your patience.");
    $curr_time = time();
    $warnings_per_req[$req_uid][$curr_time] = $op_uid;
	//$warnings_per_req[$req_uid][$op_uid] = time();

    // increase the count for warnings issued to this op about this req
    if (isSet($warnings_per_op[$op_uid][$req_uid]))
    {
        $warnings_per_op[$op_uid][$req_uid]++;
    }
    else
    {
        $warnings_per_op[$op_uid][$req_uid] = 1;
    }

    // if this op has been warned 3 times about this requestor remove ops mode from this op
    // and remove this op_uid from the warnings_per_op list
    if ($warnings_per_op[$op_uid][$req_uid] == OP_WARNING_LIMIT)
    {
        cmd_send('MODE ' . $CONFIG['channel'] . " -o $op_uid");
        unset($GLOBALS['warnings_per_op'][$op_uid]);
        cmd_send('PRIVMSG ' . $op_uid . " :I have removed ops from you since you haven't responded to " .
                                        OP_WARNING_LIMIT . " of my warnings about requestor $req_uid.");
    }


    echo $warnings_per_req[$req_uid][$curr_time];
    //echo $warnings_per_req[$req_uid][$op_uid];
	$ops_index++;
}

function find_ops()
{
	global $con, $CONFIG, $ops;
	$new_ops = array();

	//print ($con['buffer']['text'] . "\n\r");

	// find the first occurence of : in the buffer text and create a
	// substring from then on. Offset 7 on strpos to skip the "*NAMES: "
	// part of the buffer text
	$buffer_text = substr($con['buffer']['text'], strpos($con['buffer']['text'], ':', 7) + 1);

	// create an array with usernames
	$chan_users = explode(" ", $buffer_text);

	// find ops, i.e. users that have the @ sign in front of their nick
	// and add them to the ops array, ignoring the @nocbot
	foreach($chan_users as $chan_user) {
		if (substr($chan_user, 0, 1) == '@' && $chan_user != '@nocbot') {
			$new_ops[] = substr($chan_user, 1); // remove the @ from the username
		}
	}
	$ops = $new_ops;
}

/* Accepts the command as an argument, sends the command
to the server, and then displays the command in the console
for debugging */
function cmd_send($command)
{
    global $con, $time, $CONFIG;
    /* Send the command. Think of it as writing to a file. */
    fputs($con['socket'], $command."\n\r");
    /* Display the command locally, for the sole purpose
    of checking output. (line is not actually not needed) */
    print (date("[m/d @ H:i:s]") ."-> ". $command. "\n\r");
}

function log_to_file ($data)
{
    $filename = "log.txt";
    $data .= "\n";
    // open the log file
    if ($fp = fopen($filename, "ab"))
    {
        // now write to the file
        if ((fwrite($fp, $data) === FALSE))
        {
            echo "Could not write to file.<br />";
        }
    }
    else
    {
        echo "File could not be opened.<br />";
    }
}

function process_commands()
{
    global $con, $CONFIG;

    /* TIME */
    if(strtoupper($con['buffer']['text']) == '.TIME') {
        cmd_send(prep_text("Time", date("F j, Y, g:i a", time())));
    }

    /* NICK */
    if (substr(strtoupper($con['buffer']['text']), 0, 5) == ".NICK"){
        $args = explode(" ", $con['buffer']['text']);

        if (count($args) < 3)
        cmd_send(prep_text("Nick", "Syntax: .nick admin_pass new_nick"));
        else
        {
            if ($args[1] == $CONFIG['admin_pass'])
            cmd_send("NICK ". $args[2]);
            else
            cmd_send(prep_text("Nick", "Invalid password"));
        }
    }

    /* Noob */
    if(strtoupper(substr($con['buffer']['text'], 0, 5)) == '.NOOB') {
        $args = explode(" ", $con['buffer']['text'], 2);
        $name = (!empty($args[1]))?$args[1]:"beginner";
        cmd_send(prep_text("Beginner Help", "Welcome, ".$name.", to PHP! Some tutorials: www.codedemons.net, www.zend.com, www.phpbuilder.com, www.php.net"));
    }

    /* No PMs */
    if(strtoupper(substr($con['buffer']['text'], 0, 5)) == '.PM') {
        cmd_send(prep_text("please"," Please do not send PMs to ops/peons unless you have asked first."));
    }
}

function parse_buffer()
{
    /*
    :username!~identd@hostname JOIN :#php
    :username!~identd@hostname PRIVMSG #PHP :action text
    :username!~identd@hostname command channel :text
    */

    global $con, $CONFIG;

    $temp   = $con['buffer']['all'];
    $buffer = $con['buffer']['all'];
    $buffer = explode(" ", $buffer, 4);

    /* Get username */
    $buffer['username'] = substr($buffer[0], 1, strpos($buffer['0'], "!")-1);

    /* Get identd */
    $posExcl            = strpos($buffer[0], "!");
    $posAt              = strpos($buffer[0], "@");
    $buffer['identd']   = substr($buffer[0], $posExcl+1, $posAt-$posExcl-1);
    $buffer['hostname'] = substr($buffer[0], strpos($buffer[0], "@")+1);

    /* The user and the host, the whole shabang */
    $buffer['user_host'] = substr($buffer[0],1);

    /* Isolate the command the user is sending from
    the "general" text that is sent to the channel
    This is  privmsg to the channel we are talking about.

    We also format $buffer['text'] so that it can be logged nicely.
    */
    switch (strtoupper($buffer[1]))
    {
        case "JOIN":
        $buffer['text']     = "*JOINS: ". $buffer['username']." ( ".$buffer['user_host']." )";
        $buffer['command']  = "JOIN";
        $buffer['channel']  = $CONFIG['channel'];
        break;
        case "QUIT":
        $buffer['text']     = "*QUITS: ". $buffer['username']." ( ".$buffer['user_host']." )";
        $buffer['command']  = "QUIT";
        $buffer['channel']  = $CONFIG['channel'];
        break;
        case "NOTICE":
        $buffer['text']     = "*NOTICE: ". $buffer['username'];
        $buffer['command']  = "NOTICE";
        $buffer['channel']  = substr($buffer[2], 1);
        break;
        case "PART":
        $buffer['text']     = "*PARTS: ". $buffer['username']." ( ".$buffer['user_host']." )";
        $buffer['command']  = "PART";
        $buffer['channel']  = $CONFIG['channel'];
        break;
        case "MODE":
        $buffer['text']     = $buffer['username']." sets mode: ".$buffer[3];
        $buffer['command']  = "MODE";
        $buffer['channel']  = $buffer[2];
        break;
        case "NICK":
        $buffer['text']     = "*NICK: ".$buffer['username']." => ".substr($buffer[2], 1)." ( ".$buffer['user_host']." )";
        $buffer['command']  = "NICK";
        $buffer['channel']  = $CONFIG['channel'];
        break;
        case "353":
        $buffer['text']     = "*NAMES: ". $buffer[3];
        $buffer['command']  = "353";
        break;

        default:
        // it is probably a PRIVMSG
        $buffer['command']  = $buffer[1];
        $buffer['channel']  = $buffer[2];
        $buffer['text']     = substr($buffer[3], 1);
        break;
    }
    $con['buffer']          = $buffer;
    $con['buffer']['all']   = $temp;
}

function prep_text($type, $message)
{
    global $con;
    return ('PRIVMSG '. $con['buffer']['channel'] .' :['.$type.']'.$message);
}
?>
