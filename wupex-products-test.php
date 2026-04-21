<?php
/**
 * Wupex product list API test — DELETE THIS FILE after testing.
 * 1. Fill in your API key below
 * 2. Upload to your server root and visit in a browser
 */

$api_key  = 'PASTE_YOUR_PRODUCTION_API_KEY_HERE';
$base_url = 'https://service.wupex.com';

$payload = json_encode( [ 'page' => 1, 'pageSize' => 10 ] );

$ch = curl_init( $base_url . '/api/product/merchant/invited/list' );
curl_setopt_array( $ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: '     . $api_key,
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ],
    CURLOPT_HEADERFUNCTION => function ( $curl, $header ) use ( &$resp_headers ) {
        $resp_headers[] = trim( $header );
        return strlen( $header );
    },
] );

$resp_headers = [];
$body         = curl_exec( $ch );
$http_code    = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$curl_error   = curl_error( $ch );
curl_close( $ch );

$decoded = json_decode( $body, true );

?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Wupex Product List Test</title>
<style>
    body { font-family: monospace; padding: 30px; background: #f5f5f5; }
    h2   { margin-top: 24px; }
    pre  { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 6px;
           white-space: pre-wrap; word-break: break-all; max-height: 500px; overflow: auto; }
    .ok  { color: #22c55e; font-weight: bold; }
    .bad { color: #ef4444; font-weight: bold; }
</style>
</head>
<body>

<h1>Wupex Product List Test</h1>

<h2>HTTP Status</h2>
<pre class="<?php echo ( $http_code >= 200 && $http_code < 300 ) ? 'ok' : 'bad'; ?>">
<?php echo (int) $http_code; ?>
</pre>

<?php if ( $curl_error ) : ?>
<h2>cURL Error</h2>
<pre class="bad"><?php echo htmlspecialchars( $curl_error ); ?></pre>
<?php endif; ?>

<h2>Response Headers</h2>
<pre><?php echo htmlspecialchars( implode( "\n", array_filter( $resp_headers ) ) ); ?></pre>

<h2>Top-level keys in response</h2>
<pre><?php echo htmlspecialchars( $decoded ? implode( ', ', array_keys( $decoded ) ) : '(not valid JSON)' ); ?></pre>

<?php if ( $decoded && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) : ?>
<h2>Keys inside <code>data</code></h2>
<pre><?php echo htmlspecialchars( implode( ', ', array_keys( $decoded['data'] ) ) ); ?></pre>
<?php endif; ?>

<h2>Full Response (pretty-printed)</h2>
<pre><?php echo htmlspecialchars( $decoded ? json_encode( $decoded, JSON_PRETTY_PRINT ) : $body ); ?></pre>

</body>
</html>
