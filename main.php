<?php
include_once('./PayCrawler.php');

header('Content-Type: text/plain; charset=utf-8');

// get user lists
$config = json_decode(file_get_contents('config.json'));
$users = $config->users;

$target = $_GET['id'] ?? false;

$allRes = [];
foreach ($users as $user) {    
    if ($target && strval($user->id) != $target) {
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
                if ($target) {
                    file_get_contents($config->botAPI . $user->id);
                }
            } else {
                $res['result'] = 'A new entry found.';

                $resName = str_replace("/", " ", $res['last entry']['name']);

                // update database
                $crawler->updateEntry($config->db, $res['last entry']);
                $url = $config->botAPI . $user->id . '/'
                                       . $res['last entry']['date'] . '/'
                                       . $res['last entry']['pay'] . '/'
                                       . urlencode($resName);

                // connecting to telegram bot
                file_get_contents($url);
            }
        }
    } else {
        $res['result'] = $crawler->errorMsg;
    }

    unset($crawler);
    $allRes[] = $res;
}

echo json_encode($allRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

$logFile = __DIR__ . '/logs/' . date('Y');
if (!file_exists($logFile)) {
    mkdir($logFile);
}

$logFile .= '/' . date('m');
if (!file_exists($logFile)) {
    mkdir($logFile);
}

$logFile .= '/PayCrawler-' . date('Y.m.d') . '.log';
if (!file_exists($logFile)) {
    touch($logFile);
}

$logJson = json_decode(file_get_contents($logFile));

$logJson[] = $allRes;
file_put_contents($logFile,
    json_encode($logJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

?>
