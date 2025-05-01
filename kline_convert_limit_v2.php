<?php

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;

// --- Configuration ---

// -- Binance API Credentials --
define('BINANCE_API_KEY', 'GNNKQkNBYDst9MZrhWO07yWP7EiOSgJaM8V9BOK4np4QaJPeUlv7dRppj12oWLr6'); // <-- REPLACE
define('BINANCE_API_SECRET', 'TJpENspJxvOszkF07knde4xJunlc6bMeUqXN3VcJyX03AtogN2uErRoYHll2hZK0'); // <-- REPLACE
define('BINANCE_API_BASE_URL', 'https://api.binance.com');

// -- WebSocket Stream Config --
$base_wss_url = 'wss://stream.binance.com:9443/ws/';
$symbol = 'BTCUSDT'; // Trading pair for Klines
$interval = '1s';    // Kline interval
$stream_name = strtolower($symbol) . '@kline_' . $interval;

// -- Conversion & Trading Config --
$config = [
    'convert_base_asset' => 'BTC',   // Asset being bought/sold (matches base of $symbol)
    'convert_quote_asset' => 'USDT', // Asset used to buy/sell (matches quote of $symbol)
    'order_side_on_price_drop' => 'BUY', // Action when price drops below initial (BUY base / SELL base)
    'order_side_on_price_rise' => 'SELL', // Optional: Action if price RISES above initial
    'amount_percentage' => 5.0,      // Percentage of AVAILABLE asset balance to use (e.g., 5.0 for 5%)
                                     // IMPORTANT: Ensure resulting amount meets minimum Convert order size!
    'trigger_margin' => 0.0001,       // 0.1% margin below/above INITIAL price to trigger order
    'order_price_margin' => 0.0001,  // 0.05% margin below/above CURRENT price for the limit order itself
    'check_interval' => 5.0,         // Seconds between checking open order status
    'cancel_after_seconds' => 30,    // Cancel the order if still open after this many seconds
    'max_script_runtime' => 180      // Maximum total time (seconds) for the script to run
];

// -- Global State Variables --
$initialBalances = [
    $config['convert_base_asset'] => null,
    $config['convert_quote_asset'] => null,
];
$initialClosePrice = null;
$activeOrderId = null;
$orderPlaceTime = null;
$websocketConnection = null; // Hold the connection object

// --- Helper Functions for Binance REST API ---

function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
{
    if (BINANCE_API_KEY === 'YOUR_BINANCE_API_KEY' || BINANCE_API_SECRET === 'YOUR_BINANCE_API_SECRET') {
        throw new \Exception("API Key or Secret not configured.");
    }
    $params['timestamp'] = floor(microtime(true) * 1000);
    $params['recvWindow'] = 5000; // Often helpful

    $queryString = http_build_query($params);
    $signature = hash_hmac('sha256', $queryString, BINANCE_API_SECRET);
    $params['signature'] = $signature;

    $url = BINANCE_API_BASE_URL . $endpoint . '?' . http_build_query($params);
    $headers = [
        'X-MBX-APIKEY: ' . BINANCE_API_KEY,
        'Accept: application/json',
    ];
    return ['url' => $url, 'headers' => $headers];
}

function makeApiRequest(string $method, string $url, array $headers, array $postData = []): array
{
    // Use a new cURL handle each time to avoid state issues in async context
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FRESH_CONNECT => true, // Try to force new connection
        CURLOPT_FORBID_REUSE => true,  // Prevent connection reuse
    ]);

    // POST data handling remains the same (likely unused for these endpoints)
    if (strtoupper($method) === 'POST' && !empty($postData)) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    curl_close($curl); // Close handle immediately

    if ($curlErrno > 0) {
        return ['error' => true, 'code' => $curlErrno, 'message' => "cURL Error: " . $curlError];
    }
    $decodedResponse = json_decode($response, true);
    if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => true, 'code' => $httpCode, 'message' => "JSON Decode Error: " . json_last_error_msg(), 'raw' => $response];
    }
    if ($httpCode >= 400 || (isset($decodedResponse['code']) && $decodedResponse['code'] < 0 && $decodedResponse['code'] !== -2011 /* Ignore 'Unknown order sent.' for cancel*/)) {
        $errMsg = $decodedResponse['msg'] ?? $decodedResponse['message'] ?? 'Unknown API Error';
        $errCode = $decodedResponse['code'] ?? $httpCode;
        return ['error' => true, 'code' => $errCode, 'message' => "API Error: " . $errMsg, 'response' => $decodedResponse];
    }
    return $decodedResponse;
}

// --- Specific API Functions ---

function getSpecificAssetBalance(string $asset): ?float {
    echo "[API] Fetching balance for {$asset}...\n";
    $endpoint = '/sapi/v3/asset/getUserAsset';
    $params = ['asset' => $asset];
    try {
        $requestData = createSignedRequestData($endpoint, $params, 'POST'); // Endpoint uses POST
        $result = makeApiRequest('POST', $requestData['url'], $requestData['headers']);

        if (!isset($result['error']) && is_array($result)) {
            // Response is an array, even for one asset
            foreach ($result as $assetInfo) {
                if (isset($assetInfo['asset']) && $assetInfo['asset'] === $asset && isset($assetInfo['free'])) {
                    echo "[API] Available {$asset} balance: {$assetInfo['free']}\n";
                    return (float)$assetInfo['free'];
                }
            }
             echo "[API] {$asset} not found in balance response or 'free' field missing.\n";
             return 0.0; // Assume zero if not found
        } elseif (isset($result['error'])) {
            echo "[API] Error fetching balance for {$asset}: [{$result['code']}] {$result['message']}\n";
        } else {
            echo "[API] Unexpected response fetching balance for {$asset}: " . json_encode($result). "\n";
        }
    } catch (\Exception $e) {
        echo "[API] Exception fetching balance for {$asset}: " . $e->getMessage() . "\n";
    }
    return null; // Indicate error or inability to fetch
}

function getLatestKlineClosePrice(string $symbol, string $interval = '1s'): ?float {
     echo "[API] Fetching latest Kline close price for {$symbol}...\n";
     $endpoint = '/api/v3/klines';
     $params = [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => 1
     ];
     // This is a public endpoint, no signing needed
     $url = BINANCE_API_BASE_URL . $endpoint . '?' . http_build_query($params);
     $headers = ['Accept: application/json']; // Standard header

     try {
        // Using makeApiRequest structure for consistency, though signing isn't used
        $result = makeApiRequest('GET', $url, $headers);

        if (!isset($result['error']) && is_array($result) && !empty($result) && isset($result[0][4])) {
            $closePrice = (float)$result[0][4]; // Index 4 is the close price
            echo "[API] Latest {$symbol} close price: {$closePrice}\n";
            return $closePrice;
        } elseif (isset($result['error'])) {
             echo "[API] Error fetching latest Kline for {$symbol}: [{$result['code']}] {$result['message']}\n";
        } else {
             echo "[API] Unexpected response fetching latest Kline for {$symbol}: " . json_encode($result) . "\n";
        }

     } catch(\Exception $e) {
        echo "[API] Exception fetching latest Kline: " . $e->getMessage() . "\n";
     }
     return null;
}


function placeConvertLimitOrder(string $baseAsset, string $quoteAsset, string $side, float $limitPrice, float $amount, string $expiredType = '7_D'): array
{
    echo "[API] Attempting to place Convert Limit Order (Side: {$side}, Price: {$limitPrice}, Amount: {$amount})...\n";
    $endpoint = '/sapi/v1/convert/limit/placeOrder';
    $params = [
        'baseAsset' => $baseAsset,
        'quoteAsset' => $quoteAsset,
        'limitPrice' => sprintf('%.8f', $limitPrice),
        'side' => $side,
        'expiredType' => $expiredType,
    ];

    // Determine amount parameter based on side
    if ($side === 'BUY') {
        $params['quoteAmount'] = sprintf('%.8f', $amount); // Amount is QUOTE asset to spend
    } elseif ($side === 'SELL') {
        $params['baseAmount'] = sprintf('%.8f', $amount); // Amount is BASE asset to sell
    } else {
         return ['error' => true, 'message' => 'Invalid order side specified'];
    }

    try {
        $requestData = createSignedRequestData($endpoint, $params, 'POST');
        $result = makeApiRequest('POST', $requestData['url'], $requestData['headers']);
        // Log result inside the function
        if (!isset($result['error']) && isset($result['orderId'])) {
            echo "[API] Successfully placed Convert Limit Order ID: {$result['orderId']}\n";
        } elseif (isset($result['error'])) {
             echo "[API] Error placing Convert Limit Order: [{$result['code']}] {$result['message']}\n";
        } else {
            echo "[API] Unexpected response placing Convert Limit Order: " . json_encode($result) . "\n";
        }
        return $result;
    } catch (\Exception $e) {
        echo "[API] Exception placing Convert Limit Order: " . $e->getMessage() . "\n";
        return ['error' => true, 'message' => $e->getMessage()];
    }
}

function queryConvertOpenOrders(): array
{
    // echo "[API] Querying open Convert Limit Orders...\n"; // Reduce noise
    $endpoint = '/sapi/v1/convert/limit/queryOpenOrders';
    try {
        $requestData = createSignedRequestData($endpoint, [], 'POST');
        return makeApiRequest('POST', $requestData['url'], $requestData['headers']);
    } catch (\Exception $e) {
        echo "[API] Exception querying open orders: " . $e->getMessage() . "\n";
        return ['error' => true, 'message' => $e->getMessage()];
    }
}

function cancelConvertLimitOrder(string $orderId): array
{
    echo "[API] Attempting to cancel Convert Limit Order ID: {$orderId}\n";
    $endpoint = '/sapi/v1/convert/limit/cancelOrder';
    $params = ['orderId' => $orderId];
    try {
        $requestData = createSignedRequestData($endpoint, $params, 'POST');
        $result = makeApiRequest('POST', $requestData['url'], $requestData['headers']);
        // Log result inside the function
        if (!isset($result['error']) && isset($result['orderId'])) {
             echo "[API] Successfully initiated cancel for order ID: {$result['orderId']} Status: {$result['status']}\n";
        } elseif (isset($result['error']) && ($result['code'] === -2011 || $result['code'] === -1013)) { // Unknown order or Filter failure (often means already filled/cancelled)
             echo "[API] Cancel failed or unnecessary: Order ID {$orderId} likely already closed/cancelled.\n";
        } elseif (isset($result['error'])) {
            echo "[API] Error cancelling order {$orderId}: [{$result['code']}] {$result['message']}\n";
        } else {
             echo "[API] Unexpected response cancelling order {$orderId}: " . json_encode($result) . "\n";
        }
        return $result;
    } catch (\Exception $e) {
        echo "[API] Exception cancelling order {$orderId}: " . $e->getMessage() . "\n";
        return ['error' => true, 'message' => $e->getMessage()];
    }
}

// --- Main Application Logic ---

$loop = Loop::get();

echo "=== Binance Kline Stream & Convert Limit Order Demo V2 ===\n";

// 1. Fetch Initial State (Synchronously before starting loop)
echo "[SYSTEM] Fetching initial state...\n";
$initialBalances[$config['convert_base_asset']] = getSpecificAssetBalance($config['convert_base_asset']);
$initialBalances[$config['convert_quote_asset']] = getSpecificAssetBalance($config['convert_quote_asset']);
$initialClosePrice = getLatestKlineClosePrice($symbol, $interval);

if ($initialClosePrice === null || $initialBalances[$config['convert_base_asset']] === null || $initialBalances[$config['convert_quote_asset']] === null) {
    echo "[SYSTEM] ERROR: Failed to fetch initial state (balance or price). Exiting.\n";
    exit(1);
}

echo "[SYSTEM] Initial State Fetched:\n";
echo "  - {$config['convert_base_asset']} Balance: " . $initialBalances[$config['convert_base_asset']] . "\n";
echo "  - {$config['convert_quote_asset']} Balance: " . $initialBalances[$config['convert_quote_asset']] . "\n";
echo "  - Initial {$symbol} Close Price: {$initialClosePrice}\n";
echo "=======================================================\n";


$connector = new Connector($loop);

echo "[SYSTEM] Connecting to WebSocket: " . $base_wss_url . $stream_name . "\n";

$connector($base_wss_url . $stream_name)
    ->then(function(WebSocket $conn) use ($loop, $config) {
        global $activeOrderId, $orderPlaceTime, $initialClosePrice, $initialBalances, $websocketConnection; // Allow access/modification

        $websocketConnection = $conn; // Store connection globally
        echo "[WSS] Connection Established!\n";

        // --- WebSocket Message Handler ---
        $conn->on('message', function(MessageInterface $msg) use ($config, &$activeOrderId, &$orderPlaceTime, $initialClosePrice) {
            if ($activeOrderId !== null) return; // Don't process if an order is already active

            $payload = (string) $msg;
            $data = json_decode($payload, true);

            if (isset($data['e']) && $data['e'] === 'kline' && isset($data['k']['c'])) {
                $kline = $data['k'];
                $currentClosePrice = (float) $kline['c'];
                $eventTime = date('Y-m-d H:i:s', $data['E'] / 1000) . '.' . sprintf('%03d', $data['E'] % 1000);

                 echo "[WSS] {$eventTime} - Close: {$currentClosePrice} (Initial: {$initialClosePrice})\n";

                // --- Trigger Logic ---
                $triggerPrice = null;
                $orderSide = null;
                $placeOrder = false;

                // Check for price drop condition
                $dropTriggerPrice = $initialClosePrice * (1 - $config['trigger_margin']);
                if ($currentClosePrice < $dropTriggerPrice) {
                     if (isset($config['order_side_on_price_drop'])) {
                        $orderSide = $config['order_side_on_price_drop'];
                        $placeOrder = true;
                        echo "[LOGIC] Price drop detected ({$currentClosePrice} < {$dropTriggerPrice}). Preparing {$orderSide} order.\n";
                     }
                }

                // Check for price rise condition (optional)
                if (!$placeOrder && isset($config['order_side_on_price_rise'])) {
                    $riseTriggerPrice = $initialClosePrice * (1 + $config['trigger_margin']);
                     if ($currentClosePrice > $riseTriggerPrice) {
                        $orderSide = $config['order_side_on_price_rise'];
                        $placeOrder = true;
                        echo "[LOGIC] Price rise detected ({$currentClosePrice} > {$riseTriggerPrice}). Preparing {$orderSide} order.\n";
                    }
                }

                // --- Place the Order (Only Once) ---
                if ($placeOrder && $activeOrderId === null && $orderSide !== null) {

                    // **Calculate Amount based on Percentage and CURRENT Balance**
                    $amountToUse = 0.0;
                    $assetForBalanceCheck = null;
                    if ($orderSide === 'BUY') {
                        $assetForBalanceCheck = $config['convert_quote_asset']; // Use quote asset balance
                    } elseif ($orderSide === 'SELL') {
                        $assetForBalanceCheck = $config['convert_base_asset']; // Use base asset balance
                    }

                    if ($assetForBalanceCheck) {
                        $currentAvailableBalance = getSpecificAssetBalance($assetForBalanceCheck); // Fetch current balance
                        if ($currentAvailableBalance !== null && $currentAvailableBalance > 0) {
                             $amountToUse = ($currentAvailableBalance * $config['amount_percentage']) / 100.0;
                             echo "[LOGIC] Calculated amount to use: {$amountToUse} {$assetForBalanceCheck} ({$config['amount_percentage']}% of {$currentAvailableBalance})\n";
                             // !!! Add check here for minimum order size required by Binance Convert API !!!
                             // If $amountToUse is too small, set $placeOrder = false; echo warning.
                        } else {
                             echo "[LOGIC] Warning: Could not fetch or zero available balance for {$assetForBalanceCheck}. Cannot calculate order amount.\n";
                             $placeOrder = false; // Cannot place order
                        }
                    } else {
                         $placeOrder = false; // Should not happen if orderSide is BUY/SELL
                    }

                    // **Calculate Target Limit Price**
                    $targetLimitPrice = null;
                     if ($placeOrder && $orderSide === 'BUY') {
                         $targetLimitPrice = $currentClosePrice * (1 - $config['order_price_margin']); // Place slightly below current
                     } elseif ($placeOrder && $orderSide === 'SELL') {
                         $targetLimitPrice = $currentClosePrice * (1 + $config['order_price_margin']); // Place slightly above current
                     }

                    // **Execute Placement**
                    if ($placeOrder && $targetLimitPrice !== null && $amountToUse > 0) {
                         echo "[LOGIC] Placing {$orderSide} limit order at price: {$targetLimitPrice} using amount {$amountToUse}\n";
                         $result = placeConvertLimitOrder(
                             $config['convert_base_asset'],
                             $config['convert_quote_asset'],
                             $orderSide,
                             $targetLimitPrice,
                             $amountToUse // Use the calculated percentage-based amount
                         );

                         if (!isset($result['error']) && isset($result['orderId'])) {
                             $activeOrderId = $result['orderId']; // Store the ID
                             $orderPlaceTime = time();            // Record placement time
                             echo "[LOGIC] Order Placed. Active Order ID: {$activeOrderId}. Monitoring...\n";
                         } else {
                             echo "[LOGIC] Failed to place order. Will check price again.\n";
                             $activeOrderId = null; // Allow retry
                             $orderPlaceTime = null;
                         }
                     } elseif($placeOrder) {
                         echo "[LOGIC] Order placement skipped due to zero amount or missing limit price.\n";
                     }
                }
            }
            // Handle other message types like ping/pong if necessary
        });

        // --- WebSocket Close Handler ---
        $conn->on('close', function($code = null, $reason = null) use ($loop) {
            echo "[WSS] Connection closed (Code: {$code}" . ($reason ? ", Reason: {$reason}" : '') . ")\n";
            $loop->stop(); // Stop the loop
        });

        // --- WebSocket Error Handler ---
        $conn->on('error', function(\Exception $e) use ($loop) {
            echo "[WSS] Error: {$e->getMessage()}\n";
            $loop->stop(); // Stop on error
        });

    }, function(\Exception $e) use ($loop) { // --- Connection Error Handler ---
        echo "[SYSTEM] Could not connect to WebSocket: {$e->getMessage()}\n";
        $loop->stop();
    });


// --- Periodic Timer for Order Checking/Cancelling ---
$loop->addPeriodicTimer($config['check_interval'], function() use ($loop, $config) {
    global $activeOrderId, $orderPlaceTime; // Read global state

    if ($activeOrderId === null) return; // Nothing to do

    // Reduced logging noise for timer checks
    // echo "[TIMER] Checking status for Order ID: {$activeOrderId}\n";

    $openOrdersResult = queryConvertOpenOrders();
    $orderIsOpen = false;

    if (!isset($openOrdersResult['error']) && isset($openOrdersResult['list']) && is_array($openOrdersResult['list'])) {
        foreach ($openOrdersResult['list'] as $order) {
            if (isset($order['orderId']) && $order['orderId'] == $activeOrderId) {
                $orderIsOpen = true;
                // echo "[TIMER] Order {$activeOrderId} is still OPEN.\n"; // Verbose
                break;
            }
        }
        if (!$orderIsOpen && $activeOrderId !== null) { // Check activeOrderId again in case it was cleared concurrently
             echo "[TIMER] Order {$activeOrderId} no longer found in open orders. Clearing state.\n";
             $activeOrderId = null;
             $orderPlaceTime = null;
        }
    } elseif (isset($openOrdersResult['error'])) {
         echo "[TIMER] Error checking open orders: [{$openOrdersResult['code']}] {$openOrdersResult['message']}\n";
         // Continue checking next time, maybe temporary API issue
    }

    // --- Cancel Order if Open and Timed Out ---
    if ($orderIsOpen && $activeOrderId !== null && $orderPlaceTime !== null) {
        $elapsed = time() - $orderPlaceTime;
        if ($elapsed >= $config['cancel_after_seconds']) {
            echo "[TIMER] Order {$activeOrderId} open for {$elapsed}s (>= {$config['cancel_after_seconds']}s). Attempting cancellation.\n";
            cancelConvertLimitOrder($activeOrderId); // Attempt cancellation
            $activeOrderId = null; // Assume cancelled or will be soon, stop tracking
            $orderPlaceTime = null;
        } else {
            // echo "[TIMER] Order {$activeOrderId} open for {$elapsed}s. Waiting...\n"; // Verbose
        }
    }
});

// --- Timer to Stop the Script After Max Runtime ---
$loop->addTimer($config['max_script_runtime'], function() use ($loop) {
    global $websocketConnection;
    echo "[SYSTEM] Maximum script runtime ({$config['max_script_runtime']}s) reached. Stopping.\n";
    if ($websocketConnection instanceof WebSocket) {
        echo "[SYSTEM] Closing WebSocket connection gracefully...\n";
        $websocketConnection->close(); // Attempt graceful close
    }
    // Add a short delay before forceful stop, allowing close frame to send
    $loop->addTimer(0.5, function() use ($loop) {
         $loop->stop();
    });
});


// --- Start the Event Loop ---
echo "[SYSTEM] Starting event loop...\n";
$loop->run();

echo "[SYSTEM] Script finished.\n";

?>