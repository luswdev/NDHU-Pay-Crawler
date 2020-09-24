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

    $html = $crawler->login($user->password);
    if ($html !== false) {
            $html = $crawler->getData();

            if ($html === false) {
                $res['result'] = $crawler->errorMsg;
            } else {
                $res['last entry'] = $crawler->parseResult($html);
                $res['database']   = $crawler->databaseResult($config->db);
                    
            // compare two entry is different or not
            if ($res['last entry'] === $res['database']) {
                $res['result'] = 'No new entry.';
            } else {
                $res['result'] = 'A new entry found.';

                // update database
                $crawler->updateEntry($config->db, $res['last entry']);

                // connecting to telegram bot
                file_get_contents($config->botAPI.$user->id.'/'.$res['last entry']['date'].'/'.$res['last entry']['name'].'/'.$res['last entry']['pay']);
            }
        }
    } else {
        $res['result'] = $crawler->errorMsg;
    }

    unset($crawler);
    $allRes[] = $res;
}

echo json_encode($allRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
