<?php
// get user lists
$config = json_decode(file_get_contents('config.json'));
$users = $config->users;

// echo '<pre>';
foreach ($users as $user) {
    // echo '================'.$user.'================';

    $url = 'http://velociraptor.ndhu.edu.tw/MSalary/DeskTopDefault1.aspx';
    $ckfile = tempnam('/tmp', 'CURLCOOKIE');
    $useragent = $_SERVER['HTTP_USER_AGENT'];

    $email    = $user->account;
    $password = $user->password;
    
    $ch = curl_init();

    // setup curl information
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_COOKIEFILE     => $ckfile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => $useragent,
    ]);

    // getting the login form
    $html = curl_exec($ch);

    if ($html !== false) {
        // some website verified information
        $viewstate_pattern = '~<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="(.*?)" />~';
        $eventval_pattern  = '~<input type="hidden" name="__VIEWSTATEGENERATOR" id="__VIEWSTATEGENERATOR" value="(.*?)" />~';

        preg_match($viewstate_pattern, $html, $viewstate);
        preg_match($eventval_pattern,  $html, $eventValidation);

        $viewstate       = $viewstate[1];
        $eventValidation = $eventValidation[1];

        // login page information
        $postfields = http_build_query([
            '__EVENTTARGET'         => 'password',
            '__EVENTARGUMENT'       => '',
            '__VIEWSTATE'           => $viewstate,
            '__VIEWSTATEGENERATOR'  => $eventValidation,
            'email'                 => $email,
            'password'              => $password,
        ]);
        
        curl_setopt_array($ch, [
            CURLOPT_REFERER=>$url,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$postfields,
        ]);

        // submitting the login form
        $html = curl_exec($ch);

        if ($html !== false) {
            curl_setopt_array($ch, [
                CURLOPT_URL  => 'http://velociraptor.ndhu.edu.tw/MSalary/fmSary03.aspx',
                CURLOPT_POST => false,
            ]);

            // getting the data page
            $html = curl_exec($ch);

            if ($html !== false) {
                // some website verified information
                $scrollx_pattern = '~<input type="hidden" name="__SCROLLPOSITIONX" id="__SCROLLPOSITIONX" value="(.*?)" />~';
                $scrolly_pattern = '~<input type="hidden" name="__SCROLLPOSITIONY" id="__SCROLLPOSITIONY" value="(.*?)" />~';
                $preview_pattern = '~<input type="hidden" name="__PREVIOUSPAGE" id="__PREVIOUSPAGE" value="(.*?)" />~';

                preg_match($viewstate_pattern, $html, $viewstate);
                preg_match($eventval_pattern,  $html, $eventValidation);
                preg_match($scrollx_pattern,   $html, $scrollX);
                preg_match($scrolly_pattern,   $html, $scrollY);
                preg_match($preview_pattern,   $html, $previewPage);

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
                    '_ctl0:ContentPlaceHolder1:id_no1'      => $user->account,
                    '_ctl0:ContentPlaceHolder1:memo'        => '',
                    '_ctl0:ContentPlaceHolder1:empl_name'   => '',
                    '_ctl0:ContentPlaceHolder1:Button3'     => '開始查詢',
                ]);

                curl_setopt_array($ch, [
                    CURLOPT_POST        => true,
                    CURLOPT_POSTFIELDS  => $postfieldsInner,
                ]);

                // posting the data page
                $html = curl_exec($ch);

                if ($html === false) {
                    echo date('Y/m/d H:i:s').' An error has occurred: ' . curl_error($ch);
                } else {
                    include_once('./simplehtmldom/simple_html_dom.php');
                    $dom = new simple_html_dom();

                    // Load HTML from a string
                    $dom->load($html);
                    $lists = $dom->getElementById('PrintArea');

                    $last_entry = $lists->nodes[0]->nodes[1];
                    $last_date  = $last_entry->nodes[0]->nodes[0]->innertext;
                    $last_name  = $last_entry->nodes[2]->nodes[0]->innertext;
                    $last_pay   = $last_entry->nodes[3]->innertext;

                    $tg_id = $user->id;

                    // echo 'Last entry information:';
                    // echo 'Date: '.$last_date.'<br>';
                    // echo 'Detail: '.$last_name.'<br>';
                    // echo 'Pay: '.$last_pay.'<br>';

                    $DBHOST = $config->db->host;
                    $DBUSER = $config->db->user;
                    $DBPASS = $config->db->password;
                    $DBNAME = $config->db->table;
                    $conn = new mysqli($DBHOST, $DBUSER, $DBPASS, $DBNAME);

                    // get last entry from database
                    $query = 'SELECT last_date,last_name,last_pay FROM user_info WHERE tg_id=?';
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param('i', $tg_id);
                    $stmt->execute();
                    $stmt->bind_result($db_last_date, $db_last_name, $db_last_pay);
                    $stmt->fetch();
                    $stmt->close();

                    // echo 'Database information:';
                    // echo 'Date: '.$db_last_date.'<br>';
                    // echo 'Detail: '.$db_last_name.'<br>';
                    // echo 'Pay: '.$db_last_pay .'<br>';

                    // compare two entry is different or not
                    if (!strcmp($last_date, $db_last_date) &&
                        !strcmp($last_name, $db_last_name) &&
                        !strcmp($last_pay,  $db_last_pay)) {
                        echo date('Y/m/d H:i:s').' No new entry.';
                        file_put_contents('cronlog.txt', date('Y/m/d H:i:s').' No new entry.'.PHP_EOL, FILE_APPEND);
                    } else {
                        echo date('Y/m/d H:i:s').' A new entry found.';

                        // update database
                        $query = 'UPDATE user_info SET last_date=? , last_name=?, last_pay=? WHERE tg_id=?';
                        $stmt  = $conn->prepare($query);
                        $stmt->bind_param('sssi', $last_date, $last_name, $last_pay, $tg_id);
                        $stmt->execute();
                        $stmt->close();

                        // connecting to telegram bot
                        file_get_contents('https://lusw.dev/tg/api/bot/pay/'.$tg_id.'/'.$last_date.'/'.$last_name.'/'.$last_pay);
                        file_put_contents('cronlog.txt', date('Y/m/d H:i:s').' A new entry find.'.PHP_EOL, FILE_APPEND);
                    }
                    
                    $conn->close();
                }
            } else {
                echo date('Y/m/d H:i:s').' Getting data page failed.';
            }
        } else {
            echo date('Y/m/d H:i:s').' Submitting login page failed.';
        }
    } else {
        echo date('Y/m/d H:i:s').' Getting login page failed.';
    }
    curl_close($ch);

    // delete tmp cookie file
    unlink($ckfile);
}

// echo '</pre>';
