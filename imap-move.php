#!/usr/bin/php
<?php
/**
 * imap-move is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version. * imap-move is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details. * You should have received a copy of the GNU General Public License
 * along with imap-move. If not, see http://www.gnu.org/licenses/.
 *
 * Purpose: Moves Mail from one IMAP account to another
 *
 * @author Marius Bezuidenhout (marius dot bezuidenhout at gmaill dot com)
 *
 * 
 * Run Like:
 *    php ./imap-move.php \
 *        --source imap-ssl://userA:secret-password@imap.example.com:993/ \
 *        --target imap-ssl://userB:secret-passwrod@imap.example.com:993/sub-folder \
 *        [ [ --wipe | --sync ] | --fake | --once ]
 *
 *    --fake to just list what would be copied
 *    --wipe to remove messages after they are copied
 *    --once to only copy one e-mail and quit
 *    --sync to sync source and target
 *
 */

set_time_limit(0);
error_reporting(E_ALL | E_STRICT);

// Test that php-imap is installed
if (!function_exists('imap_open')) {
    echo "Please install the php imap package.\n";
    echo "e.g. For Ubuntu: sudo apt-get install php5-imap\n";
    exit(1);
}

if($argv === null) { // If $argv is null then use base64 encoded args from $_GET
    $argv = array_keys($_GET);
    $arg_cnt = count($argv);
    $arg_vrs = array();
    for ($i=0;$i<$arg_cnt;$i++) {
        switch ($argv[$i]) {
            case '--source':
            case '-s':
            case '--target':
            case '-t':
                $arg_vrs[] = $argv[$i];
                $arg_vrs[] = base64_decode($argv[++$i]);
                break;
            default:
                $arg_vrs[] = $argv[$i];
        }
    }
} else {
    $arg_cnt = $argc;
    $arg_vrs = $argv;
}

$prog = new MAIN($arg_cnt, $arg_vrs);

$prog->run();

class MESSAGE
{
    private $_message_id;
    private $_date;
    private $_subject;
    private $_body;
    private $_seen;
    private $_answered;
    private $_flagged;
    private $_draft;
    private $_recent;
    private $_deleted;
    private $_size;
    
    public function __construct($msg_body = null)
    {
        $this->_body = $msg_body;
        $this->_message_id = '';
    }
    
    public function setMessageId($id)
    {
        $this->_message_id = $id;
        return $this;
    }
    
    public function getMessageId()
    {
        return $this->_message_id;
    }
    
    public function setDate($date)
    {
        $this->_date = $date;
        return $this;
    }
    
    public function getDate()
    {
        return gmstrftime('%d-%b-%Y %H:%M:%S +0000', $this->_date);
    }
    
    public function setSubject($subject)
    {
        $this->_subject = $subject;
        return $this;
    }
    
    public function getSubject()
    {
        return $this->_subject;
    }
    
    public function setBody($body)
    {
        $this->_body = $body;
        return $this;
    }
    
    public function getBody()
    {
        return file_get_contents($this->_body);
    }
    
    public function setSeen($seen)
    {
        $this->_seen = $seen;
        return $this;
    }
    
    public function getSeen()
    {
        return $this->_seen;
    }
    
    public function setAnswered($answered)
    {
        $this->_answered = $answered;
        return $this;
    }
    
    public function getAnswered()
    {
        return $this->_answered;
    }
    
    public function setFlagged($flagged)
    {
        $this->_flagged = $flagged;
        return $this;
    }
    
    public function getFlagged()
    {
        return $this->_flagged;
    }
    
    public function setDraft($draft)
    {
        $this->_draft = $draft;
        return $this;
    }
    
    public function getDraft()
    {
        return $this->_draft;
    }
    
    public function setRecent($recent)
    {
        $this->_recent = $recent;
        return $this;
    }
    
    public function getRecent()
    {
        return $this->_recent;
    }
    
    public function setDeleted($deleted)
    {
        $this->_deleted = $deleted;
        return $this;
    }
    
    public function getDeleted()
    {
        return $this->_deleted;
    }
    
    public function setSize($size)
    {
        $this->_size = $size;
        return $this;
    }
    
    public function getSize()
    {
        return $this->_size;
    }
    
}

abstract class MAIL
{
    protected $_tmp_msg_file = 'mail';
    protected $_mailboxes = array(); 
    protected $_wipe; // Delete message
    
    public function __construct()
    {
        $this->_tmp_msg_file = tempnam(getcwd(), 'mail_');
        $this->_mailboxes = $this->listMailboxes();
    }
    public abstract function setPath($p);
    public abstract function pathStat();
    public abstract function mailStat($i);
    public abstract function mailGet($i);
    public abstract function mailPut($message);
    public abstract function mailWipe($i);
    public abstract function listPath($pat='*');
    public abstract function listMailboxes();
    public abstract function getSubscribed();
    public abstract function setSubscribed($p, $subscribe);
    public abstract function close();
}

class FILE extends MAIL
{
    private $_c; // SQLite3 instance
    private $_c_file; // SQLite3 file
    private $_path; // Current mail path;
    
    /**
        Connect to SQLite3
    */
    public function __construct($uri, $wipe = false)
    {
        $this->_c = null;
        $this->_wipe = $wipe;
        $this->_c_file = $uri['host'];
        if(isset($uri['user'])) {
            $this->_c_file = $uri['user'] . '@' . $this->_c_file;
        }
        echo "SQLite3:open($this->_c_file)\n";
        $this->_c = new SQLite3($this->_c_file);
        $this->_c->exec('CREATE TABLE IF NOT EXISTS mail(path, message_id, date, subject, body, seen, answered, flagged, draft, recent, deleted, size);');
        $this->_c->exec('CREATE TABLE IF NOT EXISTS mailbox(date, path, size, check_date, check_mail_count, check_path, subscribed);');
		parent::__construct();
    }
    
    public function getSubscribed()
    {
        $result = $this->_c->query(
            sprintf('SELECT path FROM mailbox WHERE subscribed=%d', 1));
        $res = array();
        while($row = $result->fetchArray()) {
            $res[] = $row['path'];
        }
        return $res;
    }

    public function setSubscribed($p, $subscribe = true)
    {
        $this->_c->query(sprintf('UPDATE mailbox SET subscribed = %d WHERE path = \'%s\'', ($subscribe?1:0), $p));
    }
    
    public function listPath($pat='*')
    {
        $result = $this->_c->query('SELECT path FROM mailbox');
        $res = array();
        while($row = $result->fetchArray()) {
            $res[] = array(
                'name' => $row['path'],
                'attribute' => 64
            );
        }
        return $res;
    }

	public function listMailboxes()
	{
		$result = $this->_c->query('SELECT path FROM mailbox');
		$res = array();
		while($row = $result->fetchArray())
			$res[] = $row['path'];
		return $res;
	}
    
    public function setPath($p)
    {
        $this->_path = imap_utf7_decode($p);
        $res = $this->_c->querySingle(sprintf('SELECT path FROM mailbox WHERE path=\'%s\'', $this->_c->escapeString($p)));
        if($res == null) {
            $this->_c->exec(
                sprintf('INSERT INTO mailbox (path) VALUES (\'%s\');', $this->_c->escapeString($this->_path))
            );
        }
        return true;
    }
    
    public function pathStat()
    {
        $res = array();
        $result = $this->_c->querySingle(sprintf('SELECT count(*) AS mail_count FROM mail WHERE path=\'%s\';', $this->_c->escapeString($this->_path)));
        $res['mail_count'] = $result;
        return $res;
    }
    
    public function mailStat($i, $message = null)
    {
        if(!$message instanceof MESSAGE)
            $message = new MESSAGE();
        $result = $this->_c->querySingle(
            sprintf('SELECT path, message_id, subject, date, seen, answered, flagged, draft, recent, deleted, size FROM mail WHERE path=\'%s\' LIMIT %d, 1;', 
                $this->_c->escapeString($this->_path), 
                $i - 1), 
            true);
        //$result = $this->_c->querySingle('SELECT * FROM mail', true);
        $message->setMessageId($result['message_id'])
            ->setDate(strtotime($result['date']))
            ->setSubject($result['subject'])
            ->setSeen($result['seen'])
            ->setAnswered($result['answered'])
            ->setFlagged($result['flagged'])
            ->setDraft($result['draft'])
            ->setRecent($result['recent'])
            ->setDeleted($result['deleted']);
        return $message;
    }
    
    public function mailGet($i, $message = null)
    {
        if(!$message instanceof MESSAGE)
            $message = new MESSAGE();
        $result = $this->_c->querySingle(
            sprintf('SELECT body FROM mail WHERE path=\'%s\' LIMIT %d, 1;',
                $this->_c->escapeString($this->_path),
                $i - 1));
        file_put_contents($this->_tmp_msg_file, $result);
        $message->setBody($this->_tmp_msg_file);
        return $message;
    }
    
    public function mailPut($message)
    {
        return $this->_c->exec(
            sprintf('INSERT INTO mail (path, message_id, date, subject, body, seen, answered, flagged, draft, recent, deleted, size) 
                VALUES (\'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', \'%s\');', 
                $this->_c->escapeString($this->_path), 
                $this->_c->escapeString($message->getMessageId()), 
                $this->_c->escapeString($message->getDate()), 
                $this->_c->escapeString($message->getSubject()), 
                $this->_c->escapeString($message->getBody()),
                $this->_c->escapeString($message->getSeen()),
                $this->_c->escapeString($message->getAnswered()),
                $this->_c->escapeString($message->getFlagged()),
                $this->_c->escapeString($message->getDraft()),
                $this->_c->escapeString($message->getRecent()),
                $this->_c->escapeString($message->getDeleted()),
                $this->_c->escapeString($message->getSize())
        ));
    }
    
    public function mailWipe($i)
    {
        $res = false;
        if($this->_wipe) {
            $res = $this->_c->exec(
        	   sprintf('DELETE FROM mail LIMIT %d, 1;', $i - 1)
            );
        }
        return $res;
    }
    
    /**
        Closes the database file
     */
    public function close()
    {
        $this->_c->close();
    }
}

class IMAP extends MAIL
{
    private $_c; // Connection Handle
    private $_c_host; // Server Part {}
    private $_c_base; // Base Path Requested
    /**
        Connect to an IMAP
    */
    public function __construct($uri, $wipe = false, $readonly = false)
    {
        $this->_c = null;
        $this->_wipe = $wipe;
        $this->_c_host = sprintf('{%s',$uri['host']);
        if (!empty($uri['port'])) {
            $this->_c_host .= sprintf(':%d',$uri['port']);
        }
        switch (strtolower(@$uri['scheme'])) {
            case 'imap-ssl':
                $this->_c_host .= '/ssl/novalidate-cert';
                break;
            case 'imap-tls':
                $this->_c_host .= '/tls/novalidate-cert';
                break;
            default:
        }
        if($readonly)
            $this->_c_host .= '/readonly';
        $this->_c_host.= '}';

        $this->_c_base = $this->_c_host;
        // Append Path?
        if (!empty($uri['path'])) {
            $x = ltrim($uri['path'],'/');
            if (!empty($x)) {
                $this->_c_base = $x;
            }
        }
        echo "imap_open($this->_c_host)\n";
        $this->_c = imap_open($this->_c_host,$uri['user'],base64_decode($uri['pass']));
        if(!is_resource($this->_c)) {
            echo implode(', ',imap_errors());
            echo "Could not open " . $this->_c_host;
            exit;
        }
        parent::__construct();
    }
    /**
        List folders matching pattern
        @param $pat * == all folders, % == folders at current level
    */
    public function listPath($pat='*')
    {
        $ret = array();
        $list = imap_getmailboxes($this->_c, $this->_c_host,$pat);
        foreach ($list as $x) {
            if (preg_match('/}(.+)$/', $x->name, $m)) {
                $tgt_path = imap_utf7_decode($m[1]);
            } else {
                $tgt_path = imap_utf7_decode($x->name);
            }
            $ret[] = array(
                'name' => $tgt_path,
                'attribute' => $x->attributes,
                'delimiter' => $x->delimiter,
            );
        }
        return $ret;
    }

	public function listMailboxes()
	{
		$ret = array();
		$list = imap_getmailboxes($this->_c, $this->_c_host, '*');
		foreach($list as $x) {
			if (preg_match('/}(.+)$/', $x->name, $m)) {
                $ret[] = imap_utf7_decode($m[1]);
            }
		}
		return $ret;
    }
    
    public function getSubscribed()
    {
        $ret = array();
        $list = imap_lsub($this->_c, $this->_c_host, '*');
        for($i = 0; $i < count($list); $i++) {
            if(preg_match('/}(.+)$/', $list[$i], $m)) {
                $ret[] = imap_utf7_decode($m[1]);
            }
        }
        return $ret;
    }

    /**
        Get a Message
    */
    public function mailGet($i, $message = null)
    {
        if(!$message instanceof MESSAGE)
            $message = new MESSAGE();
        // return imap_body($this->_c,$i,FT_PEEK);
        imap_savebody($this->_c, $this->_tmp_msg_file, $i, null, FT_PEEK);
        $errors = imap_errors();
        if(is_array($errors) && count($errors)) {
            print_r($errors);
            exit;
        }
        $message->setBody($this->_tmp_msg_file);
        return $message;
    }

    /**
        Store a Message with proper date
    */
    public function mailPut($message)
    {
        $stat = $this->pathStat();
        $opts = array();
        if($message->getSeen())
            $opts[] = '\Seen';
        if($message->getAnswered())
            $opts[] = '\Answered';
        if($message->getFlagged())
            $opts[] = '\Flagged';
        if($message->getDeleted())
            $opts[] = '\Deleted';
        if($message->getDraft())
            $opts[] = '\Draft';
        //if($message->getRecent()) // Recent is a read-only property
        //    $opts[] = '\Recent';
        $body = $message->getBody();
        // TODO this should be on a switch
        if(strlen($body) == 0) {
            $body = "<empty msg>"; // some IMAP servers will fatally fail if the msg is empty
        }
        if(strlen($body) > 0) {
            $ret = imap_append($this->_c, $stat['path'], $body, implode(' ',$opts), $message->getDate());
        }
        $errors = imap_errors();
        if(is_array($errors) && count($errors)) {
            print_r($errors);
        }
        return $ret;

    }

    /**
        Message Info
        @param $i
        @param $message MESSAGE
        @return MESSAGE
    */
    public function mailStat($i, $message = null)
    {
        if(!$message instanceof MESSAGE)
            $message = new MESSAGE();
        $head = imap_headerinfo($this->_c,$i);
        $message->setDate(strtotime($head->MailDate))
            ->setSeen($head->Unseen != 'U')
            ->setAnswered(($head->Answered == 'A'))
            ->setFlagged(($head->Flagged == 'F'))
            ->setDeleted(($head->Deleted == 'D'))
            ->setDraft(($head->Draft == 'X'));
        if(property_exists($head, 'Subject'))
            $message->setSubject($head->Subject);
            
        #if(!(property_exists($head, 'message_id'))) {
        #    echo "\nMessage with subject '" . $message->getSubject() . "' contains no message id\n";
        #} else {
        if((property_exists($head, 'message_id'))) {
            $message->setMessageId($head->message_id);
        }
        if($head->Recent == 'R') {
            $message->setSeen(true)
                ->setRecent(true);
        } elseif($head->Recent == 'N') {
            $message->setSeen(false)
                ->setRecent(true);
        }
        
        return $message;
        //return (array)$head;
        // $stat = imap_fetch_overview($this->_c,$i);
        // return (array)$stat[0];
    }

    /**
        Immediately Delete and Expunge the message
    */
    public function mailWipe($i)
    {
        if ( ($this->_wipe) && (imap_delete($this->_c,$i)) ) return imap_expunge($this->_c);
    }

    /**
        Sets the Current Mailfolder
    */
    public function setPath($p)
    {
		if (substr($p,0,1)!='{') {
			if($p == 'inbox')
				$imap_full_path = $this->_c_host;
			else
				$imap_full_path = $this->_c_host . trim($p,'/');
		}

		if(!in_array($p, $this->_mailboxes)) {
            echo "Creating mailbox: " . imap_utf7_encode($imap_full_path) . "\n";
			imap_createmailbox($this->_c, imap_utf7_encode($imap_full_path));
			$this->_mailboxes[] = $p;
		}
        imap_reopen($this->_c, imap_utf7_encode($imap_full_path)) or die(implode(", ", imap_errors()));
        return true;
    }

    public function setSubscribed($p, $subscribe = true)
    {
        if (substr($p,0,1)!='{') {
			if($p == 'inbox')
				$imap_full_path = $this->_c_host;
			else
				$imap_full_path = $this->_c_host . trim($p,'/');
        }
        
        if($subscribe)
            return imap_subscribe($this->_c, imap_utf7_encode($imap_full_path));
        else
            return imap_unsubscribe($this->_c, imap_utf7_encode($imap_full_path));
    }

    /**
        Returns Information about the current Path
    */
    public function pathStat()
    {
        $res = imap_check($this->_c);
        $ret['date'] = $res->Date;
        $ret['mail_count'] = $res->Nmsgs;
        $ret['path'] = $res->Mailbox;
        return $ret;
    }
    
    /**
        Closes the connection
     */
    public function close()
    {
        imap_close($this->_c);
        if(file_exists($this->_tmp_msg_file))
            unlink($this->_tmp_msg_file);
    }
}

class MAIN {
    private $src;
    private $tgt;
    private $fake;
    private $wipe;
    private $once;
    private $sync;

    public function __construct($argc, $argv)
    {
        $src = null;
        $tgt = null;
        $fake = false;
        $once = false;
        $wipe = false;
        $sync = false;
        $this->_args($argc,$argv);
    }

    public function run() {

        echo "Connecting Source...\n";
        $S = $this->_mail_conn($this->src, !$this->wipe);

        echo "Connecting Target...\n";
        $T = $this->_mail_conn($this->tgt);

        $src_path_list = $S->listPath();
        $dst_path_list = $T->listPath();
        $src_subscribed_list = $S->getSubscribed();
        $dst_subscribed_list = $T->getSubscribed();

        echo "Source subscribed list: " . implode(", ", $src_subscribed_list) . "\n";
        echo "Target subscribed list: " . implode(", ", $dst_subscribed_list) . "\n";

        foreach ($src_path_list as $path) {

            echo "S: path {$path['name']} = {$path['attribute']}\n";

            // Skip Logic Below
            if ($this->_path_skip($path)) {
                echo "S: skip {$path['name']}\n";
                continue;
            }

            // Source Path
            $S->setPath($path['name']);
            $src_path_stat = $S->pathStat();
            
            echo "S: {$src_path_stat['mail_count']} messages\n";
            if (empty($src_path_stat['mail_count'])) {
                echo "S: skip\n";
                continue;
            }

            // Target Path
            $tgt_path = null;
            if (preg_match('/}(.+)$/', $path['name'], $m)) {
                $tgt_path = $m[1];
            } else {
                $tgt_path = $path['name'];
            }
            $T->setPath($tgt_path); // Creates if needed

            // Show info on Target
            $tgt_path_stat = $T->pathStat();
            echo "T: {$tgt_path_stat['mail_count']} messages\n";

            // Build Index of Target
            echo "T: Indexing:       ";
            $tgt_mail_list = array();
            $tgt_mail_list_no_subject = array();
            for ($i=1;$i<=$tgt_path_stat['mail_count'];$i++) {
                echo "\033[6D";
                echo str_pad($i, 6, ' ', STR_PAD_RIGHT);
                $message = $T->mailStat($i);
                if(strlen($message->getMessageId()) > 0)
                    $tgt_mail_list[ $message->getMessageId() ] = array('Subject' => $message->getSubject(), 'Date' => $message->getDate(), 'Position' => $i);
                else
                    $tgt_mail_list_no_subject[ $message->getDate() ] = array('Subject' => $message->getSubject(), 'Date' => $message->getDate(), 'Position' => $i);
            }
            echo "\n";
            // print_r($tgt_mail_list);
            // for ($i=1;$i<=$src_path_stat['mail_count'];$i++) {
            $copied = 0;
            $skipped = 0;
            $src_mail_list = array();
            $src_mail_list_no_subject = array();
            for ($i=$src_path_stat['mail_count'];$i>=1;$i--) {
                $message = $S->mailStat($i);
                
                if(strlen($message->getMessageId()) > 0 ) {
                    $src_mail_list[ $message->getMessageId() ] = array('Subject' => $message->getSubject(), 'Date' => $message->getDate(), 'Position' => $i);
                    if (array_key_exists($message->getMessageId(), $tgt_mail_list)) {
                        //echo "Source: Mail: {$message->getSubject()} Copied Already\n";
                        $skipped++;
                        self::print_progress($copied, $skipped);
                        if($this->wipe)
                            $S->mailWipe($i);
                        continue;
                    }
                } else {
                    $src_mail_list_no_subject[ $message->getDate() ] = array('Subject' => $message->getSubject(), 'Date' => $message->getDate(), 'Position' => $i);
                    // There is no message ID. Find message on Date and Subject
                    if (array_key_exists($message->getDate(), $tgt_mail_list_no_subject)) {
                        //echo "Source: Mail: {$message->getSubject()} Copied Already\n";
                        $skipped++;
                        self::print_progress($copied, $skipped);
                        if($this->wipe)
                            $S->mailWipe($i);
                        continue;
                    }
                }

                // echo "S: {$message->getSubject()} {$message->getDate()}\n";

                $message = $S->mailGet($i, $message);
                $opts = array();

                //if(!$message instanceof MESSAGE)
                //    continue;

                if ($this->fake) {
                    continue;
                }
                
                $res = $T->mailPut($message);
                // echo "T: $res\n";
                $copied++;
                self::print_progress($copied, $skipped);
                if(($copied % 20) == 0)
                    if( ob_get_level() > 0 ) ob_flush();
                if($this->wipe)
                    $S->mailWipe($i);

                if ($this->once) {
                    $S->close();
                    $T->close();
                    die("--one and done\n");
                }

            }
            
            echo "\nCopied $copied messages to $tgt_path \n";
            echo "Skipped $skipped messages \n";

            if(!$this->fake && in_array($path['name'], $src_subscribed_list) && !in_array($path['name'], $dst_subscribed_list)) {
                $T->setSubscribed($path['name']);
            }

            $deleted = 0;
            if($this->sync && (($tgt_path_stat['mail_count'] + $copied) > $src_path_stat['mail_count'] )) {
                // Delete message from target that doesn't exist in source
                foreach($tgt_mail_list as $key => $value) {
                    if(!array_key_exists($key, $src_mail_list)) {
                        //echo "Message with subject: " . $value['Subject'] . " does not exist in source";
                        $T->mailWipe($value['Position']);
                        $deleted++;
                    }
                }
                foreach($tgt_mail_list_no_subject as $key => $value) {
                    if(!array_key_exists($key, $src_mail_list_no_subject)) {
                        echo "Message with date: " . $value['Date'] . " does not exist in source";
                        $T->mailWipe($value['Position']);
                        $deleted++;
                    }
                }
                echo "Deleted $deleted messages \n";
            }
            
        }

        $S->close();
        $T->close();
    }

    /**
     Process CLI Arguments
     */
    function _args($argc,$argv)
    {
        $ini_array = [];
        for ($i=1;$i<$argc;$i++) {
            switch ($argv[$i]) {
                case '--config':
                    $i++;
                    if (!empty($argv[$i])) {
                       $ini_array = parse_ini_file($argv[$i], true);
                       if ($ini_array['src']['uri'])
                       {
                           echo "Source from config file\n";
                           $this->src = parse_url($ini_array['src']['uri']);
                       }
                       if ($ini_array['dst']['uri'])
                       {
                           echo "Target from config file\n";
                           $this->tgt = parse_url($ini_array['dst']['uri']);
                       }
                    }

                    break;
            	case '--source':
            	case '-s':
            	    $i++;
            	    if (!empty($argv[$i])) {
            	        $this->src = parse_url($argv[$i]);
            	    }
            	    break;
            	case '--target':
            	case '-t': // Destination
            	    $i++;
            	    if (!empty($argv[$i])) {
            	        $this->tgt = parse_url($argv[$i]);
            	    }
            	    break;
            	case '--fake':
            	    $this->fake = true;
            	    break;
            	case '--once':
            	    $this->once = true;
            	    break;
            	case '--wipe':
            	    $this->wipe = true;
                    break;
                case '--sync':
                    $this->sync = true;
                    break;
            	default:
            	    echo "arg: {$argv[$i]}\n";
            }
        }

        if($this->sync && $this->wipe)
            die("--sync and --wipe are mutually exclusive");

        if ( (empty($this->src['path'])) || ($this->src['path']=='/') ) {
            $this->src['path'] = '/INBOX';
        }
        if ( (empty($this->tgt['path'])) || ($this->tgt['path']=='/') ) {
            $this->tgt['path'] = '/INBOX';
        }
    }

    /**
    @return true if we should skip this path
    */
    protected function _path_skip($path)
    {
        $ret = false;
        if ( ($path['attribute'] & LATT_NOSELECT) == LATT_NOSELECT) {
            $ret = true;
        }
        // All Mail, Trash, Starred have this attribute
        if ( ($path['attribute'] & 96) == 96) {
            $ret = true;
        }
        // Skip by Pattern
        if (preg_match('/}(.+)$/',$path['name'],$m)) {
            switch ($m[1]) {
            	case '[Gmail]/All Mail':
            	case '[Gmail]/Sent Mail':
            	case '[Gmail]/Spam':
            	case '[Gmail]/Starred':
            	    $ret = true;
            	    break;
            }
        }

        return $ret;
    }

    protected function _mail_conn($uri, $readonly = false)
    {
        switch(strtolower(@$uri['scheme'])) {
        	case 'imap-ssl':
        	case 'imap-tls':
        	    return new IMAP($uri, $this->wipe, $readonly);
        	    break;
        	case 'file':
        	    return new FILE($uri, $this->wipe);
        	    break;
        	default:
        	    die(self::_usage(sprintf('Invalid scheme \'%s\'', $uri['scheme'])));
        }
    }

    public static function _usage($message = '')
    {
        return sprintf('%s

        Usage:
        php ./imap-move.php \
            --source <URI> \
            --target <URI> \
            [ [ --wipe | --sync ] | --fake | --once ]

        --fake to just list what would be copied
        --wipe to remove messages after they are copied
        --once to only copy one message then exit
        --sync to sync source and target

        URI = (imap-ssl | imap-tls)://user:password@imap.example.com:993/[ folder ]
              (file: | file:///<FULLPATH>)filename.db

	Password is base64 encoded
'
            , $message);
    }

    public static function print_progress($copied, $skipped)
    {
        // Copied: ...... Skipped: ......
        echo "\033[30D";
        echo "Copied: " . str_pad($copied, 6, ' ', STR_PAD_RIGHT) . " Skipped: " . str_pad($skipped, 6, ' ', STR_PAD_RIGHT);        
    }
}

