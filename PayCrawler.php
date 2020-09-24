<?php
class PayCrawler {
    private $loginPage;
    private $dataPage;

    private $account;
    private $tgId;

    private $ch;
    private $ckfile;

    public function __construct (int $_id, string $_account) {
        $this->tgId      = $_id;
        $this->account = $_account;

        $this->loginPage = 'http://velociraptor.ndhu.edu.tw/MSalary/DeskTopDefault1.aspx';
        $this->dataPage  = 'http://velociraptor.ndhu.edu.tw/MSalary/fmSary03.aspx';

        $this->ch        = curl_init();
        $this->ckfile    = tempnam('/tmp', 'CURLCOOKIE');
        $this->useragent = $_SERVER['HTTP_USER_AGENT'];
    }

    public function __destruct () {
        curl_close($this->ch);
        unlink($this->ckfile);
    }

    public function getLoginPage () {
        // setup curl information
        curl_setopt_array($this->ch, [
            CURLOPT_URL            => $this->loginPage,
            CURLOPT_COOKIEFILE     => $this->ckfile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $this->useragent,
        ]);

        // getting the login form
        $html = curl_exec($this->ch);
        return $html;
    }

    public function login (string $_password, $_html) {
        // some website verified information
        $viewstate_pattern = '~<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="(.*?)" />~';
        $eventval_pattern  = '~<input type="hidden" name="__VIEWSTATEGENERATOR" id="__VIEWSTATEGENERATOR" value="(.*?)" />~';

        preg_match($viewstate_pattern, $_html, $viewstate);
        preg_match($eventval_pattern,  $_html, $eventValidation);

        $viewstate       = $viewstate[1];
        $eventValidation = $eventValidation[1];

        // login page information
        $postfields = http_build_query([
            '__EVENTTARGET'         => 'password',
            '__EVENTARGUMENT'       => '',
            '__VIEWSTATE'           => $viewstate,
            '__VIEWSTATEGENERATOR'  => $eventValidation,
            'email'                 => $this->account,
            'password'              => $_password,
        ]);
        
        curl_setopt_array($this->ch, [
            CURLOPT_REFERER     => $this->loginPage,
            CURLOPT_POST        => true,
            CURLOPT_POSTFIELDS  => $postfields,
        ]);

        // submitting the login form
        $html = curl_exec($this->ch);
        return $html;
    }

    public function getDataPage () {
        curl_setopt_array($this->ch, [
            CURLOPT_URL  => $this->dataPage,
            CURLOPT_POST => false,
        ]);

        // getting the data page
        $html = curl_exec($this->ch);
        
        return $html;
    }

    public function getData ($_html) {
        // some website verified information
        $viewstate_pattern = '~<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="(.*?)" />~';
        $eventval_pattern  = '~<input type="hidden" name="__VIEWSTATEGENERATOR" id="__VIEWSTATEGENERATOR" value="(.*?)" />~';
        $scrollx_pattern = '~<input type="hidden" name="__SCROLLPOSITIONX" id="__SCROLLPOSITIONX" value="(.*?)" />~';
        $scrolly_pattern = '~<input type="hidden" name="__SCROLLPOSITIONY" id="__SCROLLPOSITIONY" value="(.*?)" />~';
        $preview_pattern = '~<input type="hidden" name="__PREVIOUSPAGE" id="__PREVIOUSPAGE" value="(.*?)" />~';

        preg_match($viewstate_pattern, $_html, $viewstate);
        preg_match($eventval_pattern,  $_html, $eventValidation);
        preg_match($scrollx_pattern,   $_html, $scrollX);
        preg_match($scrolly_pattern,   $_html, $scrollY);
        preg_match($preview_pattern,   $_html, $previewPage);

        $viewstate          = $viewstate[1];
        $eventValidation    = $eventValidation[1];
        $scrollX            = $scrollX[1];
        $scrollY            = $scrollY[1];
        $previewPage        = $previewPage[1];

        // update data information
        $postfieldsInner = http_build_query([
            '__EVENTTARGET'                         => '',
            '__EVENTARGUMENT'                       => '',
            '__VIEWSTATE'                           => $viewstate,
            '__VIEWSTATEGENERATOR'                  => $eventValidation,
            '__SCROLLPOSITIONX'                     => $scrollX,
            '__SCROLLPOSITIONY'                     => $scrollY,
            '__PREVIOUSPAGE'                        => $previewPage,
            '_ctl0:ContentPlaceHolder1:YY1'         => strval(intval(date('Y'))-1911),
            '_ctl0:ContentPlaceHolder1:MM1'         => "01",
            '_ctl0:ContentPlaceHolder1:YY2'         => strval(intval(date('Y'))-1911),
            '_ctl0:ContentPlaceHolder1:MM2'         => date('m'),
            '_ctl0:ContentPlaceHolder1:id_no1'      => $this->account,
            '_ctl0:ContentPlaceHolder1:memo'        => '',
            '_ctl0:ContentPlaceHolder1:empl_name'   => '',
            '_ctl0:ContentPlaceHolder1:Button3'     => '開始查詢',
        ]);

        curl_setopt_array($this->ch, [
            CURLOPT_POST        => true,
            CURLOPT_POSTFIELDS  => $postfieldsInner,
        ]);

        // posting the data page
        $html = curl_exec($this->ch);
        return $html;
    }

    public function parseResult ($_html) : array {
        include_once('./simplehtmldom/simple_html_dom.php');
        $dom = new simple_html_dom();

        // Load HTML from a string
        $dom->load($_html);
        $lists = $dom->getElementById('PrintArea');

        $last_entry = $lists->nodes[0]->nodes[1];
        $res = array(
            'date' => $last_entry->nodes[0]->nodes[0]->innertext,
            'name' => $last_entry->nodes[2]->nodes[0]->innertext,
            'pay'  => $last_entry->nodes[3]->innertext
        );

        return $res;
    }

    public function databaseResult (object $_conf) : array {
        $DBHOST = $_conf->host;
        $DBUSER = $_conf->user;
        $DBPASS = $_conf->password;
        $DBNAME = $_conf->table;
        $conn = new mysqli($DBHOST, $DBUSER, $DBPASS, $DBNAME);

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

    public function updateEntry(object $_conf, array $data) {
        $DBHOST = $_conf->host;
        $DBUSER = $_conf->user;
        $DBPASS = $_conf->password;
        $DBNAME = $_conf->table;
        $conn = new mysqli($DBHOST, $DBUSER, $DBPASS, $DBNAME);

        // update database
        $query = 'UPDATE user_info SET last_date=? , last_name=?, last_pay=? WHERE tg_id=?';
        $stmt  = $conn->prepare($query);
        $stmt->bind_param('sssi', $data['date'], $data['time'], $data['pay'], $this->tgid);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
};
