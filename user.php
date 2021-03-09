<?php
include_once('./PayCrawler.php');

// get user lists
$config = json_decode(file_get_contents('config.json'));
$users = $config->users;

$target = $_GET['id'] ?? '';

$allRes = [];
foreach ($users as $user) {
    if (strval($user->id) != $target) {
        continue;
    }
    
    $res = array(
        'time'          => date('Y.m.d H:i:s'),
        'user'          => $user->account,
        'id'            => $user->id,
        'last entry'    => array(),
        'database'      => array(),
        'result'        => '',
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

                // connecting to telegram bot
                file_get_contents($config->botAPI . $user->id);
            } else {
                $res['result'] = 'A new entry found.';

                // update database
                $crawler->updateEntry($config->db, $res['last entry']);

                // connecting to telegram bot
                file_get_contents($config->botAPI . $user->id . '/'
                                                  . $res['last entry']['date'] . '/'
                                                  . $res['last entry']['name'] . '/'
                                                  . $res['last entry']['pay']);
            }
        }
    } else {
        $res['result'] = $crawler->errorMsg;
    }

    unset($crawler);
    $allRes[] = $res;
}

echo json_encode($allRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
