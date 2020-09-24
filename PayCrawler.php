<?php
class PayCrawler 
{
    const BASE_URL ='http://velociraptor.ndhu.edu.tw/MSalary';

    private $loginPage;
    private $dataPage;

    private $account;
    private $tgId;

    private $ch;
    private $ckfile;
    private $useragent;
    private $state;

    public $errorMsg;

    public function __construct (int $_id, string $_account) 
    {
        $this->tgId      = $_id;
        $this->account   = $_account;

        $this->loginPage = '/DeskTopDefault1.aspx';
        $this->dataPage  = '/fmSary03.aspx';

        $this->ch        = curl_init();
        $this->ckfile    = tempnam('/tmp', 'CURLCOOKIE');
        $this->useragent = $_SERVER['HTTP_USER_AGENT'];

        if (isset($this->useragent) && preg_match('/^(curl|wget)/i', $this->useragent)) {
            $this->state = 'command';
        }
        else {
            $this->state = 'browser';
        }

        $this->errorMsg  = '';
    }

    public function __destruct () 
    {
        curl_close($this->ch);
        unlink($this->ckfile);
    }

    private function isBrowser() : bool {
        return $this->state === 'browser';
    }

    private function getLoginPage () 
    {
        // setup curl information
        curl_setopt_array($this->ch, [
            CURLOPT_URL            => self::BASE_URL.$this->loginPage,
            CURLOPT_COOKIEFILE     => $this->ckfile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $this->useragent,
        ]);

        // getting the login form
        $html = curl_exec($this->ch);
        return $html;
    }

    public function login (string $_password) 
    {
        $html = $this->getLoginPage();

        if ($html === false) {
            $this->errorMsg = 'Getting login page failed:' . curl_error($this->ch);
            return $html;
        }

        // login page information
        $postfields = http_build_query([
            '__EVENTTARGET'        => 'password',
            '__EVENTARGUMENT'      => '',
            '__VIEWSTATE'          => $this->parseVerified('__VIEWSTATE', $html),
            '__VIEWSTATEGENERATOR' => $this->parseVerified('__VIEWSTATEGENERATOR', $html),
            'email'                => $this->account,
            'password'             => $_password,
        ]);
        
        curl_setopt_array($this->ch, [
            CURLOPT_REFERER    => self::BASE_URL.$this->loginPage,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $postfields,
        ]);

        // submitting the login form
        $html = curl_exec($this->ch);

        if ($html === false) {
            $this->errorMsg = 'Submitting login page failed: ' . curl_error($this->ch);
        }

        return $html;
    }

    private function getDataPage () 
    {
        curl_setopt_array($this->ch, [
            CURLOPT_URL  => self::BASE_URL.$this->dataPage,
            CURLOPT_POST => false,
        ]);

        // getting the data page
        $html = curl_exec($this->ch);
        
        return $html;
    }

    public function getData () 
    {
        $html = $this->getDataPage();

        if ($html === false) {
            $this->errorMsg = 'Getting data page failed: ' . curl_error($this->ch);
            return $html;
        }

        // update data information
        $postfieldsInner = http_build_query([
            '__EVENTTARGET'                       => '',
            '__EVENTARGUMENT'                     => '',
            '__VIEWSTATE'                         => $this->parseVerified('__VIEWSTATE', $html),
            '__VIEWSTATEGENERATOR'                => $this->parseVerified('__VIEWSTATEGENERATOR', $html),
            '__SCROLLPOSITIONX'                   => $this->parseVerified('__SCROLLPOSITIONX', $html),
            '__SCROLLPOSITIONY'                   => $this->parseVerified('__SCROLLPOSITIONY', $html),
            '__PREVIOUSPAGE'                      => $this->parseVerified('__PREVIOUSPAGE', $html),
            '_ctl0:ContentPlaceHolder1:YY1'       => strval(intval(date('Y'))-1911),
            '_ctl0:ContentPlaceHolder1:MM1'       => '01',
            '_ctl0:ContentPlaceHolder1:YY2'       => strval(intval(date('Y'))-1911),
            '_ctl0:ContentPlaceHolder1:MM2'       => date('m'),
            '_ctl0:ContentPlaceHolder1:id_no1'    => $this->account,
            '_ctl0:ContentPlaceHolder1:memo'      => '',
            '_ctl0:ContentPlaceHolder1:empl_name' => '',
            '_ctl0:ContentPlaceHolder1:Button3'   => '開始查詢',
        ]);

        curl_setopt_array($this->ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $postfieldsInner,
        ]);

        // posting the data page
        $html = curl_exec($this->ch);

        if ($html === false) {
            $this->errorMsg = 'An error has occurred: ' . curl_error($this->ch);
        }

        return $html;
    }

    public function parseResult (string $_html) : array 
    {
        include_once('./simplehtmldom/simple_html_dom.php');
        $dom = new simple_html_dom();

        // Load HTML from a string
        $dom->load($_html);
        $lists = $dom->getElementById('PrintArea');

        $last_entry = $lists->nodes[0]->nodes[1];
        $res = array(
            'date' => $this->isBrowser() ? $last_entry->nodes[0]->innertext : $last_entry->nodes[0]->nodes[0]->innertext,
            'name' => $this->isBrowser() ? $last_entry->nodes[2]->innertext : $last_entry->nodes[2]->nodes[0]->innertext,
            'pay'  => $last_entry->nodes[3]->innertext
        );

        return $res;
    }

    public function databaseResult (object $_conf) : array 
    {
        $conn = $this->connectDatabase($_conf);

        // get last entry from database
        $query = 'SELECT last_date,last_name,last_pay FROM user_info WHERE tg_id=?';
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $this->tgId);
        $stmt->execute();
        $stmt->bind_result($db_last_date, $db_last_name, $db_last_pay);
        $stmt->fetch();
        $stmt->close();
        $conn->close();

        $res = array(
            'date' => $db_last_date,
            'name' => $db_last_name,
            'pay'  => $db_last_pay 
        );

        return $res;
    }

    public function updateEntry(object $_conf, array $_data) 
    {
        $conn = $this->connectDatabase($_conf);

        // update database
        $query = 'UPDATE user_info SET last_date=? , last_name=?, last_pay=? WHERE tg_id=?';
        $stmt  = $conn->prepare($query);
        $stmt->bind_param('sssi', $_data['date'], $_data['name'], $_data['pay'], $this->tgId);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }

    private function parseVerified (string $_name, string $_html) : string  
    {
        $pattern = '~<input type="hidden" name="'.$_name.'" id="'.$_name.'" value="(.*?)" />~';

        preg_match($pattern, $_html, $values);

        return $values[1] ?? '';
    }

    private function connectDatabase (object $_conf) 
    {
        $conn = new mysqli($_conf->host, $_conf->user, $_conf->password, $_conf->table);

        return $conn ?? null;
    }
};
