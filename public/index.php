<?php

require_once '../vendor/autoload.php';

use Starsquare\Monzo\Provider,
    League\OAuth2\Client\Token\AccessToken;

openlog('UberMonzoAttachment', LOG_NDELAY, LOG_USER);

$file = '../config/oauth.json';
$config = json_decode(file_get_contents($file), true);

$provider = new Provider([
    'baseAuthorizationUrl' => 'https://auth.monzo.com/',
    'baseUrl'      => 'https://api.monzo.com/',
    'clientId'     => $config['client_id'],
    'clientSecret' => $config['client_secret'],
    'redirectUri'  => 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'],
]);

function error($code, $message, $header = ' ') {
    syslog(LOG_ERR, $message);
    header($header, true, $code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('message' => $message), JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    error(405, 'You must POST to this API', 'Allow: POST');
}

if (!isset($_SERVER['PHP_AUTH_USER']) || md5($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']) != 'bc2da2ce08c3ed97e228c744ca97fb86') {
    error(401, 'You must use Basic authentication with this API', 'WWW-Authenticate: Basic realm="ifttt-uber-monzo"');
}

$temp = tempnam('/tmp', 'uberMonzoAttachment');
file_put_contents($temp, file_get_contents('php://input'));
syslog(LOG_DEBUG, "Wrote POST body to $temp");

if (empty($_POST['TripMapImage']) || empty($_POST['CompletedAt'])) {
    error(400, 'You must supply a TripMapImage URL and CompletedAt timestamp');
}

syslog(LOG_INFO, 'Looking for Uber transaction at ' . $_POST['CompletedAt']);
$completedTime = DateTimeImmutable::createFromFormat('F j, Y \a\t h:iA', $_POST['CompletedAt']);

if (!$completedTime) {
  error(400, "Cannot parse CompletedAt '${_POST['CompletedAt']}'");
}

$extension = pathinfo($_POST['TripMapImage'], PATHINFO_EXTENSION);

if (!$extension) {
    error(400, "Invalid TripMapImage '${_POST['TripMapImage']}'");
}

$interval = new DateInterval('PT1M');
$start = $completedTime->sub($interval);
$end = $completedTime->add($interval);

$token = new AccessToken($config['access_token']);

if ($token->hasExpired()) {
    syslog(LOG_INFO, 'Access token has expired, refreshing');

    $token = $provider->getAccessToken('refresh_token', [
        'refresh_token' => $token->getRefreshToken()
    ]);

    $config['access_token'] = [
        'access_token'      => $token->getToken(),
        'expires'           => $token->getExpires(),
        'refresh_token'     => $token->getRefreshToken(),
        'resource_owner_id' => $token->getResourceOwnerId()
    ];

	file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT));
}

try {
    $accounts = $provider->getAccounts($token, 'uk_retail');
} catch (\Exception $e) {
    error(503, 'Could not retrieve accounts: ' . $e->getMessage());
}

$transactions = $provider->getTransactions($token, $accounts[0]['id'], $start, $end);
$uberTransactions = array_filter($transactions, function ($t) {
    return $t['merchant'] && ($t['merchant']['name'] == 'Uber');
});

if (count($uberTransactions) !== 1) {
    error(503, sprintf('Cannot match uber transactions (found %d)', count($uberTransactions)));
}

$type = "image/$extension";
syslog(LOG_INFO, sprintf('Adding %s of type %s to transaction %s', $_POST['TripMapImage'], $type, $uberTransactions[0]['id']));

try {
    $result = $provider->registerAttachment($token, $uberTransactions[0]['id'], $_POST['TripMapImage'], $type);
} catch (\Exception $e) {
    error(503, 'Cannot register attachment: ' . $e->getMessage());
}
