<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// --- Configuration Loading ---
$binanceApiKey = getenv('BINANCE_API_KEY') ?: 'NqgRju8D4Hqexr9ZBsbO0Ua4F2MiPzLshm7pCCBBGkbEgzj7yakorkadbPBX6UQF';
$binanceApiSecret = getenv('BINANCE_API_SECRET') ?: 'ue8NGUVZMTTNT8FcgT0bgSuCR6AfoFtEbmxfK7nIjHbePuWNW07CreuNAul5Yjeg';
$geminiApiKey = getenv('GEMINI_API_KEY') ?: 'AIzaSyChCv_Ab9Sd0vORDG-VY6rMrhKpMThv_YA'; // IMPORTANT: Set your Gemini API Key
$geminiModelName = getenv('GEMINI_MODEL_NAME') ?: 'gemini-1.5-flash-latest'; // Configurable Gemini Model

if ($binanceApiKey === 'YOUR_DEFAULT_KEY_FALLBACK' || $binanceApiSecret === 'YOUR_DEFAULT_SECRET_FALLBACK') {
    die("Error: Binance API Key or Secret not configured. Please set BINANCE_API_KEY and BINANCE_API_SECRET environment variables.\n");
}
if (empty($geminiApiKey)) {
    die("Error: Gemini API Key not configured. Please set GEMINI_API_KEY environment variable.\n");
}

// --- AI Trading Bot Class ---

class AiTradingBot
{
    // --- Constants ---
    private const BINANCE_REST_API_BASE_URL = 'https://api.binance.com';
    private const BINANCE_WS_API_BASE_URL = 'wss://stream.binance.com:9443';
    private const BINANCE_API_RECV_WINDOW = 5000;
    private const MAX_ORDER_LOG_ENTRIES = 10;

    // --- Configuration Properties ---
    private string $binanceApiKey;
    private string $binanceApiSecret;
    private string $geminiApiKey;
    private string $geminiModelName;
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
    private int $aiUpdateIntervalSeconds;


    // --- Dependencies ---
    private LoopInterface $loop;
    private Browser $browser;
    private Logger $logger;
    private ?WebSocket $wsConnection = null;

    // --- State Properties ---
    private ?float $initialBaseBalance = null;
    private ?float $initialQuoteBalance = null;
    private ?float $initialPrice = null;
    private ?float $lastClosedKlinePrice = null;
    private ?string $activeOrderId = null;
    private ?array $activeOrderDetails = null;
    private ?int $orderPlaceTime = null;
    private bool $isPlacingOrder = false;
    private array $recentOrderLogs = [];

    public function __construct(
        string $binanceApiKey,
        string $binanceApiSecret,
        string $geminiApiKey,
        string $geminiModelName,
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
        int $maxScriptRuntimeSeconds,
        int $aiUpdateIntervalSeconds
    ) {
        $this->binanceApiKey = $binanceApiKey;
        $this->binanceApiSecret = $binanceApiSecret;
        $this->geminiApiKey = $geminiApiKey;
        $this->geminiModelName = $geminiModelName;
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
        $this->aiUpdateIntervalSeconds = $aiUpdateIntervalSeconds;


        $this->loop = Loop::get();
        $this->browser = new Browser($this->loop);

        $logFormat = "[%datetime%] [%level_name%] %message% %context% %extra%\n";
        $formatter = new LineFormatter($logFormat, 'Y-m-d H:i:s', true, true);
        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('AiTradingBot');
        $this->logger->pushHandler($streamHandler);

        if ($this->cancelAfterSeconds <= $this->aiUpdateIntervalSeconds) {
            $this->logger->warning("Initial cancelAfterSeconds ({$this->cancelAfterSeconds}) is not greater than aiUpdateIntervalSeconds ({$this->aiUpdateIntervalSeconds}). Adjusting cancelAfterSeconds.", [
                'original_cancel_after' => $this->cancelAfterSeconds,
                'ai_update_interval' => $this->aiUpdateIntervalSeconds
            ]);
            $this->cancelAfterSeconds = $this->aiUpdateIntervalSeconds + 60;
             $this->logger->info("Adjusted cancelAfterSeconds to: {$this->cancelAfterSeconds}");
        }

        $this->logger->info('AiTradingBot instance created.', [
            'ai_update_interval_seconds' => $this->aiUpdateIntervalSeconds,
            'gemini_model_name' => $this->geminiModelName
        ]);
    }

    public function run(): void
    {
        $this->logger->info('Starting AI Trading Bot initialization...');
        \React\Promise\all([
            $this->getSpecificAssetBalance($this->convertBaseAsset),
            $this->getSpecificAssetBalance($this->convertQuoteAsset),
            $this->getLatestKlineClosePrice($this->symbol, $this->klineInterval),
        ])->then(
            function ($results) {
                $this->initialBaseBalance = (float)$results[0];
                $this->initialQuoteBalance = (float)$results[1];
                $this->initialPrice = (float)$results[2];
                $this->lastClosedKlinePrice = $this->initialPrice;

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

    private function stop(): void
    {
        $this->logger->info('Stopping event loop...');
        if ($this->wsConnection && method_exists($this->wsConnection, 'close')) {
             $this->logger->debug('Closing WebSocket connection...');
             try { $this->wsConnection->close(); } catch (\Exception $e) { /* ignore */ }
        }
        $this->loop->stop();
    }

    private function connectWebSocket(): void
    {
        $wsUrl = self::BINANCE_WS_API_BASE_URL . '/ws/' . strtolower($this->symbol) . '@kline_' . $this->klineInterval;
        $this->logger->info('Connecting to Binance WebSocket', ['url' => $wsUrl]);
        $wsConnector = new WsConnector($this->loop);
        $wsConnector($wsUrl)->then(
            function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->logger->info('WebSocket connected successfully.');
                $conn->on('message', fn($msg) => $this->handleWsMessage((string)$msg));
                $conn->on('error', function (\Throwable $e) {
                    $this->logger->error('WebSocket error', ['exception' => $e->getMessage()]);
                    $this->stop();
                });
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->stop();
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception' => $e->getMessage()]);
                $this->stop();
            }
        );
    }

    private function setupTimers(): void
    {
        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            if ($this->activeOrderId !== null && !$this->isPlacingOrder) {
                 $this->checkActiveOrderStatus();
            }
        });
        $this->logger->info('Order check timer started', ['interval_seconds' => $this->orderCheckIntervalSeconds]);

        $this->loop->addTimer($this->maxScriptRuntimeSeconds, function () {
            $this->logger->warning('Maximum script runtime reached. Stopping.', ['max_runtime_seconds' => $this->maxScriptRuntimeSeconds]);
            $this->stop();
        });
        $this->logger->info('Max runtime timer started', ['limit_seconds' => $this->maxScriptRuntimeSeconds]);

        // AI Parameter Update Timer
        $this->loop->addPeriodicTimer($this->aiUpdateIntervalSeconds, [$this, 'triggerAIUpdate']);
        $this->logger->info('AI parameter update timer started', ['interval_seconds' => $this->aiUpdateIntervalSeconds]);
        // Initial AI update after a short delay to let WS connect and gather initial data
        $this->loop->addTimer(15, [$this, 'triggerAIUpdate']);
    }

    private function handleWsMessage(string $msg): void
    {
        if ($this->activeOrderId !== null || $this->isPlacingOrder) return;

        $data = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['e']) || $data['e'] !== 'kline' || !isset($data['k']['c']) || !isset($data['k']['x']) || $data['k']['x'] !== true) {
            return;
        }

        $currentPrice = (float)$data['k']['c'];
        $this->lastClosedKlinePrice = $currentPrice;

        if ($this->initialPrice === null) {
             $this->logger->warning('Initial price not set, cannot evaluate triggers from WS.');
             return;
        }

        $triggerPriceUp = $this->initialPrice * (1 + $this->triggerMarginPercent / 100);
        $triggerPriceDown = $this->initialPrice * (1 - $this->triggerMarginPercent / 100);
        $orderSide = null;

        if ($currentPrice >= $triggerPriceUp) $orderSide = $this->orderSideOnPriceRise;
        elseif ($currentPrice <= $triggerPriceDown) $orderSide = $this->orderSideOnPriceDrop;

        if ($orderSide) {
            $this->logger->info('Price trigger met', [
                'current_price' => $currentPrice,
                'trigger_up' => $triggerPriceUp,
                'trigger_down' => $triggerPriceDown,
                'order_side' => $orderSide
            ]);
            $this->attemptPlaceOrder($orderSide, $currentPrice);
        }
    }

    private function addOrderToLog(string $orderId, string $status, string $side, string $assetPair, ?float $limitPrice, ?float $amountUsed, ?string $amountAsset, int $timestamp): void
    {
        $logEntry = [
            'orderId' => $orderId,
            'status' => $status,
            'side' => $side,
            'assetPair' => $assetPair,
            'limitPrice' => $limitPrice,
            'amountUsed' => $amountUsed,
            'amountAsset' => $amountAsset,
            'timestamp' => date('Y-m-d H:i:s', $timestamp),
        ];
        array_unshift($this->recentOrderLogs, $logEntry);
        $this->recentOrderLogs = array_slice($this->recentOrderLogs, 0, self::MAX_ORDER_LOG_ENTRIES);
        $this->logger->info('Order outcome logged for AI', $logEntry);
    }

    private function attemptPlaceOrder(string $orderSide, float $currentPrice): void
    {
        if ($this->isPlacingOrder) {
            $this->logger->warning('Order placement already in progress.');
            return;
        }
        $this->isPlacingOrder = true;
        $this->logger->info('Initiating order placement...', ['side' => $orderSide, 'current_price' => $currentPrice]);

        $relevantAsset = ($orderSide === 'BUY') ? $this->convertQuoteAsset : $this->convertBaseAsset;
        $assetPairForLog = $this->convertBaseAsset . '/' . $this->convertQuoteAsset;

        $this->getSpecificAssetBalance($relevantAsset)
        ->then(function (float $balance) use ($orderSide, $currentPrice, $relevantAsset, $assetPairForLog) {
            $amountToUse = $balance * ($this->amountPercentage / 100);
            if ($amountToUse <= 0) {
                $this->logger->warning('Calculated amount to use is zero or negative.', ['asset' => $relevantAsset, 'balance' => $balance, 'percentage' => $this->amountPercentage]);
                $this->isPlacingOrder = false;
                return \React\Promise\reject(new \DomainException('Amount to use is zero.'));
            }

            $limitPrice = ($orderSide === 'BUY')
                ? $currentPrice * (1 - $this->orderPriceMarginPercent / 100)
                : $currentPrice * (1 + $this->orderPriceMarginPercent / 100);

            $this->activeOrderDetails = [
                'side' => $orderSide,
                'assetPair' => $assetPairForLog,
                'limitPrice' => (float)sprintf('%.8f', $limitPrice),
                'amountUsed' => (float)sprintf('%.8f', $amountToUse),
                'amountAsset' => $relevantAsset,
            ];

            $this->logger->info('Proceeding to place Convert Limit order', [
                'details' => $this->activeOrderDetails,
            ]);

            return $this->placeConvertLimitOrder(
                $this->convertBaseAsset,
                $this->convertQuoteAsset,
                $orderSide,
                $this->activeOrderDetails['limitPrice'],
                $this->activeOrderDetails['amountUsed'],
                '7_D'
            );
        })
        ->then(function ($orderData) {
            if (isset($orderData['orderId'])) {
                $this->activeOrderId = (string)$orderData['orderId'];
                $this->orderPlaceTime = time();
                $this->activeOrderDetails['orderId'] = $this->activeOrderId;
                $this->logger->info('Convert Limit order placed successfully', [
                    'orderId' => $this->activeOrderId,
                    'place_time' => date('Y-m-d H:i:s', $this->orderPlaceTime)
                ]);
            } else {
                $this->logger->error('Order placement API call succeeded but response lacked orderId', ['response' => $orderData]);
                if ($this->activeOrderDetails) {
                     $this->addOrderToLog(
                        $this->activeOrderDetails['orderId'] ?? ('TEMP_FAIL_' . time()),
                        'FAILED_NO_ID',
                        $this->activeOrderDetails['side'],
                        $this->activeOrderDetails['assetPair'],
                        $this->activeOrderDetails['limitPrice'],
                        $this->activeOrderDetails['amountUsed'],
                        $this->activeOrderDetails['amountAsset'],
                        time()
                    );
                }
            }
            $this->isPlacingOrder = false;
        })
        ->catch(function (\Throwable $e) {
            $this->logger->error('Failed during order placement chain', ['exception' => $e->getMessage()]);
            if ($this->activeOrderDetails) {
                 $this->addOrderToLog(
                    $this->activeOrderDetails['orderId'] ?? ('TEMP_ERR_' . time()),
                    'FAILED_PLACEMENT',
                    $this->activeOrderDetails['side'],
                    $this->activeOrderDetails['assetPair'],
                    $this->activeOrderDetails['limitPrice'],
                    $this->activeOrderDetails['amountUsed'],
                    $this->activeOrderDetails['amountAsset'],
                    time()
                );
            }
            $this->isPlacingOrder = false;
        });
    }

    private function checkActiveOrderStatus(): void
    {
        if ($this->activeOrderId === null || !$this->activeOrderDetails) return;

        $orderIdToCheck = $this->activeOrderId;
        $orderDetailsForLog = $this->activeOrderDetails;
        $this->logger->debug('Checking status for active order', ['orderId' => $orderIdToCheck]);

        $this->getConvertOrderStatus($orderIdToCheck)
        ->then(function (array $orderStatusData) use ($orderIdToCheck, $orderDetailsForLog) {
            $finishedStatuses = ['SUCCESS', 'FAILURE', 'EXPIRED', 'CANCELED'];
            $pendingStatuses = ['PENDING', 'PROCESS'];
            $currentStatus = $orderStatusData['orderStatus'] ?? 'UNKNOWN';
            $logStatus = strtoupper($currentStatus);

            if (in_array($currentStatus, $finishedStatuses)) {
                $this->logger->info('Active order is finished.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus]);
                $this->addOrderToLog(
                    $orderIdToCheck,
                    $logStatus,
                    $orderDetailsForLog['side'],
                    $orderDetailsForLog['assetPair'],
                    $orderDetailsForLog['limitPrice'],
                    ($currentStatus === 'SUCCESS' ? ($orderStatusData['executedBaseQty'] ?? $orderStatusData['executedQuoteQty'] ?? $orderDetailsForLog['amountUsed']) : $orderDetailsForLog['amountUsed']),
                    ($currentStatus === 'SUCCESS' ? ($orderStatusData['baseAsset'] ?? $orderStatusData['quoteAsset'] ?? $orderDetailsForLog['amountAsset']) : $orderDetailsForLog['amountAsset']),
                    time()
                );
                $this->clearActiveOrderState();
            } elseif (in_array($currentStatus, $pendingStatuses)) {
                $elapsedTime = time() - ($this->orderPlaceTime ?? time());
                if ($elapsedTime >= $this->cancelAfterSeconds) {
                    $this->logger->warning('Cancellation timeout. Attempting cancellation.', ['orderId' => $orderIdToCheck, 'elapsed' => $elapsedTime]);
                    $this->cancelConvertLimitOrder($orderIdToCheck)
                    ->then(function ($result) use ($orderIdToCheck, $orderDetailsForLog) {
                        $this->logger->info('Cancellation attempt processed. Clearing state.', ['orderId' => $orderIdToCheck, 'result_preview' => substr(json_encode($result),0,100)]);
                        $this->addOrderToLog($orderIdToCheck, 'CANCELED_TIMEOUT', $orderDetailsForLog['side'], $orderDetailsForLog['assetPair'], $orderDetailsForLog['limitPrice'], $orderDetailsForLog['amountUsed'], $orderDetailsForLog['amountAsset'], time());
                        $this->clearActiveOrderState();
                    })
                    ->catch(function (\Throwable $e) use ($orderIdToCheck, $orderDetailsForLog) {
                        $this->logger->error('Failed to cancel order on timeout. Clearing state.', ['orderId' => $orderIdToCheck, 'exception' => $e->getMessage()]);
                         $this->addOrderToLog($orderIdToCheck, 'FAILED_CANCEL', $orderDetailsForLog['side'], $orderDetailsForLog['assetPair'], $orderDetailsForLog['limitPrice'], $orderDetailsForLog['amountUsed'], $orderDetailsForLog['amountAsset'], time());
                        $this->clearActiveOrderState();
                    });
                } else {
                    $this->logger->debug('Active order still pending.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus]);
                }
            } else {
                $this->logger->warning('Unrecognized order status.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus, 'data' => $orderStatusData]);
            }
        })
        ->catch(function (\Throwable $e) use ($orderIdToCheck, $orderDetailsForLog) {
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'Order does not exist') || str_contains($errorMessage, '-2013') || str_contains($errorMessage, 'query order failed')) {
                $this->logger->info('Order not found (likely filled/expired/cancelled). Clearing state.', ['orderId' => $orderIdToCheck, 'exception' => $errorMessage]);
                $this->addOrderToLog($orderIdToCheck, 'UNKNOWN_RESOLVED_NOT_FOUND', $orderDetailsForLog['side'], $orderDetailsForLog['assetPair'], $orderDetailsForLog['limitPrice'], $orderDetailsForLog['amountUsed'], $orderDetailsForLog['amountAsset'], time());
                $this->clearActiveOrderState();
            } else {
                $this->logger->error('Failed to get order status.', ['orderId' => $orderIdToCheck, 'exception' => $errorMessage]);
            }
        });
    }

    private function clearActiveOrderState(): void
    {
        $this->logger->debug('Clearing active order state', ['previous_order_id' => $this->activeOrderId]);
        $this->activeOrderId = null;
        $this->orderPlaceTime = null;
        $this->activeOrderDetails = null;
        $this->isPlacingOrder = false;
    }

    private function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $timestamp = round(microtime(true) * 1000);
        $params['timestamp'] = $timestamp;
        $params['recvWindow'] = self::BINANCE_API_RECV_WINDOW;
        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $queryString, $this->binanceApiSecret);
        $params['signature'] = $signature;
        $url = self::BINANCE_REST_API_BASE_URL . $endpoint;
        $body = null;
        if ($method === 'GET') {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        } else {
            $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        return ['url' => $url, 'headers' => ['X-MBX-APIKEY' => $this->binanceApiKey], 'postData' => $body];
    }

    private function makeAsyncApiRequest(string $method, string $url, array $headers = [], ?string $body = null): PromiseInterface
    {
        $options = ['follow_redirects' => false, 'timeout' => 10.0];
        $requestPromise = (in_array($method, ['POST', 'PUT', 'DELETE']) && is_string($body) && !empty($body))
            ? $this->browser->request($method, $url, $headers + ['Content-Type' => 'application/x-www-form-urlencoded'], $body, $options)
            : $this->browser->request($method, $url, $headers, '', $options);

        return $requestPromise->then(
            function (ResponseInterface $response) use ($method, $url) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $logCtx = ['method' => $method, 'url_path' => parse_url($url, PHP_URL_PATH), 'status' => $statusCode];
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('Failed to decode API JSON response', $logCtx + ['json_err' => json_last_error_msg(), 'body_preview' => substr($body, 0, 200)]);
                    throw new \RuntimeException("JSON decode error: " . json_last_error_msg());
                }
                if (isset($data['code']) && $data['code'] != 0 && !in_array($data['code'], [-2011, -2013])) {
                    $this->logger->error('Binance API Error', $logCtx + ['api_code' => $data['code'], 'api_msg' => $data['msg'] ?? 'N/A']);
                    throw new \RuntimeException("Binance API error ({$data['code']}): " . ($data['msg'] ?? 'Unknown'));
                }
                 if (isset($data['code']) && in_array($data['code'], [-2011, -2013])) {
                     $this->logger->info('Binance API Info (known code)', $logCtx + ['api_code' => $data['code'], 'api_msg' => $data['msg'] ?? 'N/A']);
                 }
                if ($statusCode >= 400 && !(isset($data['status']) && $data['status'] === 'CANCELED')) {
                     $isKnownErrorCode = isset($data['code']) && in_array($data['code'], [-2011, -2013]);
                     if (!$isKnownErrorCode) {
                        $this->logger->error('HTTP Error Status without specific handled API error', $logCtx + ['body_preview' => substr($body, 0, 200)]);
                        throw new \RuntimeException("HTTP error {$statusCode} with unhandled API response.");
                     }
                }
                return $data;
            },
            function (\Throwable $e) use ($method, $url) {
                $this->logger->error('API Request Failed', ['method' => $method, 'url_path' => parse_url($url, PHP_URL_PATH), 'err_type' => get_class($e), 'err_msg' => $e->getMessage()]);
                throw new \RuntimeException("API Req fail for {$method} " . parse_url($url, PHP_URL_PATH) . ": " . $e->getMessage(), 0, $e);
            }
        );
    }

    private function getSpecificAssetBalance(string $asset): PromiseInterface {
        $endpoint = '/sapi/v3/asset/getUserAsset';
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function ($data) use ($asset) {
                if (!is_array($data)) throw new \RuntimeException("Invalid response for getUserAsset: not an array.");
                foreach ($data as $assetInfo) {
                    if (isset($assetInfo['asset'], $assetInfo['free']) && $assetInfo['asset'] === $asset) {
                        return (float)$assetInfo['free'];
                    }
                }
                return 0.0;
            });
    }
    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface {
        $endpoint = '/api/v3/klines';
        $params = ['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => 1];
        $url = self::BINANCE_REST_API_BASE_URL . $endpoint . '?' . http_build_query($params);
        return $this->makeAsyncApiRequest('GET', $url, [])
            ->then(function ($data) {
                if (!is_array($data) || empty($data) || !isset($data[0][4])) throw new \RuntimeException("Invalid klines response format.");
                $price = (float)$data[0][4];
                if ($price <=0) throw new \RuntimeException("Invalid kline price: {$price}");
                return $price;
            });
    }
    private function placeConvertLimitOrder(string $baseAsset, string $quoteAsset, string $side, float $limitPrice, float $amount, string $expiredType = '7_D'): PromiseInterface {
        $endpoint = '/sapi/v1/convert/limit/placeOrder';
        if ($limitPrice <= 0 || $amount <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid price/amount for order."));
        $params = ['baseAsset' => $baseAsset, 'quoteAsset' => $quoteAsset, 'limitPrice' => sprintf('%.8f', $limitPrice), 'side' => $side, 'expiredType' => $expiredType];
        if ($side === 'BUY') $params['quoteAmount'] = sprintf('%.8f', $amount);
        else $params['baseAmount'] = sprintf('%.8f', $amount);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function($data){
                if(!isset($data['orderId'])) throw new \RuntimeException("Place order response missing orderId.");
                return $data;
            });
    }
    private function getConvertOrderStatus(string $orderId): PromiseInterface {
        $endpoint = '/sapi/v1/convert/orderStatus';
        $params = ['orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function($data){
                 if (!isset($data['orderId']) || !isset($data['orderStatus'])) throw new \RuntimeException("Invalid getConvertOrderStatus response.");
                 return $data;
            });
    }
    private function cancelConvertLimitOrder(string $orderId): PromiseInterface {
        $endpoint = '/sapi/v1/convert/limit/cancelOrder';
        $params = ['orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    // --- AI Interaction Methods ---
    public function triggerAIUpdate(): void // Visibility changed to public
    {
        if ($this->initialPrice === null) {
            $this->logger->debug("AI Update: Initial price not set, skipping AI update cycle.");
            return;
        }
        $this->logger->info('Starting AI parameter update cycle...');
        $dataForAI = $this->collectDataForAI();
        $promptPayload = $this->constructAIPrompt($dataForAI);

        $this->sendRequestToAI($promptPayload)
            ->then(
                function ($rawResponse) { // Wrapped in a closure
                    return $this->processAIResponse($rawResponse);
                }
            )
            ->catch(function (\Throwable $e) {
                $this->logger->error('AI update cycle failed.', ['exception' => $e->getMessage()]);
            });
    }

    private function collectDataForAI(): array
    {
        return [
            'current_asset_price' => $this->lastClosedKlinePrice ?? $this->initialPrice,
            'recent_order_logs' => $this->recentOrderLogs,
            'current_parameters' => [
                'amountPercentage' => $this->amountPercentage,
                'triggerMarginPercent' => $this->triggerMarginPercent,
                'orderPriceMarginPercent' => $this->orderPriceMarginPercent,
                'cancelAfterSeconds' => $this->cancelAfterSeconds,
            ],
            'ai_update_interval_seconds' => $this->aiUpdateIntervalSeconds,
            'trade_logic_summary' => "The bot monitors the price of {$this->symbol} using {$this->klineInterval} klines. If the price deviates from an initial reference price ({$this->initialPrice}) by `triggerMarginPercent`, it attempts to place a Binance Convert Limit order. If price rises, it Sells {$this->convertBaseAsset}; if drops, it Buys {$this->convertBaseAsset} with {$this->convertQuoteAsset}. Order price has `orderPriceMarginPercent` slip. Orders auto-cancel after `cancelAfterSeconds`. Trade amount is `amountPercentage` of available balance.",
        ];
    }

    private function constructAIPrompt(array $dataForAI): string
    {
        $promptText = "You are an AI trading assistant. Your goal is to optimize the trading parameters for a cryptocurrency bot to maximize profit.\n\n";
        $promptText .= "Bot's Current State & Data:\n" . json_encode($dataForAI, JSON_PRETTY_PRINT) . "\n\n";
        $promptText .= "Your Task:\nBased on the provided data, suggest new values for `amountPercentage`, `triggerMarginPercent`, `orderPriceMarginPercent`, and `cancelAfterSeconds`.\n";
        $promptText .= "Your suggestions should aim to make profitable trades.\n\n";
        $promptText .= "Constraints for `cancelAfterSeconds`:\n";
        $promptText .= "- The new `cancelAfterSeconds` value MUST be an integer.\n";
        $promptText .= "- The new `cancelAfterSeconds` value MUST be strictly greater than `ai_update_interval_seconds` (which is {$this->aiUpdateIntervalSeconds}). For example, if `ai_update_interval_seconds` is {$this->aiUpdateIntervalSeconds}, `cancelAfterSeconds` must be at least " . ($this->aiUpdateIntervalSeconds + 1) . ".\n\n";
        $promptText .= "Output Format:\nPlease provide your response as a single JSON object string with the following structure:\n";
        $promptText .= "{\n  \"amountPercentage\": <float_value_between_0.1_and_100.0>,\n  \"triggerMarginPercent\": <float_value_between_0.01_and_5.0>,\n  \"orderPriceMarginPercent\": <float_value_between_0.01_and_5.0>,\n  \"cancelAfterSeconds\": <integer_value>\n}\n";
        $promptText .= "Example:\n{\"amountPercentage\": 15.0, \"triggerMarginPercent\": 0.05, \"orderPriceMarginPercent\": 0.03, \"cancelAfterSeconds\": " . ($this->aiUpdateIntervalSeconds + 60) . "}\n\n";
        $promptText .= "Analyze the recent trades and current market conditions (as inferred from price) to make your decision. Ensure the JSON is valid.";

        return json_encode(['contents' => [['parts' => [['text' => $promptText]]]]]);
    }

    private function sendRequestToAI(string $jsonPayload): PromiseInterface
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModelName . ':generateContent?key=' . $this->geminiApiKey;
        $headers = [
            'Content-Type' => 'application/json',
        ];
        $this->logger->debug('Sending request to Gemini AI', ['url' => $url, 'payload_preview' => substr($jsonPayload, 0, 250) . '...']);

        return $this->browser->post($url, $headers, $jsonPayload)->then(
            function (ResponseInterface $response) {
                $body = (string)$response->getBody();
                $this->logger->debug('Received response from Gemini AI', ['status' => $response->getStatusCode(), 'body_preview' => substr($body, 0, 500) . '...']);
                if ($response->getStatusCode() >= 300) {
                    throw new \RuntimeException("Gemini API HTTP error: " . $response->getStatusCode() . " Body: " . $body);
                }
                return $body;
            },
            function (\Throwable $e) {
                $this->logger->error('Gemini AI request failed', ['exception' => $e->getMessage()]);
                throw $e;
            }
        );
    }

    private function processAIResponse(string $rawResponse): void
    {
        $this->logger->info('Processing AI response.');
        try {
            $responseDecoded = json_decode($rawResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Failed to decode AI JSON response: " . json_last_error_msg());
            }

            $aiTextResponse = $responseDecoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$aiTextResponse) {
                throw new \InvalidArgumentException("Could not extract text from AI response. Full response: " . substr($rawResponse,0,500));
            }

            $paramsJson = $aiTextResponse;
            $paramsJson = trim($paramsJson);
            if (str_starts_with($paramsJson, '```json')) $paramsJson = substr($paramsJson, 7);
            if (str_starts_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 3);
            if (str_ends_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 0, -3);
            $paramsJson = trim($paramsJson);

            $newParams = json_decode($paramsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Failed to decode JSON parameters from AI's text response.", [
                    'ai_text_response' => $aiTextResponse,
                    'json_error' => json_last_error_msg()
                ]);
                throw new \InvalidArgumentException("Failed to decode JSON parameters from AI's text: " . json_last_error_msg());
            }
            $this->updateBotParameters($newParams);

        } catch (\Throwable $e) {
            $this->logger->error('Error processing AI response or updating parameters.', [
                'exception' => $e->getMessage(),
                'raw_response_preview' => substr($rawResponse, 0, 500)
            ]);
        }
    }

    private function updateBotParameters(array $newParams): void
    {
        $this->logger->info('Attempting to update bot parameters from AI.', ['new_params' => $newParams]);
        $updated = false;

        if (isset($newParams['amountPercentage']) && is_numeric($newParams['amountPercentage'])) {
            $val = (float)$newParams['amountPercentage'];
            if ($val >= 0.1 && $val <= 100.0) {
                $this->amountPercentage = $val;
                $this->logger->info('Updated amountPercentage', ['value' => $this->amountPercentage]);
                $updated = true;
            } else {
                $this->logger->warning('AI proposed invalid amountPercentage', ['value' => $val]);
            }
        }
        if (isset($newParams['triggerMarginPercent']) && is_numeric($newParams['triggerMarginPercent'])) {
            $val = (float)$newParams['triggerMarginPercent'];
            if ($val >= 0.001 && $val <= 10.0) {
                $this->triggerMarginPercent = $val;
                $this->logger->info('Updated triggerMarginPercent', ['value' => $this->triggerMarginPercent]);
                $updated = true;
            } else {
                $this->logger->warning('AI proposed invalid triggerMarginPercent', ['value' => $val]);
            }
        }
        if (isset($newParams['orderPriceMarginPercent']) && is_numeric($newParams['orderPriceMarginPercent'])) {
            $val = (float)$newParams['orderPriceMarginPercent'];
            if ($val >= 0.001 && $val <= 10.0) {
                $this->orderPriceMarginPercent = $val;
                $this->logger->info('Updated orderPriceMarginPercent', ['value' => $this->orderPriceMarginPercent]);
                $updated = true;
            } else {
                $this->logger->warning('AI proposed invalid orderPriceMarginPercent', ['value' => $val]);
            }
        }
        if (isset($newParams['cancelAfterSeconds']) && is_numeric($newParams['cancelAfterSeconds'])) {
            $val = (int)$newParams['cancelAfterSeconds'];
            if ($val > $this->aiUpdateIntervalSeconds) {
                $this->cancelAfterSeconds = $val;
                $this->logger->info('Updated cancelAfterSeconds', ['value' => $this->cancelAfterSeconds]);
                $updated = true;
            } else {
                $this->logger->warning('AI proposed invalid cancelAfterSeconds (must be > aiUpdateIntervalSeconds).', [
                    'proposed_value' => $val,
                    'ai_update_interval' => $this->aiUpdateIntervalSeconds
                ]);
            }
        }

        if (!$updated) {
            $this->logger->warning('No valid parameters were updated from AI response.');
        }
    }
}


// --- Script Execution ---
$loop = Loop::get();

$bot = new AiTradingBot(
    binanceApiKey: $binanceApiKey,
    binanceApiSecret: $binanceApiSecret,
    geminiApiKey: $geminiApiKey,
    geminiModelName: $geminiModelName,
    symbol: 'BTCUSDT',
    klineInterval: '1s',
    convertBaseAsset: 'BTC',
    convertQuoteAsset: 'USDT',
    orderSideOnPriceDrop: 'BUY',
    orderSideOnPriceRise: 'SELL',
    amountPercentage: 5.0,
    triggerMarginPercent: 0.02,
    orderPriceMarginPercent: 0.01,
    orderCheckIntervalSeconds: 5,
    cancelAfterSeconds: 360,
    maxScriptRuntimeSeconds: 3 * 3600,
    aiUpdateIntervalSeconds: 300
);

$bot->run();

?>