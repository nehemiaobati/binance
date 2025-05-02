<?php

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector;
use Ratchet\Client\Connector as WsConnector;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

// --- Configuration ---
const BINANCE_API_KEY = 'GNNKQkNBYDst9MZrhWO07yWP7EiOSgJaM8V9BOK4np4QaJPeUlv7dRppj12oWLr6';    // Replace with your API Key
const BINANCE_API_SECRET = 'TJpENspJxvOszkF07knde4xJunlc6bMeUqXN3VcJyX03AtogN2uErRoYHll2hZK0'; // Replace with your API Secret

const REST_API_BASE_URL = 'https://api.binance.com';
const WS_API_BASE_URL = 'wss://stream.binance.com:9443'; // Or wss://stream.binance.com:443

const SYMBOL = 'BTCUSDT'; // Symbol to monitor and trade (lowercase for streams)
const KLINE_INTERVAL = '1s'; // Kline interval for price updates (e.g., 1s, 1m)

// Trading Parameters
const CONVERT_BASE_ASSET = 'BTC'; // Base asset for Convert order
const CONVERT_QUOTE_ASSET = 'USDT'; // Quote asset for Convert order

// Side when price drops below initial_price * (1 - trigger_margin/100)
const ORDER_SIDE_ON_PRICE_DROP = 'BUY'; // BUY or SELL (relative to base asset)
// Side when price rises above initial_price * (1 + trigger_margin/100)
const ORDER_SIDE_ON_PRICE_RISE = 'SELL'; // BUY or SELL (relative to base asset)

// Percentage of available balance of the relevant asset to use for the order
// Relevant asset is QUOTE for BUY side, BASE for SELL side
const AMOUNT_PERCENTAGE = 10; // e.g., 10 means 10%

// Percentage deviation from initial price to trigger an order attempt
const TRIGGER_MARGIN_PERCENT = 0.1; // e.g., 0.1 means 0.1%

// Percentage deviation from CURRENT price to set the Convert LIMIT order price
// e.g., 0.05 means BUY limit price is current_price * (1 - 0.05/100), SELL is current_price * (1 + 0.05/100)
const ORDER_PRICE_MARGIN_PERCENT = 0.05; // e.g., 0.05 means 0.05%

const ORDER_CHECK_INTERVAL_SECONDS = 5; // How often to check the status of an active order
const CANCEL_AFTER_SECONDS = 600; // Automatically cancel order if open for this long (10 minutes)
const MAX_SCRIPT_RUNTIME_SECONDS = 3600; // Maximum script runtime (1 hour)

const API_RECV_WINDOW = 5000; // recvWindow for REST API calls

// --- Global State ---
$loop = Loop::get();
$browser = new Browser($loop);
$initial_base_balance = null;
$initial_quote_balance = null;
$initial_price = null;
$activeOrderId = null; // Stores the ID of the currently active Convert Limit order
$orderPlaceTime = null; // Stores the timestamp when the order was placed

// --- Helper Functions (Asynchronous API Calls) ---

/**
 * Creates signed request data including timestamp, recvWindow, and signature.
 * @param string $endpoint API endpoint path (e.g., '/sapi/v3/asset/getUserAsset')
 * @param array $params Request parameters (will be added to query string)
 * @return array Contains 'url' and 'headers' for makeApiRequest
 */
function createSignedRequestData(string $endpoint, array $params = []): array
{
    $timestamp = round(microtime(true) * 1000);
    $params['timestamp'] = $timestamp;
    $params['recvWindow'] = API_RECV_WINDOW;

    // Sort parameters alphabetically by key
    ksort($params);

    $queryString = http_build_query($params);
    $signature = hash_hmac('sha256', $queryString, BINANCE_API_SECRET);
    $params['signature'] = $signature;

    $url = REST_API_BASE_URL . $endpoint . '?' . http_build_query($params);

    $headers = [
        'X-MBX-APIKEY' => BINANCE_API_KEY,
        'Content-Type' => 'application/x-www-form-urlencoded', // Typically required for POST
    ];

    return [
        'url' => $url,
        'headers' => $headers,
        'postData' => $queryString // For POST, send parameters in the body as x-www-form-urlencoded
    ];
}

/**
 * Makes an asynchronous HTTP request using ReactPHP Browser.
 * @param string $method HTTP method (GET, POST, PUT, DELETE)
 * @param string $url Full URL including query string for GET, base URL for POST/PUT/DELETE
 * @param array $headers Request headers
 * @param string|array $body Request body (string for raw, array for form-urlencoded POST/PUT)
 * @return \React\Promise\PromiseInterface Resolves with response data, rejects on error
 */
function makeAsyncApiRequest(string $method, string $url, array $headers, $body = null): \React\Promise\PromiseInterface
{
    global $browser;

    // For GET requests, params are in the URL query string
    // For POST/PUT requests with createSignedRequestData, params are also in the query string,
    // and the signing data is passed as $body for x-www-form-urlencoded
    if (in_array($method, ['POST', 'PUT', 'DELETE']) && is_string($body) && !empty($body)) {
         // Send the query string in the body for signed POST/PUT requests as x-www-form-urlencoded
         $headers['Content-Type'] = 'application/x-www-form-urlencoded';
         $requestPromise = $browser->request($method, $url, $headers, $body);
    } else {
         $requestPromise = $browser->request($method, $url, $headers);
    }


    return $requestPromise->then(function (ResponseInterface $response) {
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to decode JSON response: " . json_last_error_msg() . " Body: " . $body);
        }

        // Check for Binance API error codes
        if (isset($data['code']) && $data['code'] !== 0) {
             // Specific error handling for known cases like order not found
             if ($data['code'] === -2011 && strpos($data['msg'], 'Unknown order sent') !== false) {
                  // This is expected when trying to cancel an already non-existent order
                 echo date('Y-m-d H:i:s') . " [API] Known Error (Order already non-existent): " . $data['msg'] . PHP_EOL;
                 return $data; // Return the error data so the caller can check for it
             }
            throw new \Exception("Binance API error: " . $data['msg'] . " (Code: " . $data['code'] . ")");
        }

        return $data;
    }, function (\Exception $e) {
        throw new \Exception("HTTP Request failed: " . $e->getMessage());
    });
}


/**
 * Fetches the available ('free') balance for a single specified asset.
 * @param string $asset The asset symbol (e.g., 'BTC')
 * @return \React\Promise\PromiseInterface Resolves with the free balance (float), rejects on error or asset not found
 */
function getSpecificAssetBalance(string $asset): \React\Promise\PromiseInterface
{
    $signedRequestData = createSignedRequestData('/sapi/v3/asset/getUserAsset', ['asset' => $asset]);

    // Note: getUserAsset is a POST request
    return makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
        ->then(function ($data) use ($asset) {
            if (!is_array($data)) {
                 throw new \Exception("Invalid response format for getUserAsset");
            }
            foreach ($data as $assetInfo) {
                if (isset($assetInfo['asset']) && $assetInfo['asset'] === $asset) {
                    echo date('Y-m-d H:i:s') . " [Balance] Fetched free balance for {$asset}: " . $assetInfo['free'] . PHP_EOL;
                    return (float)$assetInfo['free'];
                }
            }
            echo date('Y-m-d H:i:s') . " [Balance] Asset {$asset} not found in balance list." . PHP_EOL;
            // Throwing an exception here makes the .catch() handler work below
            throw new \Exception("Asset {$asset} not found in balance list.");
        })
        ->catch(function (\Exception $e) use ($asset) {
            echo date('Y-m-d H:i:s') . " [Balance Error] Failed to fetch balance for {$asset}: " . $e->getMessage() . PHP_EOL;
            // Return null or a default value in the catch handler if you want the chain to continue
            // Throwing the exception here ensures the parent promise rejects, stopping the initialization sequence
            throw $e;
        });
}


/**
 * Fetches the close price of the most recent Kline.
 * @param string $symbol The trading symbol (e.g., 'BTCUSDT')
 * @param string $interval The kline interval (e.g., '1s')
 * @return \React\Promise\PromiseInterface Resolves with the close price (float), rejects on error
 */
function getLatestKlineClosePrice(string $symbol, string $interval = '1s'): \React\Promise\PromiseInterface
{
    $url = REST_API_BASE_URL . '/api/v3/klines?' . http_build_query([
        'symbol' => strtoupper($symbol), // REST API uses uppercase
        'interval' => $interval,
        'limit' => 1,
    ]);

    // This is a public endpoint, no signature needed
    return makeAsyncApiRequest('GET', $url, [])
        ->then(function ($data) use ($symbol, $interval) {
            if (!is_array($data) || empty($data) || !isset($data[0]) || !is_array($data[0]) || !isset($data[0][4])) {
                throw new \Exception("Invalid response format for klines");
            }
            $closePrice = (float)$data[0][4]; // Kline data: [open_time, open, high, low, close, ...]
            echo date('Y-m-d H:i:s') . " [Kline] Fetched latest close price for {$symbol} ({$interval}): {$closePrice}" . PHP_EOL;
            return $closePrice;
        })
        ->catch(function (\Exception $e) use ($symbol, $interval) {
             echo date('Y-m-d H:i:s') . " [Kline Error] Failed to fetch latest kline price for {$symbol} ({$interval}): " . $e->getMessage() . PHP_EOL;
             throw $e; // Propagate error to stop initialization sequence
        });
}

/**
 * Places a Convert Limit order.
 * @param string $baseAsset Base asset symbol
 * @param string $quoteAsset Quote asset symbol
 * @param string $side Order side ('BUY' or 'SELL' for base asset)
 * @param float $limitPrice The limit price
 * @param float $amount The amount of the asset being spent (quote for BUY, base for SELL)
 * @param string $expiredType Expiry type ('1_D', '3_D', '7_D', '30_D')
 * @return \React\Promise\PromiseInterface Resolves with the order details (including orderId), rejects on error
 */
function placeConvertLimitOrder(string $baseAsset, string $quoteAsset, string $side, float $limitPrice, float $amount, string $expiredType = '7_D'): \React\Promise\PromiseInterface
{
    $endpoint = '/sapi/v1/convert/limit/placeOrder';
    $params = [
        'baseAsset' => $baseAsset,
        'quoteAsset' => $quoteAsset,
        'limitPrice' => sprintf('%.8f', $limitPrice), // Format price for precision
        'side' => $side,
        'expiredType' => $expiredType,
    ];

    // Add the amount based on the side, using the appropriate parameter name
    if ($side === 'BUY') {
        // When buying base, you specify how much quote to spend
        $params['quoteAmount'] = sprintf('%.8f', $amount); // Format amount for precision
    } elseif ($side === 'SELL') {
        // When selling base, you specify how much base to sell
        $params['baseAmount'] = sprintf('%.8f', $amount); // Format amount for precision
    } else {
         return \React\Promise\reject(new \Exception("Invalid order side specified: {$side}"));
    }


    $signedRequestData = createSignedRequestData($endpoint, $params);

    return makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
        ->then(function ($data) use ($baseAsset, $quoteAsset, $side, $limitPrice, $amount) {
            if (isset($data['orderId'])) {
                echo date('Y-m-d H:i:s') . " [Order] Placed Convert Limit {$side} order {$data['orderId']} for "
                    . ($side === 'BUY' ? "{$amount} {$quoteAsset}" : "{$amount} {$baseAsset}")
                    . " at price {$limitPrice}." . PHP_EOL;
                 // TODO: Add minimum order size check based on API response or exchange info
                return $data; // Contains orderId, clientOrderId, etc.
            } else {
                // Even if API returns non-error code, it might not have orderId on failure
                 throw new \Exception("Order placement failed. Response: " . json_encode($data));
            }
        })
        ->catch(function (\Exception $e) {
            echo date('Y-m-d H:i:s') . " [Order Error] Failed to place Convert Limit order: " . $e->getMessage() . PHP_EOL;
            throw $e; // Propagate error
        });
}

/**
 * Queries currently open Convert Limit orders.
 * @return \React\Promise\PromiseInterface Resolves with an array of open orders, rejects on error
 */
function queryConvertOpenOrders(): \React\Promise\PromiseInterface
{
    $endpoint = '/sapi/v1/convert/limit/queryOpenOrders';
    $signedRequestData = createSignedRequestData($endpoint);

    // This is a POST request according to documentation snippet
    return makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
        ->then(function ($data) {
             if (!is_array($data)) {
                 throw new \Exception("Invalid response format for queryOpenOrders");
             }
            // Response is an array of orders
            echo date('Y-m-d H:i:s') . " [Order Check] Fetched " . count($data) . " open Convert Limit orders." . PHP_EOL;
            return $data;
        })
        ->catch(function (\Exception $e) {
            echo date('Y-m-d H:i:s') . " [Order Check Error] Failed to query open orders: " . $e->getMessage() . PHP_EOL;
            throw $e; // Propagate error
        });
}

/**
 * Cancels a specific Convert Limit order.
 * @param string $orderId The ID of the order to cancel
 * @return \React\Promise\PromiseInterface Resolves with cancellation result, rejects on error
 */
function cancelConvertLimitOrder(string $orderId): \React\Promise\PromiseInterface
{
    $endpoint = '/sapi/v1/convert/limit/cancelOrder';
    $params = ['orderId' => $orderId];
    $signedRequestData = createSignedRequestData($endpoint, $params);

    // This is a POST request according to documentation snippet
    return makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
        ->then(function ($data) use ($orderId) {
             // Check if the response indicates success or a known 'already cancelled/filled' state
             if (isset($data['orderId']) && $data['orderId'] === $orderId) {
                 echo date('Y-m-d H:i:s') . " [Order] Successfully cancelled order {$orderId}." . PHP_EOL;
                 return $data;
             } elseif (isset($data['code']) && $data['code'] === -2011) {
                 echo date('Y-m-d H:i:s') . " [Order] Order {$orderId} was already cancelled or filled." . PHP_EOL;
                 return $data; // Treat as success for cancellation purposes
             } else {
                  throw new \Exception("Cancellation response not expected. Response: " . json_encode($data));
             }
        })
        ->catch(function (\Exception $e) use ($orderId) {
            echo date('Y-m-d H:i:s') . " [Order Cancel Error] Failed to cancel order {$orderId}: " . $e->getMessage() . PHP_EOL;
             // Even if cancellation fails, we might want to clear the active order state
             // depending on the error. For this script, we'll clear it anyway to avoid
             // infinite cancel attempts on a persistent API error.
            throw $e; // Propagate error but state might still be cleared by caller
        });
}


// --- Initialization (Async) ---
echo date('Y-m-d H:i:s') . " [Script] Starting initialization..." . PHP_EOL;

\React\Promise\all([
    getSpecificAssetBalance(CONVERT_BASE_ASSET),
    getSpecificAssetBalance(CONVERT_QUOTE_ASSET),
    getLatestKlineClosePrice(SYMBOL, KLINE_INTERVAL),
])->then(function ($results) {
    global $initial_base_balance, $initial_quote_balance, $initial_price, $loop;

    $initial_base_balance = $results[0];
    $initial_quote_balance = $results[1];
    $initial_price = $results[2];

    // Check if initialization was successful
    if ($initial_base_balance === null || $initial_quote_balance === null || $initial_price === null) {
        echo date('Y-m-d H:i:s') . " [Initialization Error] Failed to fetch initial data. Exiting." . PHP_EOL;
        $loop->stop(); // Stop the event loop
        return;
    }

    echo date('Y-m-d H:i:s') . " [Initialization Success]" . PHP_EOL;
    echo "  Initial " . CONVERT_BASE_ASSET . " Balance: " . $initial_base_balance . PHP_EOL;
    echo "  Initial " . CONVERT_QUOTE_ASSET . " Balance: " . $initial_quote_balance . PHP_EOL;
    echo "  Initial Price (" . SYMBOL . "): " . $initial_price . PHP_EOL;

    // --- WebSocket Connection ---
    $wsUrl = WS_API_BASE_URL . '/ws/' . strtolower(SYMBOL) . '@kline_' . KLINE_INTERVAL;
    echo date('Y-m-d H:i:s') . " [WS] Connecting to " . $wsUrl . PHP_EOL;

    $wsConnector = new WsConnector($loop);
    $wsConnector($wsUrl)->then(function ($conn) {
        echo date('Y-m-d H:i:s') . " [WS] Connected successfully." . PHP_EOL;

        $conn->on('message', function ($msg) use ($conn) {
            global $initial_price, $activeOrderId, $orderPlaceTime;

            // Ignore messages if an order is pending or active
            if ($activeOrderId !== null) {
                // echo date('Y-m-d H:i:s') . " [WS] Ignoring message, order {$activeOrderId} is active." . PHP_EOL;
                return;
            }

            $data = json_decode($msg, true);

            // Check for raw kline payload structure
            if (!isset($data['k']['c'])) {
                // echo date('Y-m-d H:i:s') . " [WS] Received non-kline message or invalid kline format: " . $msg . PHP_EOL;
                return;
            }

            $current_price = (float)$data['k']['c'];
            // $is_kline_closed = $data['k']['x']; // Only trigger on closed kline? Prompt says "push updates to the current klines" which implies open klines too. Let's trigger on any update.

            $trigger_price_up = $initial_price * (1 + TRIGGER_MARGIN_PERCENT / 100);
            $trigger_price_down = $initial_price * (1 - TRIGGER_MARGIN_PERCENT / 100);

            $order_side = null;
            $trigger_met = false;

            if ($current_price >= $trigger_price_up) {
                $order_side = ORDER_SIDE_ON_PRICE_RISE;
                $trigger_met = true;
                echo date('Y-m-d H:i:s') . " [WS] Price ({$current_price}) >= Up Trigger ({$trigger_price_up}). Triggering {$order_side}." . PHP_EOL;
            } elseif ($current_price <= $trigger_price_down) {
                $order_side = ORDER_SIDE_ON_PRICE_DROP;
                $trigger_met = true;
                echo date('Y-m-d H:i:s') . " [WS] Price ({$current_price}) <= Down Trigger ({$trigger_price_down}). Triggering {$order_side}." . PHP_EOL;
            }

            if ($trigger_met && $order_side !== null) {
                // Trigger met, place an order
                $relevant_asset = ($order_side === 'BUY') ? CONVERT_QUOTE_ASSET : CONVERT_BASE_ASSET;
                $target_asset = ($order_side === 'BUY') ? CONVERT_BASE_ASSET : CONVERT_QUOTE_ASSET;

                // Fetch current balance before placing order (async)
                getSpecificAssetBalance($relevant_asset)->then(function ($current_relevant_balance) use ($order_side, $current_price, $relevant_asset, $target_asset) {
                    global $activeOrderId, $orderPlaceTime;

                    if ($current_relevant_balance === null) {
                        echo date('Y-m-d H:i:s') . " [Order Prep Error] Could not fetch current balance for {$relevant_asset}. Cannot place order." . PHP_EOL;
                        return; // Stop the chain if balance fetch failed
                    }

                    // Calculate amount and price
                    $amount_to_use = $current_relevant_balance * (AMOUNT_PERCENTAGE / 100);

                    // TODO: Add minimum order size check here before calling place order.
                    // Need to fetch Convert exchange info to get min limits.
                    // For now, rely on API error handling.

                    $limit_price = ($order_side === 'BUY')
                        ? $current_price * (1 - ORDER_PRICE_MARGIN_PERCENT / 100) // Buy lower than current price
                        : $current_price * (1 + ORDER_PRICE_MARGIN_PERCENT / 100); // Sell higher than current price

                    // Place the order (async)
                    placeConvertLimitOrder(CONVERT_BASE_ASSET, CONVERT_QUOTE_ASSET, $order_side, $limit_price, $amount_to_use)->then(function ($orderData) {
                        global $activeOrderId, $orderPlaceTime;
                        // Order placed successfully, update state
                        $activeOrderId = $orderData['orderId'];
                        $orderPlaceTime = time();
                    })->catch(function (\Exception $e) {
                        echo date('Y-m-d H:i:s') . " [Order Placement Chain Error] " . $e->getMessage() . PHP_EOL;
                        // Error during placement - activeOrderId remains null, script continues monitoring
                    });

                })->catch(function (\Exception $e) {
                     echo date('Y-m-d H:i:s') . " [Balance Fetch Chain Error] " . $e->getMessage() . PHP_EOL;
                     // Error during balance fetch - activeOrderId remains null, script continues monitoring
                });
            }

        });

        $conn->on('error', function ($e) use ($conn) {
            echo date('Y-m-d H:i:s') . " [WS Error] " . $e->getMessage() . PHP_EOL;
            global $loop;
            $loop->stop(); // Stop the event loop on WebSocket error
        });

        $conn->on('close', function ($code = null, $reason = null) {
            echo date('Y-m-d H:i:s') . " [WS] Connection closed ({$code} - {$reason})." . PHP_EOL;
            global $loop;
            $loop->stop(); // Stop the event loop on WebSocket close
        });

        // --- Periodic Order Status Check Timer ---
        $loop->addPeriodicTimer(ORDER_CHECK_INTERVAL_SECONDS, function () {
            global $activeOrderId, $orderPlaceTime;

            if ($activeOrderId === null) {
                // echo date('Y-m-d H:i:s') . " [Timer] No active order to check." . PHP_EOL;
                return; // No order active, nothing to do
            }

            echo date('Y-m-d H:i:s') . " [Timer] Checking status for order {$activeOrderId}..." . PHP_EOL;

            queryConvertOpenOrders()->then(function ($openOrders) use ($activeOrderId) {
                global $activeOrderId, $orderPlaceTime;

                $orderStillOpen = false;
                foreach ($openOrders as $order) {
                    if (isset($order['orderId']) && $order['orderId'] == $activeOrderId) {
                        $orderStillOpen = true;
                        break;
                    }
                }

                if (!$orderStillOpen) {
                    echo date('Y-m-d H:i:s') . " [Timer] Order {$activeOrderId} is no longer in open orders list (filled or cancelled elsewhere)." . PHP_EOL;
                    $activeOrderId = null; // Clear state
                    $orderPlaceTime = null; // Clear state
                } else {
                    // Order is still open, check cancellation timeout
                    $elapsedTime = time() - $orderPlaceTime;
                    echo date('Y-m-d H:i:s') . " [Timer] Order {$activeOrderId} still open. Elapsed time: {$elapsedTime}s." . PHP_EOL;

                    if ($elapsedTime >= CANCEL_AFTER_SECONDS) {
                        echo date('Y-m-d H:i:s') . " [Timer] Cancellation timeout reached for order {$activeOrderId}." . PHP_EOL;
                        cancelConvertLimitOrder($activeOrderId)->then(function ($result) {
                            // Cancellation attempt successful (even if order was already non-existent)
                            global $activeOrderId, $orderPlaceTime;
                            $activeOrderId = null; // Clear state after attempt
                            $orderPlaceTime = null; // Clear state after attempt
                        })->catch(function (\Exception $e) {
                             // Cancellation failed - state will be cleared anyway to prevent retrying same cancel
                             global $activeOrderId, $orderPlaceTime;
                             $activeOrderId = null;
                             $orderPlaceTime = null;
                             echo date('Y-m-d H:i:s') . " [Timer] Error during cancellation chain: " . $e->getMessage() . PHP_EOL;
                        });
                    }
                }
            })->catch(function (\Exception $e) {
                 // Error querying orders - state remains, will try again next timer interval
                 echo date('Y-m-d H:i:s') . " [Timer Error] Failed to query open orders: " . $e->getMessage() . PHP_EOL;
            });
        });

        // --- Script Termination Timer ---
        $loop->addTimer(MAX_SCRIPT_RUNTIME_SECONDS, function () {
            echo date('Y-m-d H:i:s') . " [Script] Maximum runtime reached. Stopping script." . PHP_EOL;
            global $activeOrderId, $conn, $loop;

            if ($activeOrderId !== null) {
                 echo date('Y-m-d H:i:s') . " [Script] Note: Order {$activeOrderId} was still active when script stopped." . PHP_EOL;
                 // Decide if you want to attempt cancellation here or leave it open
                 // For simplicity, we just stop. To cancel, you'd need another async chain here.
            }

            if ($conn->isOpen()) {
                 $conn->close(); // Close WebSocket connection gracefully
            }
            $loop->stop(); // Stop the event loop
        });


    }, function ($e) {
        echo date('Y-m-d H:i:s') . " [WS Error] Could not connect: " . $e->getMessage() . PHP_EOL;
        global $loop;
        $loop->stop(); // Stop the event loop if WebSocket connection fails
    });

}, function (\Exception $e) {
    echo date('Y-m-d H:i:s') . " [Initialization Error] Script stopped due to initialization failure: " . $e->getMessage() . PHP_EOL;
     // Error handled by the catch in getSpecificAssetBalance or getLatestKlineClosePrice, loop already stopped.
});


// --- Start the ReactPHP Event Loop ---
echo date('Y-m-d H:i:s') . " [Script] Starting event loop..." . PHP_EOL;
$loop->run();
echo date('Y-m-d H:i:s') . " [Script] Event loop finished." . PHP_EOL;

?>