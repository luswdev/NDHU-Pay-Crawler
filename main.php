<?php
include_once('./PayCrawler.php');

// get user lists
$config = json_decode(file_get_contents('config.json'));
$users = $config->users;

$allRes = [];
foreach ($users as $user) {
    $res = array(
        'time'          => date('Y.m.d H:i:s'),
        'user'          => $user->account,
        'id'            => $user->id,
        'last entry'    => array(),
        'database'      => array(),
        'result'        => ''
    );

    $crawler = new PayCrawler($user->id, $user->account);

    $html = $crawler->getLoginPage();
    if ($html !== false) {
        $html = $crawler->login($user->password, $html);

        if ($html !== false) {
            $html = $crawler->getDataPage();

            if ($html !== false) {
                $html = $crawler->getData($html);

                if ($html === false) {
                    $res['result'] = 'An error has occurred: ' . curl_error($ch);
                } else {
                    $res['last entry'] = $crawler->parseResult($html);
                    $res['database']   = $crawler->databaseResult($config->db);
                    
                    // compare two entry is different or not
                    if ($res['last entry'] === $res['database']) {
                        file_put_contents('cronlog.txt', date('Y/m/d H:i:s').' No new entry.'.PHP_EOL, FILE_APPEND);
                        $res['result'] = 'No new entry.';
                    } else {
                        $res['result'] = 'A new entry found.';

                        // update database
                        $crawler->updateEntry($config->db, $res['last entry']);

                        // connecting to telegram bot
                        file_get_contents('https://lusw.dev/tg/api/bot/pay/'.$user->id.'/'.$$res['last entry']['date'].'/'.$$res['last entry']['name'].'/'.$$res['last entry']['pay']);
                        file_put_contents('cronlog.txt', date('Y/m/d H:i:s').' A new entry find.'.PHP_EOL, FILE_APPEND);
                    }
                }
            } else {
                $res['result'] = 'Getting data page failed.';
            }
        } else {
            $res['result'] = 'Submitting login page failed.';
        }
    } else {
        $res['result'] = 'Getting login page failed.';
    }

    unset($crawler);
    $allRes[] = $res;
}

echo json_encode($allRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
