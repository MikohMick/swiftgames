<?php
/**
 * Wupex API auth test — DELETE THIS FILE after testing.
 * Upload to your server root and visit in a browser.
 */

$api_key  = 'PASTE_YOUR_PRODUCTION_API_KEY_HERE';
$base_url = 'https://service.wupex.com';

// -------------------------------------------------------
// 1. Outbound IP check
// -------------------------------------------------------
$ip_ch = curl_init( 'https://api.ipify.org' );
curl_setopt( $ip_ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ip_ch, CURLOPT_TIMEOUT, 10 );
$outbound_ip = curl_exec( $ip_ch );
curl_close( $ip_ch );

// -------------------------------------------------------
// 2. Call /api/customer/balance
// -------------------------------------------------------
$ch = curl_init( $base_url . '/api/customer/balance' );
curl_setopt_array( $ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: '     . $api_key,
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en',
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ],
    CURLOPT_HEADERFUNCTION => function ( $curl, $header ) use ( &$response_headers ) {
        $response_headers[] = trim( $header );
        return strlen( $header );
    },
] );

$response_headers = [];
$body             = curl_exec( $ch );
$http_code        = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$curl_error       = curl_error( $ch );
curl_close( $ch );

?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Wupex API Test</title>
<style>
    body { font-family: monospace; padding: 30px; background: #f5f5f5; }
    h2 { margin-top: 24px; }
    pre { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 6px; white-space: pre-wrap; word-break: break-all; }
    .ok  { color: #22c55e; font-weight: bold; }
    .bad { color: #ef4444; font-weight: bold; }
</style>
</head>
<body>

<h1>Wupex Production API Test</h1>

<h2>Server Outbound IP</h2>
<pre><?php echo htmlspecialchars( $outbound_ip ?: 'Could not determine' ); ?></pre>

<h2>Request</h2>
<pre>GET <?php echo htmlspecialchars( $base_url . '/api/customer/balance' ); ?>
x-api-key: <?php echo htmlspecialchars( substr( $api_key, 0, 6 ) . str_repeat( '*', max( 0, strlen( $api_key ) - 6 ) ) ); ?></pre>

<h2>HTTP Status</h2>
<pre class="<?php echo $http_code >= 200 && $http_code < 300 ? 'ok' : 'bad'; ?>"><?php echo (int) $http_code; ?></pre>

<?php if ( $curl_error ) : ?>
<h2>cURL Error</h2>
<pre class="bad"><?php echo htmlspecialchars( $curl_error ); ?></pre>
<?php endif; ?>

<h2>Response Headers</h2>
<pre><?php echo htmlspecialchars( implode( "\n", array_filter( $response_headers ) ) ); ?></pre>

<h2>Response Body</h2>
<pre><?php
$decoded = json_decode( $body, true );
echo htmlspecialchars( $decoded ? json_encode( $decoded, JSON_PRETTY_PRINT ) : $body );
?></pre>

</body>
</html>
