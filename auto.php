<?php

declare(strict_types=1); // Enable strict types for better code quality

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as SocketConnector; // Keep for potential future use, though Browser handles HTTP
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket; // Type hint for connection
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// --- Configuration Loading ---
// ** IMPORTANT: Use Environment Variables for API Keys! **
// Example: export BINANCE_API_KEY='your_key'
//          export BINANCE_API_SECRET='your_secret'
$apiKey = getenv('BINANCE_API_KEY') ?: 'NqgRju8D4Hqexr9ZBsbO0Ua4F2MiPzLshm7pCCBBGkbEgzj7yakorkadbPBX6UQF'; // Replace fallback or ensure env var is set
$apiSecret = getenv('BINANCE_API_SECRET') ?: 'ue8NGUVZMTTNT8FcgT0bgSuCR6AfoFtEbmxfK7nIjHbePuWNW07CreuNAul5Yjeg'; // Replace fallback or ensure env var is set

if ($apiKey === 'YOUR_DEFAULT_KEY_FALLBACK' || $apiSecret === 'YOUR_DEFAULT_SECRET_FALLBACK') {
    die("Error: Binance API Key or Secret not configured. Please set BINANCE_API_KEY and BINANCE_API_SECRET environment variables.\n");
}

// --- Trading Bot Class ---

class TradingBot
{
    // --- Constants ---
    private const REST_API_BASE_URL = 'https://api.binance.com';
    private const WS_API_BASE_URL = 'wss://stream.binance.com:9443'; // Or wss://stream.binance.com:443
    private const API_RECV_WINDOW = 5000;

    // --- Configuration Properties ---
    private string $apiKey;
    private string $apiSecret;
    private string $symbol;
    private string $klineInterval;
    private string $convertBaseAsset;
    private string $convertQuoteAsset;
    private string $orderSideOnPriceDrop;
    private string $orderSideOnPriceRise;
    private float $amountPercentage;
    private float $triggerMarginPercent;
    private float $orderPriceMarginPercent;
    private int $orderCheckIntervalSeconds;
    private int $cancelAfterSeconds;
    private int $maxScriptRuntimeSeconds;

    // --- Dependencies ---
    private LoopInterface $loop;
    private Browser $browser;
    private Logger $logger;
    private ?WebSocket $wsConnection = null; // Store WS connection

    // --- State Properties ---
    private ?float $initialBaseBalance = null;
    private ?float $initialQuoteBalance = null;
    private ?float $initialPrice = null;
    private ?string $activeOrderId = null; // Stores the ID of the currently active Convert Limit order
    private ?int $orderPlaceTime = null; // Stores the timestamp when the order was placed
    private bool $isPlacingOrder = false; // Flag to prevent multiple order attempts concurrently

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $symbol,
        string $klineInterval,
        string $convertBaseAsset,
        string $convertQuoteAsset,
        string $orderSideOnPriceDrop,
        string $orderSideOnPriceRise,
        float $amountPercentage,
        float $triggerMarginPercent,
        float $orderPriceMarginPercent,
        int $orderCheckIntervalSeconds,
        int $cancelAfterSeconds,
        int $maxScriptRuntimeSeconds
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->symbol = $symbol;
        $this->klineInterval = $klineInterval;
        $this->convertBaseAsset = $convertBaseAsset;
        $this->convertQuoteAsset = $convertQuoteAsset;
        $this->orderSideOnPriceDrop = $orderSideOnPriceDrop;
        $this->orderSideOnPriceRise = $orderSideOnPriceRise;
        $this->amountPercentage = $amountPercentage;
        $this->triggerMarginPercent = $triggerMarginPercent;
        $this->orderPriceMarginPercent = $orderPriceMarginPercent;
        $this->orderCheckIntervalSeconds = $orderCheckIntervalSeconds;
        $this->cancelAfterSeconds = $cancelAfterSeconds;
        $this->maxScriptRuntimeSeconds = $maxScriptRuntimeSeconds;

        $this->loop = Loop::get();
        $this->browser = new Browser($this->loop);

        // Setup Logger (Monolog)
        $logFormat = "[%datetime%] [%level_name%] %message% %context% %extra%\n";
        $formatter = new LineFormatter($logFormat, 'Y-m-d H:i:s', true, true);
        // Log to standard output (stdout)
        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG); // Set minimum log level (e.g., DEBUG, INFO)
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('TradingBot');
        $this->logger->pushHandler($streamHandler);

        $this->logger->info('TradingBot instance created.');
    }

    /**
     * Runs the trading bot's main logic.
     */
    public function run(): void
    {
        $this->logger->info('Starting initialization...');

        \React\Promise\all([
            $this->getSpecificAssetBalance($this->convertBaseAsset),
            $this->getSpecificAssetBalance($this->convertQuoteAsset),
            $this->getLatestKlineClosePrice($this->symbol, $this->klineInterval),
        ])->then(
            function ($results) {
                $this->initialBaseBalance = (float)$results[0];
                $this->initialQuoteBalance = (float)$results[1];
                $this->initialPrice = (float)$results[2];

                if ($this->initialPrice === null || $this->initialPrice <= 0) {
                    throw new \RuntimeException("Failed to fetch a valid initial price.");
                }

                $this->logger->info('Initialization Success', [
                    'initial_' . $this->convertBaseAsset . '_balance' => $this->initialBaseBalance,
                    'initial_' . $this->convertQuoteAsset . '_balance' => $this->initialQuoteBalance,
                    'initial_price_' . $this->symbol => $this->initialPrice,
                ]);

                $this->connectWebSocket();
                $this->setupTimers();
            },
            function (\Throwable $e) {
                $this->logger->error('Initialization failed', ['exception' => $e->getMessage()]);
                $this->stop();
            }
        );

        $this->logger->info('Starting event loop...');
        $this->loop->run();
        $this->logger->info('Event loop finished.');
    }

    /**
     * Stops the event loop and performs cleanup.
     */
    private function stop(): void
    {
        $this->logger->info('Stopping event loop...');
        if ($this->wsConnection && method_exists($this->wsConnection, 'close')) {
             $this->logger->debug('Closing WebSocket connection...');
             try {
                $this->wsConnection->close();
             } catch (\Exception $e) {
                // Ignore errors during close, maybe already closed
                $this->logger->debug('Exception during WebSocket close (ignoring)', ['exception' => $e->getMessage()]);
             }
        }
        $this->loop->stop();
    }

    /**
     * Connects to the Binance WebSocket stream.
     */
    private function connectWebSocket(): void
    {
        $wsUrl = self::WS_API_BASE_URL . '/ws/' . strtolower($this->symbol) . '@kline_' . $this->klineInterval;
        $this->logger->info('Connecting to WebSocket', ['url' => $wsUrl]);

        $wsConnector = new WsConnector($this->loop);
        $wsConnector($wsUrl)->then(
            function (WebSocket $conn) { // Use type hint
                $this->wsConnection = $conn; // Store the connection
                $this->logger->info('WebSocket connected successfully.');

                $conn->on('message', function ($msg) {
                    $this->handleWsMessage((string)$msg);
                });

                $conn->on('error', function (\Throwable $e) {
                    $this->logger->error('WebSocket error', ['exception' => $e->getMessage()]);
                    $this->stop();
                });

                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    // Decide if you want to stop the bot or attempt reconnection
                    $this->stop(); // Simple stop for now
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception' => $e->getMessage()]);
                $this->stop();
            }
        );
    }

    /**
     * Sets up periodic timers for order checks and script termination.
     */
    private function setupTimers(): void
    {
        // Periodic Order Status Check
        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            // Only check if an order ID exists and we are not currently trying to place one
            if ($this->activeOrderId !== null && !$this->isPlacingOrder) {
                 $this->checkActiveOrderStatus();
            }
        });
        $this->logger->info('Order check timer started', ['interval_seconds' => $this->orderCheckIntervalSeconds]);


        // Script Termination Timer
        $this->loop->addTimer($this->maxScriptRuntimeSeconds, function () {
            $this->logger->warning('Maximum script runtime reached. Stopping script.', [
                'max_runtime_seconds' => $this->maxScriptRuntimeSeconds
            ]);
             if ($this->activeOrderId !== null) {
                 $this->logger->warning('Order was still active upon script termination', ['orderId' => $this->activeOrderId]);
                 // Optionally attempt cancellation here if required by adding another async call chain
                 // For simplicity, we just log and stop.
            }
            $this->stop();
        });
        $this->logger->info('Max runtime timer started', ['limit_seconds' => $this->maxScriptRuntimeSeconds]);

    }

    /**
     * Handles incoming WebSocket messages.
     * @param string $msg The raw message string.
     */
    private function handleWsMessage(string $msg): void
    {
        // Ignore messages if an order is pending, active, or currently being placed
        if ($this->activeOrderId !== null || $this->isPlacingOrder) {
            // $this->logger->debug('Ignoring WS message, order check/placement active.'); // Can be noisy
            return;
        }

        $data = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Failed to decode WebSocket message JSON', ['message' => substr($msg, 0, 100)]);
            return;
        }

        // Check for valid kline data and if the kline is closed
        if (
            !isset($data['e']) || $data['e'] !== 'kline' || // Check event type
            !isset($data['k']['c']) ||                     // Check close price exists
            !isset($data['k']['x']) || $data['k']['x'] !== true // Check kline is closed
           ) {
             // $this->logger->debug('Ignoring non-closed kline or invalid message structure.'); // Can be noisy
             return;
        }

        $current_price = (float)$data['k']['c'];
        // $this->logger->debug('Received kline update', ['price' => $current_price]); // Can be noisy

        if ($this->initialPrice === null) {
             $this->logger->warning('Initial price not set, cannot evaluate triggers.');
             return; // Should not happen after initialization success, but safety check
        }

        $trigger_price_up = $this->initialPrice * (1 + $this->triggerMarginPercent / 100);
        $trigger_price_down = $this->initialPrice * (1 - $this->triggerMarginPercent / 100);

        $order_side = null;
        $trigger_met = false;

        if ($current_price >= $trigger_price_up) {
            $order_side = $this->orderSideOnPriceRise;
            $trigger_met = true;
            $this->logger->info('Price rise trigger met', [
                'current_price' => $current_price,
                'trigger_price_up' => $trigger_price_up,
                'order_side' => $order_side
            ]);
        } elseif ($current_price <= $trigger_price_down) {
            $order_side = $this->orderSideOnPriceDrop;
            $trigger_met = true;
             $this->logger->info('Price drop trigger met', [
                'current_price' => $current_price,
                'trigger_price_down' => $trigger_price_down,
                'order_side' => $order_side
            ]);
        }

        if ($trigger_met && $order_side !== null) {
            $this->attemptPlaceOrder($order_side, $current_price);
        }
    }

    /**
     * Attempts to place a convert limit order after a trigger is met.
     * Handles fetching balance, calculating amount/price, and placing the order.
     * @param string $orderSide 'BUY' or 'SELL'
     * @param float $currentPrice The price that triggered the order.
     */
    private function attemptPlaceOrder(string $orderSide, float $currentPrice): void
    {
        if ($this->isPlacingOrder) {
            $this->logger->warning('Attempted to place order while another placement was in progress.');
            return;
        }
        $this->isPlacingOrder = true; // Set flag
        $this->logger->info('Order trigger met. Initiating order placement process...', ['side' => $orderSide]);

        $relevant_asset = ($orderSide === 'BUY') ? $this->convertQuoteAsset : $this->convertBaseAsset;

        $this->getSpecificAssetBalance($relevant_asset)
        ->then(function (float $current_relevant_balance) use ($orderSide, $currentPrice, $relevant_asset) {

            // Calculate amount to use
            // IMPORTANT: This assumes AMOUNT_PERCENTAGE is of the *available* balance.
            $amount_to_use = $current_relevant_balance * ($this->amountPercentage / 100);

             // Basic check for sufficient amount.
             // TODO: Implement minimum amount/notional checks based on /sapi/v1/convert/exchangeInfo
             if ($amount_to_use <= 0) {
                 $this->logger->warning('Calculated amount to use is zero or negative. Cannot place order.', [
                     'asset' => $relevant_asset,
                     'balance' => $current_relevant_balance,
                     'percentage' => $this->amountPercentage,
                     'calculated_amount' => $amount_to_use,
                 ]);
                 $this->isPlacingOrder = false; // Clear flag
                 return \React\Promise\reject(new \DomainException('Calculated amount is zero or negative')); // Stop this chain explicitly
            }

            // Calculate the limit price with margin
            $limit_price = ($orderSide === 'BUY')
                ? $currentPrice * (1 - $this->orderPriceMarginPercent / 100) // Buy lower than trigger price
                : $currentPrice * (1 + $this->orderPriceMarginPercent / 100); // Sell higher than trigger price

            // Format price and amount (using fixed precision - needs improvement with exchangeInfo)
            // Using sprintf is acceptable, but be aware of potential precision issues vs exchange rules.
            $formatted_limit_price = sprintf('%.8f', $limit_price);
            $formatted_amount_to_use = sprintf('%.8f', $amount_to_use);


             $this->logger->info('Proceeding to place Convert Limit order', [
                'side' => $orderSide,
                'amount_to_use' => $formatted_amount_to_use,
                'asset_spent' => $relevant_asset,
                'limit_price' => $formatted_limit_price,
                'base_asset' => $this->convertBaseAsset,
                'quote_asset' => $this->convertQuoteAsset,
             ]);

            // Place the order
            return $this->placeConvertLimitOrder(
                $this->convertBaseAsset,
                $this->convertQuoteAsset,
                $orderSide,
                (float)$formatted_limit_price, // API function expects float, but it gets formatted again inside
                (float)$formatted_amount_to_use, // API function expects float
                '7_D' // Hardcoded expiry for now
            );
        })
        ->then(function ($orderData) {
            // Order placed successfully, update state
            if (isset($orderData['orderId'])) {
                $this->activeOrderId = (string)$orderData['orderId']; // Ensure string
                $this->orderPlaceTime = time();
                $this->logger->info('Convert Limit order placed successfully', [
                    'orderId' => $this->activeOrderId,
                    'place_time' => date('Y-m-d H:i:s', $this->orderPlaceTime)
                ]);
            } else {
                 // Should ideally be caught by makeAsyncApiRequest, but double check
                 $this->logger->error('Order placement API call succeeded but response lacked orderId', ['response' => $orderData]);
                 // Even though order placement *may* have happened, we don't have an ID to track.
                 // Clear the placing flag to allow future attempts, but log the error.
            }
             $this->isPlacingOrder = false; // Clear flag on success/completion of this step
        })
        ->catch(function (\Throwable $e) {
             // Catch errors from getSpecificAssetBalance OR placeConvertLimitOrder OR the DomainException above
             $this->logger->error('Failed during order placement chain', ['exception' => $e->getMessage()]);
             $this->isPlacingOrder = false; // Clear flag on failure
             // Keep monitoring price after failure
        });
    }


    /**
     * Checks the status of the currently active order using getConvertOrderStatus.
     * Cancels if timeout is reached.
     */
    private function checkActiveOrderStatus(): void
    {
        if ($this->activeOrderId === null) return; // Should not happen if called correctly

        $orderIdToCheck = $this->activeOrderId; // Capture for async context
        $this->logger->debug('Checking status for active order', ['orderId' => $orderIdToCheck]);

        // *** Call the corrected function ***
        $this->getConvertOrderStatus($orderIdToCheck)
        ->then(function (array $orderStatusData) use ($orderIdToCheck) {

            // Define statuses indicating the order is finished (Verify these against Binance Convert API docs)
            $finishedStatuses = ['SUCCESS', 'FAILURE', 'EXPIRED', 'CANCELED'];
            // Define statuses indicating the order is still potentially active/pending (Verify these)
            $pendingStatuses = ['PENDING', 'PROCESS']; // Example, check actual statuses

            $currentStatus = $orderStatusData['orderStatus'] ?? 'UNKNOWN'; // getConvertOrderStatus returns 'orderStatus'

            if (in_array($currentStatus, $finishedStatuses)) {
                 $this->logger->info('Active order is finished.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus]);
                 $this->clearActiveOrderState();

            } elseif (in_array($currentStatus, $pendingStatuses)) {
                 // Order is still open/pending, check cancellation timeout
                 $this->logger->debug('Active order is still pending/processing.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus]);
                 $currentTime = time();
                 $elapsedTime = $currentTime - ($this->orderPlaceTime ?? $currentTime); // Null check for safety

                 $this->logger->debug('Checking cancellation timeout.', [
                    'orderId' => $orderIdToCheck,
                    'elapsed_seconds' => $elapsedTime,
                    'cancel_after_seconds' => $this->cancelAfterSeconds
                 ]);

                 if ($elapsedTime >= $this->cancelAfterSeconds) {
                     $this->logger->warning('Cancellation timeout reached for active order. Attempting cancellation.', ['orderId' => $orderIdToCheck]);
                     // Cancellation logic uses cancelConvertLimitOrder
                     $this->cancelConvertLimitOrder($orderIdToCheck)
                     ->then(function ($result) use ($orderIdToCheck) {
                          $this->logger->info('Cancellation attempt processed for order. Clearing state.', ['orderId' => $orderIdToCheck, 'api_result_preview' => substr(json_encode($result), 0, 100)]);
                          $this->clearActiveOrderState();
                     })
                     ->catch(function (\Throwable $e) use ($orderIdToCheck) {
                           $this->logger->error('Failed to cancel order due to API/HTTP error during timeout cancellation. Clearing state anyway.', [
                               'orderId' => $orderIdToCheck,
                               'exception' => $e->getMessage()
                           ]);
                           $this->clearActiveOrderState();
                     });
                 }
             } else {
                 // Status is not recognized as pending or finished
                 $this->logger->warning('Unrecognized order status received from getConvertOrderStatus.', [
                    'orderId' => $orderIdToCheck,
                    'status' => $currentStatus,
                    'responseData' => $orderStatusData
                 ]);
                 // Treat as pending for timeout purposes, but log warning.
                 // (Add timeout check here identical to the one above if desired for unknown states)
             }
        })
        ->catch(function (\Throwable $e) use ($orderIdToCheck) {
             // Error occurred trying to fetch the order status
             $errorMessage = $e->getMessage();
             // Check if the error message indicates the order specifically doesn't exist
             // (Binance might use code -2013, or specific messages like "Order does not exist", "query order failed")
             // Adjust these checks based on actual observed errors from the API.
             if (str_contains($errorMessage, 'Order does not exist') || str_contains($errorMessage, '-2013') || str_contains($errorMessage, 'query order failed')) {
                  $this->logger->info('Order not found during status check (likely filled/cancelled/expired). Clearing state.', [
                      'orderId_checked' => $orderIdToCheck,
                      'exception' => $errorMessage
                  ]);
                  $this->clearActiveOrderState();
             } else {
                 // For other errors (network, unexpected API issues), log and retry next time.
                 $this->logger->error('Failed to get order status during check', [
                     'orderId_checked' => $orderIdToCheck,
                     'exception' => $errorMessage
                 ]);
                 // Do NOT clear state here. If the query failed for other reasons, retry.
             }
        });
    }

    /**
     * Clears the state related to an active order.
     */
    private function clearActiveOrderState(): void
    {
         $this->logger->debug('Clearing active order state', ['previous_order_id' => $this->activeOrderId]);
         $this->activeOrderId = null;
         $this->orderPlaceTime = null;
         $this->isPlacingOrder = false; // Ensure flag is clear
    }


    // --- API Helper Methods ---

    /**
     * Creates signed request data including timestamp, recvWindow, and signature.
     */
    private function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $timestamp = round(microtime(true) * 1000);
        $params['timestamp'] = $timestamp;
        $params['recvWindow'] = self::API_RECV_WINDOW;

        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986); // Use RFC3986 for consistency
        $signature = hash_hmac('sha256', $queryString, $this->apiSecret);
        // Add signature *after* query string generation for it
        $params['signature'] = $signature;

        $url = self::REST_API_BASE_URL . $endpoint;
        $body = null;

        if ($method === 'GET') {
            // For GET, all params (incl signature) go into the query string
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        } else {
            // For POST/PUT/DELETE, params (incl signature) go into the body
            $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['X-MBX-APIKEY' => $this->apiKey];

        return [
            'url' => $url,
            'headers' => $headers,
            'postData' => $body
        ];
    }

    /**
     * Makes an asynchronous HTTP request using ReactPHP Browser.
     * Handles basic response validation, JSON decoding, and Binance API error checking.
     */
    private function makeAsyncApiRequest(string $method, string $url, array $headers = [], ?string $body = null): PromiseInterface
    {
        // Default options for the browser request
        $options = [
            'follow_redirects' => false, // Standard practice for APIs
            'timeout' => 10.0 // Add a reasonable timeout
        ];

        if (in_array($method, ['POST', 'PUT', 'DELETE']) && is_string($body) && !empty($body)) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
             // Ensure body is passed for these methods
            $requestPromise = $this->browser->request($method, $url, $headers, $body, $options);
        } else {
            // For GET or methods without a body payload
            $requestPromise = $this->browser->request($method, $url, $headers, '', $options); // Pass empty string for body if null/GET
        }

        return $requestPromise->then(
            function (ResponseInterface $response) use ($method, $url) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $logContext = ['method' => $method, 'url_endpoint' => parse_url($url, PHP_URL_PATH), 'status' => $statusCode]; // Log only endpoint path

                if ($statusCode >= 400) {
                    // Log HTTP errors, but continue to parse body for potential Binance error message
                    $this->logger->warning('HTTP Error Status Code Received', $logContext + ['response_body_preview' => substr($body, 0, 200)]);
                }

                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('Failed to decode JSON response', $logContext + [
                        'json_error' => json_last_error_msg(),
                        'response_body_preview' => substr($body, 0, 200)
                    ]);
                    // Throw even if status code was < 400, invalid JSON is an error
                    throw new \RuntimeException("Failed to decode JSON response: " . json_last_error_msg());
                }

                 // Check for Binance API error structure ({ "code": ..., "msg": ... })
                 // Binance uses integer codes, non-zero indicates an error.
                 // Exception: Convert API might return { "orderId": ..., "status": "CANCELED" } on success for cancel, no "code" field.
                 // Need to handle cases where 'code' might not be present on success.
                 if (isset($data['code']) && $data['code'] != 0) {
                     $errorMessage = "Binance API error: " . ($data['msg'] ?? 'Unknown Error') . " (Code: " . $data['code'] . ")";
                     $logContext['api_code'] = $data['code'];
                     $logContext['api_msg'] = $data['msg'] ?? 'N/A';

                     // Handle specific known "non-fatal" errors if necessary (e.g., order not found during cancel/query)
                     // Convert API might use -2013 for "Order does not exist" or similar for "Unknown order sent" like -2011
                     $nonFatalCodes = [-2011, -2013]; // Add codes that indicate "already done" or "not found"
                     if (in_array($data['code'], $nonFatalCodes)) {
                         $this->logger->info('API Info: Known non-fatal error code received.', $logContext);
                         // Return the data - the caller can decide how to interpret this specific code
                         return $data;
                     }

                      // Log other API errors as errors
                     $this->logger->error('Binance API Error Response', $logContext);
                     throw new \RuntimeException($errorMessage); // Throw for general API errors
                 }

                 // If status code was >= 400 AND no Binance 'code' was present OR the code was 0, still treat as error
                 // (Unless it's a specific success case without 'code', like potentially the cancel response)
                 $isCancelSuccess = ($method === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '/convert/limit/cancelOrder') && isset($data['status']) && $data['status'] === 'CANCELED');

                 if ($statusCode >= 400 && !$isCancelSuccess) {
                      $this->logger->error('HTTP Error without specific API error code, or code was 0', $logContext + ['response_body_preview' => substr($body, 0, 200)]);
                      throw new \RuntimeException("HTTP Request failed with status code {$statusCode} and unexpected API response structure.");
                 }

                 // Successful response (HTTP 2xx and Binance code 0 or not present, or specific handled success like cancel)
                 // $this->logger->debug('API Request Success', $logContext); // Optional: Log success at debug level
                 return $data; // Return decoded JSON data
            },
            function (\Throwable $e) use ($method, $url) {
                // Catches HTTP client errors (connection refused, timeout, DNS etc.) or errors from within the ->then block above
                $this->logger->error('API Request Failed', [
                    'method' => $method,
                    'url_endpoint' => parse_url($url, PHP_URL_PATH), // Log only endpoint path
                    'exception_type' => get_class($e),
                    'exception_message' => $e->getMessage()
                ]);
                // Rethrow wrapped exception for upstream catchers
                throw new \RuntimeException("API Request failed for {$method} " . parse_url($url, PHP_URL_PATH) . ": " . $e->getMessage(), 0, $e);
            }
        );
    }

    /**
     * Fetches the available ('free') balance for a single specified asset.
     * Uses POST /sapi/v3/asset/getUserAsset to get all assets with balance > 0.
     */
    private function getSpecificAssetBalance(string $asset): PromiseInterface
    {
        $endpoint = '/sapi/v3/asset/getUserAsset';
        // This endpoint requires POST and signature, even with no body parameters initially
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'POST');
        $logContext = ['asset' => $asset];

        $this->logger->debug('Fetching asset balance via getUserAsset', $logContext);

        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function ($data) use ($asset, $logContext) {
                if (!is_array($data)) {
                     $this->logger->error('Invalid response format for getUserAsset: Not an array.', $logContext + ['response_type' => gettype($data)]);
                     throw new \RuntimeException("Invalid response format for getUserAsset: Not an array.");
                }
                foreach ($data as $assetInfo) {
                    // Ensure keys exist before accessing
                    if (isset($assetInfo['asset'], $assetInfo['free']) && $assetInfo['asset'] === $asset) {
                        $balance = (float)$assetInfo['free'];
                        $this->logger->info("Fetched free balance for asset", $logContext + ['balance' => $balance]);
                        return $balance;
                    }
                }
                // If loop finishes without finding the asset
                $this->logger->info("Asset not found in getUserAsset response (balance likely 0 or dust).", $logContext);
                return 0.0; // Asset not found, assume 0 balance
            })
            ->catch(function (\Throwable $e) use ($logContext) {
                 // Error already logged by makeAsyncApiRequest
                 $this->logger->error('Failure in getSpecificAssetBalance chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e; // Re-throw to propagate failure (caught by initialization)
             });
    }

    /**
     * Fetches the close price of the most recent Kline (Public Endpoint).
     */
    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface
    {
        $endpoint = '/api/v3/klines';
        $params = [
            'symbol' => strtoupper($symbol), // REST API uses uppercase
            'interval' => $interval,
            'limit' => 1,
        ];
        $url = self::REST_API_BASE_URL . $endpoint . '?' . http_build_query($params);
        $logContext = ['symbol' => $symbol, 'interval' => $interval];

        $this->logger->debug('Fetching latest kline price', $logContext);

        // Public endpoint, no signature needed, empty headers.
        return $this->makeAsyncApiRequest('GET', $url, [])
            ->then(function ($data) use ($logContext) {
                // Validate Kline data structure more robustly
                if (!is_array($data) || empty($data) || !isset($data[0]) || !is_array($data[0]) || count($data[0]) < 5 || !isset($data[0][4])) {
                     $this->logger->error('Invalid response format for klines', $logContext + ['response_preview' => json_encode(array_slice($data, 0, 1))]);
                     throw new \RuntimeException("Invalid response format for klines");
                }
                 // Kline data: [0:open_time, 1:open, 2:high, 3:low, 4:close, ...]
                $closePrice = (float)$data[0][4];
                 if ($closePrice <= 0) {
                     $this->logger->error('Invalid close price received from klines', $logContext + ['price' => $closePrice]);
                     throw new \RuntimeException("Invalid close price received: {$closePrice}");
                 }
                 $this->logger->info('Fetched latest kline close price', $logContext + ['price' => $closePrice]);
                 return $closePrice;
            })
             ->catch(function (\Throwable $e) use ($logContext) {
                 // Error already logged by makeAsyncApiRequest
                 $this->logger->error('Failure in getLatestKlineClosePrice chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e; // Re-throw (caught by initialization)
             });
    }

    /**
     * Places a Convert Limit order.
     * NOTE: Uses hardcoded precision. Fetching rules from /sapi/v1/convert/exchangeInfo is recommended for production.
     */
    private function placeConvertLimitOrder(string $baseAsset, string $quoteAsset, string $side, float $limitPrice, float $amount, string $expiredType = '7_D'): PromiseInterface
    {
        $endpoint = '/sapi/v1/convert/limit/placeOrder';
        // Validate inputs
        if ($limitPrice <= 0 || $amount <= 0) {
            $msg = "Invalid price or amount for placing order.";
            $this->logger->error($msg, ['price' => $limitPrice, 'amount' => $amount, 'side' => $side]);
            return \React\Promise\reject(new \InvalidArgumentException($msg));
        }
        if (!in_array($side, ['BUY', 'SELL'])) {
             $this->logger->error('Invalid order side specified for placement', ['side' => $side]);
             return \React\Promise\reject(new \InvalidArgumentException("Invalid order side specified: {$side}"));
        }

        $params = [
            'baseAsset' => $baseAsset,
            'quoteAsset' => $quoteAsset,
            // WARNING: Using fixed precision. Should ideally use dynamic precision from exchangeInfo.
            'limitPrice' => sprintf('%.8f', $limitPrice), // Format with fixed precision
            'side' => $side,
            'expiredType' => $expiredType,
        ];

        // WARNING: Using fixed precision. Should ideally use dynamic precision from exchangeInfo.
        if ($side === 'BUY') {
            $params['quoteAmount'] = sprintf('%.8f', $amount); // Format with fixed precision
        } else { // SELL
            $params['baseAmount'] = sprintf('%.8f', $amount); // Format with fixed precision
        }

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        $logContext = ['side' => $side, 'base' => $baseAsset, 'quote' => $quoteAsset, 'price' => $params['limitPrice'], 'amount_param' => ($side === 'BUY' ? 'quoteAmount' : 'baseAmount'), 'amount_value' => ($side === 'BUY' ? $params['quoteAmount'] : $params['baseAmount'])];

        $this->logger->info('Calling placeConvertLimitOrder API...', $logContext);

        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function ($data) use ($logContext) {
                 // Check if orderId is present upon successful API call (status 2xx, code 0)
                 if (!isset($data['orderId'])) {
                     // This case implies the API call itself didn't throw an error in makeAsyncApiRequest,
                     // but the response structure is unexpected (e.g., success status but no orderId).
                      $this->logger->error('Place order API response successful but missing orderId', $logContext + ['response' => $data]);
                      throw new \RuntimeException("Order placement response did not contain an orderId.");
                 }
                  // Success is logged by the calling function (attemptPlaceOrder)
                 return $data; // Contains orderId, clientOrderId, status etc.
            })
             ->catch(function (\Throwable $e) use ($logContext) {
                 // Error already logged by makeAsyncApiRequest or input validation above
                 $this->logger->error('Failure in placeConvertLimitOrder chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e; // Re-throw (caught by attemptPlaceOrder)
             });
    }

    /**
     * Gets the status of a specific Convert order by its ID.
     * Uses GET /sapi/v1/convert/orderStatus.
     */
    private function getConvertOrderStatus(string $orderId): PromiseInterface
    {
        $endpoint = '/sapi/v1/convert/orderStatus';
        $params = ['orderId' => $orderId];
        // This is a GET request requiring signature
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        $logContext = ['orderId' => $orderId];

        $this->logger->debug('Calling getConvertOrderStatus API...', $logContext);

        // Make the GET request
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) use ($logContext) {
                 // Basic validation of expected fields in a successful response
                 if (!isset($data['orderId']) || !isset($data['orderStatus'])) { // Endpoint returns 'orderStatus' key
                      // Log error but throw exception
                      $this->logger->error('Invalid response format for getConvertOrderStatus', $logContext + ['response_preview' => json_encode($data)]);
                      throw new \RuntimeException("Invalid response format for getConvertOrderStatus");
                 }
                 $this->logger->debug('Get order status successful', $logContext + ['status' => $data['orderStatus']]);
                 return $data; // Return full order status details
            })
            ->catch(function (\Throwable $e) use ($logContext) {
                 // Error already logged by makeAsyncApiRequest.
                 // Specific handling for "order not found" is now done in checkActiveOrderStatus's catch block.
                 $this->logger->error('Failure in getConvertOrderStatus chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e; // Re-throw (caught by checkActiveOrderStatus)
             });
    }


    /**
     * Cancels a specific Convert Limit order.
     * Uses POST /sapi/v1/convert/limit/cancelOrder.
     * Corrected based on API documentation for response check.
     */
    private function cancelConvertLimitOrder(string $orderId): PromiseInterface
    {
        $endpoint = '/sapi/v1/convert/limit/cancelOrder';
        $params = ['orderId' => $orderId]; // API Doc specifies LONG, but string is fine for request building
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        $logContext = ['orderId' => $orderId];

        $this->logger->info('Calling cancelConvertLimitOrder API...', $logContext);

        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function ($data) use ($logContext, $orderId) { // Pass orderId for comparison
                 // makeAsyncApiRequest handles logging/throwing general API errors.
                 // It also specifically logs known non-fatal codes like -2011, -2013 as INFO but returns the data.

                 // Check for known non-fatal codes first (order already gone)
                 if (isset($data['code']) && in_array($data['code'], [-2011, -2013])) {
                      $this->logger->debug('Order cancellation confirmed non-existent via known error code', $logContext + ['api_code' => $data['code']]);
                 }
                 // *** CORRECTED CHECK: Use 'status' instead of 'orderStatus' as per cancelOrder docs ***
                 elseif (isset($data['orderId'], $data['status']) && $data['orderId'] == $orderId && $data['status'] === 'CANCELED') {
                     // Explicit confirmation of CANCELED status from successful response
                     $this->logger->info('Order cancellation successful via API response status CANCELED', $logContext);
                 } else {
                     // If it wasn't an error caught by makeAsyncApiRequest, and not a known non-fatal code,
                     // but also not the exact expected CANCELED response structure, log a warning.
                     // It might still have been successful, but the response was slightly different.
                      $this->logger->warning('Cancellation response structure unexpected or status not explicitly CANCELED, but no API error reported', $logContext + ['response_preview' => json_encode($data)]);
                 }
                 // Return the result regardless for the caller (checkActiveOrderStatus)
                 return $data;
            })
             ->catch(function (\Throwable $e) use ($logContext) {
                  // Error already logged by makeAsyncApiRequest
                  $this->logger->error('Failure in cancelConvertLimitOrder chain', $logContext + ['exception' => $e->getMessage()]);
                  throw $e; // Re-throw (caught by checkActiveOrderStatus)
              });
    }

}


// --- Script Execution ---

$bot = new TradingBot(
    apiKey: $apiKey,
    apiSecret: $apiSecret,
    symbol: 'BTCUSDT',             // Symbol to monitor
    klineInterval: '1s',           // Price update interval
    convertBaseAsset: 'BTC',       // Base asset for Convert trades
    convertQuoteAsset: 'USDT',     // Quote asset for Convert trades
    orderSideOnPriceDrop: 'BUY',   // Action when price drops below trigger
    orderSideOnPriceRise: 'SELL',  // Action when price rises above trigger
    amountPercentage: 10.0,        // % of available relevant balance to use
    triggerMarginPercent: 0.01,    // % deviation from initial price to trigger
    orderPriceMarginPercent: 0.05, // % deviation from current price for limit order
    orderCheckIntervalSeconds: 5,  // How often to check active order status
    cancelAfterSeconds: 10,       // Auto-cancel order after 10 minutes (600s)
    maxScriptRuntimeSeconds: 3600  // Max script runtime 1 hour (3600s)
);

$bot->run();

?>