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
use React\Promise\Deferred;
use React\Promise\all as PromiseAll; // Alias can stay for clarity or future use
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// --- Configuration Loading ---
$binanceApiKey = getenv('BINANCE_API_KEY') ?: '87d4247646329bd52942197b01f819c210eac9a22ccc3c1ae456475b59329d32';
$binanceApiSecret = getenv('BINANCE_API_SECRET') ?: 'e6976724570ccbb50bf27922c860b19c0cf033a94e4592389b77daca3c9edb73';
$geminiApiKey = getenv('GEMINI_API_KEY') ?: 'AIzaSyChCv_Ab9Sd0vORDG-VY6rMrhKpMThv_YA'; // Ensure this is set
$geminiModelName = getenv('GEMINI_MODEL_NAME') ?: 'gemini-2.5-flash-preview-04-17'; // Or your preferred model, ensure it's a valid one like gemini-1.5-flash

$useTestnet = true; // Set to true for testnet

if ($binanceApiKey === 'YOUR_BINANCE_FUTURES_API_KEY' || $binanceApiSecret === 'YOUR_BINANCE_FUTURES_API_SECRET') {
    die("Error: Binance API Key or Secret not configured. Please set BINANCE_API_KEY and BINANCE_API_SECRET environment variables.\n");
}
if (empty($geminiApiKey) || $geminiApiKey === 'YOUR_GEMINI_API_KEY') {
    die("Error: Gemini API Key not configured. Please set GEMINI_API_KEY environment variable.\n");
}

// --- AI Trading Bot Class for Futures ---

class AiTradingBotFutures
{
    // --- Constants ---
    private const BINANCE_FUTURES_PROD_REST_API_BASE_URL = 'https://fapi.binance.com';
    private const BINANCE_FUTURES_TEST_REST_API_BASE_URL = 'https://testnet.binancefuture.com';
    private const BINANCE_FUTURES_PROD_WS_BASE_URL = 'wss://fstream.binance.com';
    private const BINANCE_FUTURES_TEST_WS_BASE_URL = 'wss://fstream-auth.binance.com'; // Testnet uses fstream-auth for user data or fstream for public

    private const BINANCE_API_RECV_WINDOW = 5000;
    private const MAX_ORDER_LOG_ENTRIES = 20;
    private const LISTEN_KEY_REFRESH_INTERVAL = 30 * 60; // 30 minutes
    private const PENDING_ENTRY_ORDER_CANCEL_TIMEOUT_SECONDS_DEFAULT = 300; // Default 5 minutes

    // --- Configuration Properties ---
    private string $binanceApiKey;
    private string $binanceApiSecret;
    private string $geminiApiKey;
    private string $geminiModelName;
    private string $tradingSymbol; // e.g., BTCUSDT
    private string $klineInterval; // e.g., 1m, 5m (for WebSocket stream & primary logic)
    private string $historicalKlineIntervalAI; // e.g., 1s, 5s, 1m (for AI's detailed historical analysis)
    private string $marginAsset;   // e.g., USDT

    private int $defaultLeverage;
    private float $amountPercentage; // Percentage of margin to use for a trade (AI can adjust)
    // AI will provide SL/TP, entry strategy
    private int $orderCheckIntervalSeconds; // Fallback check, User Data Stream is primary
    private int $maxScriptRuntimeSeconds;
    private int $aiUpdateIntervalSeconds;
    private bool $useTestnet;
    private string $currentRestApiBaseUrl;
    private string $currentWsBaseUrl;
    private int $pendingEntryOrderCancelTimeoutSeconds;


    // --- Dependencies ---
    private LoopInterface $loop;
    private Browser $browser;
    private Logger $logger;
    private ?WebSocket $wsConnection = null;

    // --- State Properties ---
    private ?float $lastClosedKlinePrice = null; // Price from the main $klineInterval stream
    private ?string $activeEntryOrderId = null;
    private ?int $activeEntryOrderTimestamp = null; // Timestamp of when the entry order was placed
    private ?string $activeSlOrderId = null;
    private ?string $activeTpOrderId = null;
    private ?array $currentPositionDetails = null; // [symbol, side, entryPrice, quantity, leverage, unrealizedPnl, markPrice]
    private array $recentOrderLogs = [];
    private bool $isPlacingOrManagingOrder = false; // General lock for complex operations
    private ?string $listenKey = null;
    private ?\React\EventLoop\TimerInterface $listenKeyRefreshTimer = null;
    private bool $isMissingProtectiveOrder = false; // Flag if position open but SL/TP missing
    private ?array $lastAIDecisionResult = null; // Store outcome of last AI action

    // AI Suggested Parameters (can be part of a more complex state object)
    private float $aiSuggestedEntryPrice;
    private float $aiSuggestedSlPrice;
    private float $aiSuggestedTpPrice;
    private float $aiSuggestedQuantity;
    private string $aiSuggestedSide; // BUY (long) or SELL (short)
    private int $aiSuggestedLeverage;


    public function __construct(
        string $binanceApiKey,
        string $binanceApiSecret,
        string $geminiApiKey,
        string $geminiModelName,
        string $tradingSymbol,
        string $klineInterval, // Interval for live stream & main logic
        string $historicalKlineIntervalAI, // Interval for AI's historical data
        string $marginAsset,
        int $defaultLeverage,
        float $amountPercentage,
        int $orderCheckIntervalSeconds,
        int $maxScriptRuntimeSeconds,
        int $aiUpdateIntervalSeconds,
        bool $useTestnet,
        int $pendingEntryOrderCancelTimeoutSeconds = self::PENDING_ENTRY_ORDER_CANCEL_TIMEOUT_SECONDS_DEFAULT
    ) {
        $this->binanceApiKey = $binanceApiKey;
        $this->binanceApiSecret = $binanceApiSecret;
        $this->geminiApiKey = $geminiApiKey;
        $this->geminiModelName = $geminiModelName;
        $this->tradingSymbol = strtoupper($tradingSymbol);
        $this->klineInterval = $klineInterval;
        $this->historicalKlineIntervalAI = $historicalKlineIntervalAI;
        $this->marginAsset = strtoupper($marginAsset);
        $this->defaultLeverage = $defaultLeverage;
        $this->amountPercentage = $amountPercentage; // Currently unused as AI calculates quantity
        $this->orderCheckIntervalSeconds = $orderCheckIntervalSeconds;
        $this->maxScriptRuntimeSeconds = $maxScriptRuntimeSeconds;
        $this->aiUpdateIntervalSeconds = $aiUpdateIntervalSeconds;
        $this->useTestnet = $useTestnet;
        $this->pendingEntryOrderCancelTimeoutSeconds = $pendingEntryOrderCancelTimeoutSeconds;

        $this->currentRestApiBaseUrl = $this->useTestnet ? self::BINANCE_FUTURES_TEST_REST_API_BASE_URL : self::BINANCE_FUTURES_PROD_REST_API_BASE_URL;
        // Corrected WebSocket URL for testnet user data stream based on Binance documentation (fstream-auth.binance.com)
        // Public streams (like klines) use stream.binancefuture.com for testnet.
        // The combined stream URL will be constructed later.
        $this->currentWsBaseUrl = $this->useTestnet ? 'wss://stream.binancefuture.com' : self::BINANCE_FUTURES_PROD_WS_BASE_URL;


        $this->loop = Loop::get();
        $this->browser = new Browser($this->loop);

        $logFormat = "[%datetime%] [%level_name%] %message% %context% %extra%\n";
        $formatter = new LineFormatter($logFormat, 'Y-m-d H:i:s', true, true);
        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('AiTradingBotFutures');
        $this->logger->pushHandler($streamHandler);

        $this->logger->info('AiTradingBotFutures instance created.', [
            'symbol' => $this->tradingSymbol,
            'kline_interval_stream' => $this->klineInterval,
            'kline_interval_historical_ai' => $this->historicalKlineIntervalAI,
            'ai_update_interval_seconds' => $this->aiUpdateIntervalSeconds,
            'gemini_model_name' => $this->geminiModelName,
            'using_testnet' => $this->useTestnet,
            'pending_entry_cancel_timeout' => $this->pendingEntryOrderCancelTimeoutSeconds,
            'rest_url' => $this->currentRestApiBaseUrl,
            'ws_url_base_public' => $this->currentWsBaseUrl, // For public streams like klines
            'ws_url_base_user_testnet' => self::BINANCE_FUTURES_TEST_WS_BASE_URL // Specifically for user data on testnet
        ]);

        $this->aiSuggestedLeverage = $this->defaultLeverage;
    }

    public function run(): void
    {
        $this->logger->info('Starting AI Trading Bot (Futures) initialization...');
        \React\Promise\all([
            'initial_balance' => $this->getFuturesAccountBalance(),
            'initial_price' => $this->getLatestKlineClosePrice($this->tradingSymbol, $this->klineInterval), // Use main klineInterval for initial price
            'initial_position' => $this->getPositionInformation($this->tradingSymbol),
            'listen_key' => $this->startUserDataStream(),
        ])->then(
            function ($results) {
                $initialBalance = $results['initial_balance'][$this->marginAsset] ?? ['availableBalance' => 0.0, 'balance' => 0.0];
                $this->lastClosedKlinePrice = (float)($results['initial_price']['price'] ?? 0);
                $this->currentPositionDetails = $this->formatPositionDetails($results['initial_position']);
                $this->listenKey = $results['listen_key']['listenKey'] ?? null;

                if ($this->lastClosedKlinePrice <= 0) {
                    throw new \RuntimeException("Failed to fetch a valid initial price for {$this->tradingSymbol} using {$this->klineInterval} interval.");
                }
                if (!$this->listenKey) {
                    throw new \RuntimeException("Failed to obtain a listenKey for User Data Stream.");
                }

                $this->logger->info('Initialization Success', [
                    'startup_' . $this->marginAsset . '_balance' => $initialBalance,
                    'initial_market_price_' . $this->tradingSymbol . '_' . $this->klineInterval => $this->lastClosedKlinePrice,
                    'initial_position' => $this->currentPositionDetails ?? 'No position',
                    'listen_key_obtained' => (bool)$this->listenKey,
                ]);

                $this->connectWebSocket();
                $this->setupTimers();
                $this->loop->addTimer(5, function () {
                     // Initial AI check after startup
                    $this->triggerAIUpdate();
                });
            },
            function (\Throwable $e) {
                $this->logger->error('Initialization failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
        if ($this->listenKeyRefreshTimer) {
            $this->loop->cancelTimer($this->listenKeyRefreshTimer);
        }
        if ($this->listenKey) {
            $this->closeUserDataStream($this->listenKey)->then(
                fn() => $this->logger->info("ListenKey closed successfully."),
                fn($e) => $this->logger->error("Failed to close ListenKey.", ['err' => $e->getMessage()])
            );
        }
        if ($this->wsConnection && method_exists($this->wsConnection, 'close')) {
             $this->logger->debug('Closing WebSocket connection...');
             try { $this->wsConnection->close(); } catch (\Exception $e) { /* ignore */ }
        }
        $this->loop->stop();
    }

    private function connectWebSocket(): void
    {
        if (!$this->listenKey) {
            $this->logger->error("Cannot connect WebSocket without a listenKey.");
            $this->stop();
            return;
        }

        $klineStream = strtolower($this->tradingSymbol) . '@kline_' . $this->klineInterval;
        
        // Determine the correct base URL for the listenKey part of the stream
        // For production, it's fstream.binance.com
        // For testnet, user data streams (identified by listenKey) use fstream-auth.binance.com
        $userDataStreamBaseUrl = $this->useTestnet ? self::BINANCE_FUTURES_TEST_WS_BASE_URL : self::BINANCE_FUTURES_PROD_WS_BASE_URL;
        
        // Public streams like klines use stream.binancefuture.com on testnet
        // $publicStreamBaseUrl = $this->currentWsBaseUrl; // This is already set correctly for public streams.

        // Construct the combined stream URL.
        // All streams can be combined under a single connection if they share the same base WebSocket URL.
        // However, Binance Testnet uses different base URLs for public (kline) and private (user data) streams.
        // For simplicity and common practice, we often combine them IF the base URLs match.
        // If they don't, two connections would be needed, or we pick one.
        // Most examples show combining them. Binance docs suggest /stream?streams=... for fstream.binance.com
        // and /ws/<listenKey> for fstream-auth.binance.com
        // Let's assume we can combine on the *public* stream URL and append the listenKey stream path
        // This is a common pattern. If testnet has issues, this might need splitting.
        // Testnet: wss://stream.binancefuture.com/stream?streams=btcusdt@kline_1m/TEST_LISTEN_KEY (this seems to work for some)
        // OR the listenKey itself is a path: wss://fstream-auth.binance.com/ws/LISTEN_KEY
        
        // Let's try the combined approach using the public stream base, as it's more common.
        // If listenKey on testnet MUST use fstream-auth, this combined stream won't get user data.
        // The most robust way for testnet is often two separate connections if base URLs differ significantly in path structure.
        // For now, let's stick to the single combined stream path, assuming it works or user data comes via REST for checks.
        // The provided testnet WS URL fstream-auth.binance.com is usually for /ws/<listenKey>
        // The provided public testnet WS URL is stream.binancefuture.com (for /stream?streams=...)
        // Given this, the current $this->currentWsBaseUrl is for public streams.
        // The user data stream for testnet should be wss://fstream-auth.binance.com/ws/{listenKey}
        // This means we *cannot* reliably combine them into one URL for testnet if the base domains differ.

        // Let's try connecting to the user data stream separately if on testnet,
        // and the kline stream on its own URL. This is more complex.
        // For now, let's assume the single stream approach and see if user data events arrive on testnet.
        // The provided constant self::BINANCE_FUTURES_TEST_WS_BASE_URL = 'wss://fstream-auth.binance.com';
        // This is typically used as wss://fstream-auth.binance.com/ws/<listenKey>
        // The other one currentWsBaseUrl = 'wss://stream.binancefuture.com' is for /stream?streams=...

        // If using testnet, the user data stream path might be just /ws/{listenKey} on fstream-auth.
        // And kline is /stream?streams={klineStreamName} on stream.binancefuture.com
        // This structure implies two separate WebSocket connections for testnet if using fstream-auth for user data.

        // Let's simplify and assume one connection to the public stream endpoint, appending the listen key.
        // This works for production (fstream.binance.com for both).
        // For testnet, it means using stream.binancefuture.com and hoping it accepts the listenKey in this format.
        $wsUrl = $this->currentWsBaseUrl . '/stream?streams=' . $klineStream . '/' . $this->listenKey;


        $this->logger->info('Connecting to Binance Futures Combined WebSocket', ['url' => $wsUrl]);
        $wsConnector = new WsConnector($this->loop);

        $wsConnector($wsUrl)->then(
            function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->logger->info('WebSocket connected successfully.');
                $conn->on('message', fn($msg) => $this->handleWsMessage((string)$msg));
                $conn->on('error', function (\Throwable $e) {
                    $this->logger->error('WebSocket error', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                    // Consider robust reconnect logic here
                    $this->stop(); 
                });
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->wsConnection = null; 
                    // Consider robust reconnect logic here
                    $this->stop(); 
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                 // Consider robust reconnect logic here
                $this->stop();
            }
        );
    }

    private function setupTimers(): void
    {
        // Fallback Order Check / Timeout Timer
        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            // --- Pending Entry Order Timeout Check ---
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder && $this->activeEntryOrderTimestamp !== null) {
                $secondsPassed = time() - $this->activeEntryOrderTimestamp;
                if ($secondsPassed > $this->pendingEntryOrderCancelTimeoutSeconds) {
                    $this->logger->warning("Pending entry order {$this->activeEntryOrderId} timed out ({$secondsPassed}s > {$this->pendingEntryOrderCancelTimeoutSeconds}s). Attempting cancellation.");
                    $this->isPlacingOrManagingOrder = true; // Lock
                    $orderIdToCancel = $this->activeEntryOrderId;
                    $timedOutOrderSide = $this->aiSuggestedSide ?? 'N/A';
                    $timedOutOrderPrice = $this->aiSuggestedEntryPrice ?? 0;
                    $timedOutOrderQty = $this->aiSuggestedQuantity ?? 0;

                    $this->cancelFuturesOrder($this->tradingSymbol, $orderIdToCancel)
                        ->then(
                            function ($cancellationData) use ($orderIdToCancel, $timedOutOrderSide, $timedOutOrderPrice, $timedOutOrderQty) {
                                $this->logger->info("Pending entry order {$orderIdToCancel} successfully cancelled due to timeout.", ['response_status' => $cancellationData['status'] ?? 'N/A']);
                                if ($this->activeEntryOrderId === $orderIdToCancel) {
                                    $this->addOrderToLog($orderIdToCancel, 'CANCELED_TIMEOUT', $timedOutOrderSide, $this->tradingSymbol, $timedOutOrderPrice, $timedOutOrderQty, $this->marginAsset, time(), 0.0);
                                    $this->resetTradeState();
                                    $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderIdToCancel} cancelled due to timeout.", 'decision' => null];
                                }
                            },
                            function (\Throwable $e) use ($orderIdToCancel) {
                                $this->logger->error("Failed attempt to cancel timed-out pending entry order {$orderIdToCancel}.", ['exception' => $e->getMessage()]);
                                if ($this->activeEntryOrderId === $orderIdToCancel) {
                                     $this->checkActiveOrderStatus($orderIdToCancel, 'ENTRY_TIMEOUT_CANCEL_FAILED');
                                }
                                $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed cancellation attempt for timed-out order {$orderIdToCancel}.", 'decision' => null];
                            }
                        )->finally(function () {
                            $this->isPlacingOrManagingOrder = false; // Unlock
                        });
                    return; // Avoid immediate re-check of the same order
                }
            }

            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder) {
                $this->checkActiveOrderStatus($this->activeEntryOrderId, 'ENTRY');
            }
        });
        $this->logger->info('Fallback order check timer started', ['interval_seconds' => $this->orderCheckIntervalSeconds, 'entry_order_timeout' => $this->pendingEntryOrderCancelTimeoutSeconds]);

        $this->loop->addTimer($this->maxScriptRuntimeSeconds, function () {
            $this->logger->warning('Maximum script runtime reached. Stopping.', ['max_runtime_seconds' => $this->maxScriptRuntimeSeconds]);
            $this->stop();
        });
        $this->logger->info('Max runtime timer started', ['limit_seconds' => $this->maxScriptRuntimeSeconds]);

        $this->loop->addPeriodicTimer($this->aiUpdateIntervalSeconds, function () {
            $this->triggerAIUpdate();
        });
        $this->logger->info('AI parameter update timer started', ['interval_seconds' => $this->aiUpdateIntervalSeconds]);

        if ($this->listenKey) {
            $this->listenKeyRefreshTimer = $this->loop->addPeriodicTimer(self::LISTEN_KEY_REFRESH_INTERVAL, function () {
                if ($this->listenKey) {
                    $this->keepAliveUserDataStream($this->listenKey)->then(
                        fn() => $this->logger->info('ListenKey kept alive successfully.'),
                        fn($e) => $this->logger->error('Failed to keep ListenKey alive.', ['err' => $e->getMessage()])
                    );
                }
            });
             $this->logger->info('ListenKey refresh timer started.', ['interval_seconds' => self::LISTEN_KEY_REFRESH_INTERVAL]);
        }
    }

    private function handleWsMessage(string $msg): void
    {
        $decoded = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['stream'], $decoded['data'])) {
            $this->logger->debug('Received non-standard WebSocket message or decode error.', ['raw_msg_preview' => substr($msg,0,100)]);
            return;
        }

        $streamName = $decoded['stream'];
        $data = $decoded['data'];

        // Check if the stream name ends with '@kline_' followed by the main klineInterval
        if (str_ends_with($streamName, '@kline_' . $this->klineInterval)) {
            if (isset($data['e']) && $data['e'] === 'kline' && isset($data['k']['x']) && $data['k']['x'] === true && isset($data['k']['c'])) {
                $newPrice = (float)$data['k']['c'];
                if ($newPrice != $this->lastClosedKlinePrice) { // Log only on change
                    $this->lastClosedKlinePrice = $newPrice;
                    $this->logger->debug('Kline update received (closed)', [
                        'symbol' => $data['k']['s'],
                        'interval' => $this->klineInterval, 
                        'close_price' => $this->lastClosedKlinePrice
                    ]);
                }
            }
        } elseif ($streamName === $this->listenKey) { // This check relies on testnet WebSocket setup being correct
            $this->handleUserDataStreamEvent($data);
        } else {
            // This might happen if the listenKey is part of a path segment like /ws/listenKey
            // and not literally the stream name. Or if other streams are subscribed.
             $this->logger->debug('Received WS message on unhandled stream or mismatched listenKey path', ['stream_name' => $streamName, 'expected_listenkey_stream' => $this->listenKey]);
        }
    }

    private function handleUserDataStreamEvent(array $eventData): void
    {
        $eventType = $eventData['e'] ?? null;
        $this->logger->debug("User Data Stream Event", ['type' => $eventType, 'data_preview' => substr(json_encode($eventData), 0, 200)]);

        switch ($eventType) {
            case 'ACCOUNT_UPDATE':
                if (isset($eventData['a']['P'])) { // Position update
                    foreach($eventData['a']['P'] as $posData) {
                        if ($posData['s'] === $this->tradingSymbol) {
                            $oldPositionDetails = $this->currentPositionDetails;
                            $newPositionDetails = $this->formatPositionDetails($posData); // This filters for non-zero quantity

                            if ($newPositionDetails && !$oldPositionDetails) { // Position opened
                                $this->logger->info("Position opened/updated via ACCOUNT_UPDATE.", $newPositionDetails);
                                $this->currentPositionDetails = $newPositionDetails;
                                // Potentially trigger AI if this was unexpected
                            } elseif (!$newPositionDetails && $oldPositionDetails) { // Position closed
                                $this->logger->info("Position for {$this->tradingSymbol} detected as closed via ACCOUNT_UPDATE.", ['reason_code' => $posData['cr'] ?? 'N/A', 'closed_details' => $oldPositionDetails]);
                                $this->handlePositionClosed(); // This will reset state and cancel orders
                            } elseif ($newPositionDetails && $oldPositionDetails) { // Position modified
                                if ((float)$newPositionDetails['quantity'] !== (float)$oldPositionDetails['quantity'] ||
                                    (float)$newPositionDetails['entryPrice'] !== (float)$oldPositionDetails['entryPrice']) {
                                    $this->logger->info("Position details changed via ACCOUNT_UPDATE.", ['old' => $oldPositionDetails, 'new' => $newPositionDetails]);
                                } else {
                                     $this->logger->debug("Position details updated via ACCOUNT_UPDATE (no change in qty/entry).", $newPositionDetails);
                                }
                                $this->currentPositionDetails = $newPositionDetails;
                            }
                            // If newPositionDetails is null AND oldPositionDetails was also null, it's just an update for a zero position, no action.
                        }
                    }
                }
                if (isset($eventData['a']['B'])) { // Balance update
                     foreach($eventData['a']['B'] as $balData) {
                         if ($balData['a'] === $this->marginAsset) {
                             $this->logger->info("Balance update for {$this->marginAsset}", [
                                 'wallet_balance' => $balData['wb'],
                                 'cross_un_pnl' => $balData['cw']
                             ]);
                         }
                     }
                }
                break;

            case 'ORDER_TRADE_UPDATE':
                $order = $eventData['o'];
                $this->logger->info("Order Update Event", [
                    'symbol' => $order['s'], 'orderId' => $order['i'], 'clientOrderId' => $order['c'],
                    'status' => $order['X'], 'type' => $order['o'], 'side' => $order['S'], 'origType' => $order['ot'] ?? 'N/A',
                    'price' => $order['p'], 'quantity' => $order['q'], 'filled_qty' => $order['z'],
                    'avg_fill_price' => $order['ap'], 'reduce_only' => $order['R'] ?? 'N/A',
                    'stop_price' => $order['sp'] ?? 'N/A', 'pnl' => $order['rp'] ?? 'N/A'
                ]);

                if ($order['s'] !== $this->tradingSymbol) return;

                $orderId = (string)$order['i'];
                $orderStatus = $order['X']; // Execution Type (e.g., NEW, CANCELED, FILLED, TRADE)
                                            // Order Status is 'x' (lowercase) in some contexts, 'X' (uppercase) in others (ORDER_TRADE_UPDATE uses 'X')

                if ($orderId === $this->activeEntryOrderId) {
                    if (in_array($orderStatus, ['FILLED', 'PARTIALLY_FILLED'])) {
                        $filledQty = (float)$order['z']; // Last executed quantity for this event
                        $cumulativeFilledQty = (float)$order['l']; // Cumulative filled quantity for the order
                        $avgFilledPrice = (float)$order['ap']; // Average filled price for the order
                        
                        // Update position based on cumulative fill if it's the first fill or an update
                        if ($cumulativeFilledQty > 0) {
                             $this->currentPositionDetails = [
                                'symbol' => $this->tradingSymbol,
                                'side' => $order['S'] === 'BUY' ? 'LONG' : 'SHORT',
                                'entryPrice' => $avgFilledPrice, // Binance 'ap' is overall average
                                'quantity' => $cumulativeFilledQty,
                                'leverage' => $this->aiSuggestedLeverage, // Or fetch from position data if available
                                'markPrice' => $avgFilledPrice, // Initial mark price
                                'unrealizedPnl' => 0
                            ];
                             $this->logger->info("Entry order fill reported. Position updated.", $this->currentPositionDetails);
                        }


                        if ($orderStatus === 'FILLED') { // Check overall order status 'X'
                            $this->logger->info("Entry order fully filled: {$this->activeEntryOrderId}. Placing SL/TP orders.");
                            $this->activeEntryOrderId = null;
                            $this->activeEntryOrderTimestamp = null;
                            $this->isMissingProtectiveOrder = false; // Will be set true if SL/TP fails
                            $this->placeSlAndTpOrders();
                        }
                    } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED', 'PENDING_CANCEL'])) {
                        $this->logger->warning("Active entry order {$this->activeEntryOrderId} ended without full fill via WS: {$orderStatus}. Resetting.");
                        $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)$order['p'], (float)$order['q'], $this->marginAsset, time(), (float)($order['rp'] ?? 0));
                        $this->resetTradeState();
                        $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} ended without full fill: {$orderStatus}.", 'decision' => null];
                    }
                }
                elseif ($orderId === $this->activeSlOrderId || $orderId === $this->activeTpOrderId) {
                    if ($orderStatus === 'FILLED') {
                        $isSlFill = ($orderId === $this->activeSlOrderId);
                        $otherOrderId = $isSlFill ? $this->activeTpOrderId : $this->activeSlOrderId;
                        $logSide = 'UNKNOWN';
                        if ($this->currentPositionDetails) {
                             // This is the side of the SL/TP order itself, which is opposite to position
                             $logSide = $order['S'];
                        }
                        $this->logger->info("{$order['ot']} order {$orderId} (SL/TP) filled. Position closed.", ['realized_pnl' => $order['rp'] ?? 'N/A']);
                        $this->addOrderToLog($orderId, $orderStatus, $logSide, $this->tradingSymbol, (float)($order['ap'] > 0 ? $order['ap'] : ($order['sp'] ?? 0)), (float)$order['z'], $this->marginAsset, time(), (float)($order['rp'] ?? 0));
                        $this->handlePositionClosed($otherOrderId); // This will cancel the other order and reset state
                        $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Position closed by " . ($isSlFill ? "SL" : "TP") . " order {$orderId}.", 'decision' => null];

                    } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED', 'PENDING_CANCEL'])) {
                         $this->logger->warning("SL/TP order {$orderId} ended without fill: {$orderStatus}. Possible external cancellation or issue.");
                         if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                         if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;

                         if ($this->currentPositionDetails && !$this->activeSlOrderId && !$this->activeTpOrderId) {
                              $this->logger->critical("Position open but BOTH SL/TP orders are now gone unexpectedly. Flagging critical state.");
                              $this->isMissingProtectiveOrder = true;
                              $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Position unprotected: SL/TP order {$orderId} ended with status {$orderStatus}.", 'decision' => null];
                              $this->triggerAIUpdate(true); // Emergency AI update
                         } elseif ($this->currentPositionDetails && (!$this->activeSlOrderId || !$this->activeTpOrderId)) {
                             $this->logger->warning("Position open but ONE SL/TP order is now gone unexpectedly. Flagging potentially unsafe state.");
                              $this->isMissingProtectiveOrder = true;
                              $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Position missing one protective order: {$orderId} ended with status {$orderStatus}.", 'decision' => null];
                              $this->triggerAIUpdate(true); // Emergency AI update
                         }
                    }
                }
                break;

            case 'listenKeyExpired':
                $this->logger->warning("ListenKey expired. Attempting to get a new one and reconnect WebSocket.");
                $this->listenKey = null;
                if ($this->wsConnection) { try { $this->wsConnection->close(); } catch (\Exception $_){}}
                $this->wsConnection = null;
                $this->startUserDataStream()->then(function ($data) {
                    $this->listenKey = $data['listenKey'] ?? null;
                    if ($this->listenKey) {
                        $this->logger->info("New ListenKey obtained. Reconnecting WebSocket.");
                        // Important: The old WS connection is closed. Need to establish a new one.
                        // The previous connectWebSocket might have referenced the old listenKey in its URL.
                        $this->connectWebSocket(); // This will use the new listenKey
                    } else {
                        $this->logger->error("Failed to get new ListenKey after expiry.");
                        $this->stop();
                    }
                })->otherwise(function ($e) {
                     $this->logger->error("Error getting new ListenKey after expiry.", ['err' => $e->getMessage()]);
                     $this->stop();
                });
                break;

            case 'MARGIN_CALL':
                $this->logger->critical("MARGIN CALL RECEIVED!", $eventData['m'] ?? $eventData);
                // Margin call implies position might be liquidated or partially liquidated.
                // State needs to be re-evaluated.
                $this->isMissingProtectiveOrder = true; // Assume worst case
                $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "MARGIN CALL RECEIVED!", 'decision' => null];
                $this->triggerAIUpdate(true); // Emergency AI update
                break;

            default:
                $this->logger->debug('Unhandled user data event type', ['type' => $eventType, 'data' => $eventData]);
        }
    }

    private function formatPositionDetails(?array $positionsInput): ?array
    {
        if (empty($positionsInput)) return null;
        $positionData = null;
        // Check if it's a single position object (from ACCOUNT_UPDATE or getPositionInformation direct query)
        // or an array of positions (from getPositionInformation that might return multiple symbols if not filtered)
        $isSingleObject = !isset($positionsInput[0]) && isset($positionsInput['symbol']);

        if ($isSingleObject) {
            // Ensure it's for the trading symbol and has a non-zero quantity
            $currentQty = (float)($positionsInput['positionAmt'] ?? $positionsInput['pa'] ?? 0);
            if ($positionsInput['symbol'] === $this->tradingSymbol && $currentQty != 0) {
                $positionData = $positionsInput;
            }
        } elseif (is_array($positionsInput) && isset($positionsInput[0]['symbol'])) { // Array of positions
            foreach ($positionsInput as $p) {
                $currentQty = (float)($p['positionAmt'] ?? $p['pa'] ?? 0);
                if (isset($p['symbol']) && $p['symbol'] === $this->tradingSymbol && $currentQty != 0) {
                    $positionData = $p;
                    break;
                }
            }
        }
        
        if (!$positionData) return null; // No relevant position found or quantity is zero

        $quantityVal = (float)($positionData['positionAmt'] ?? $positionData['pa'] ?? 0);
        // If quantity is effectively zero after formatting, treat as no position
        if (abs($quantityVal) < 1e-9) return null;


        $entryPriceVal = (float)($positionData['entryPrice'] ?? $positionData['ep'] ?? 0);
        $markPriceVal = (float)($positionData['markPrice'] ?? $positionData['mp'] ?? ($this->lastClosedKlinePrice ?? 0));
        $unrealizedPnlVal = (float)($positionData['unrealizedProfit'] ?? $positionData['up'] ?? 0);
        $leverageVal = (int)($positionData['leverage'] ?? $this->defaultLeverage); // Ensure leverage is int
        $initialMarginVal = (float)($positionData['initialMargin'] ?? $positionData['iw'] ?? 0); // Or use 'im'
        $maintMarginVal = (float)($positionData['maintMargin'] ?? $positionData['mm'] ?? 0);
        $isolatedWalletVal = (float)($positionData['isolatedWallet'] ?? $positionData['maw'] ?? 0); // Margin Asset Wallet balance for isolated margin

        $side = $quantityVal > 0 ? 'LONG' : 'SHORT';

        return [
            'symbol' => $this->tradingSymbol,
            'side' => $side,
            'entryPrice' => $entryPriceVal,
            'quantity' => abs($quantityVal),
            'leverage' => $leverageVal ?: $this->defaultLeverage, // Fallback if leverage is 0
            'markPrice' => $markPriceVal,
            'unrealizedPnl' => $unrealizedPnlVal,
            'initialMargin' => $initialMarginVal,
            'maintMargin' => $maintMarginVal,
            'isolatedWallet' => $isolatedWalletVal,
            // Add positionSide if available and relevant ('ps' or 'positionSide')
            'positionSideBinance' => $positionData['positionSide'] ?? 'BOTH',
        ];
    }

    private function placeSlAndTpOrders(): void
    {
        if (!$this->currentPositionDetails) {
            $this->logger->error("Attempted to place SL/TP orders without a current position.");
            $this->isPlacingOrManagingOrder = false; // Ensure lock is released if we return early
            return;
        }
         if ($this->isPlacingOrManagingOrder) {
             $this->logger->warning("SL/TP placement already in progress or another operation locked, skipping redundant call.");
             return; // Do not release lock here, other operation owns it
         }
        $this->isPlacingOrManagingOrder = true;
        $this->isMissingProtectiveOrder = false; // Assume success initially

        $positionSide = $this->currentPositionDetails['side'];
        $quantity = (float)$this->currentPositionDetails['quantity'];
        $orderSideForSlTp = ($positionSide === 'LONG') ? 'SELL' : 'BUY';

        if ($this->aiSuggestedSlPrice <= 0 || $this->aiSuggestedTpPrice <= 0) {
             $this->logger->critical("Invalid AI SL/TP prices (<=0) during placement attempt.", ['sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice, 'position' => $this->currentPositionDetails]);
             $this->isMissingProtectiveOrder = true; // Critical failure
             $this->isPlacingOrManagingOrder = false;
             $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Invalid SL/TP prices detected at placement time. Position unprotected.", 'decision' => null];
             $this->triggerAIUpdate(true); // Emergency AI update
             return;
        }
        // Additional logic checks for SL/TP relative to position and entry price
        $entryPrice = (float)$this->currentPositionDetails['entryPrice'];
        if ($positionSide === 'LONG' && ($this->aiSuggestedSlPrice >= $entryPrice || $this->aiSuggestedTpPrice <= $entryPrice)) {
            $this->logger->critical("Illogical SL/TP for LONG position.", ['entry' => $entryPrice, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice]);
            $this->isMissingProtectiveOrder = true;
            $this->isPlacingOrManagingOrder = false;
            $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Illogical SL/TP for LONG position. Position unprotected.", 'decision' => null];
            $this->triggerAIUpdate(true);
            return;
        }
        if ($positionSide === 'SHORT' && ($this->aiSuggestedSlPrice <= $entryPrice || $this->aiSuggestedTpPrice >= $entryPrice)) {
            $this->logger->critical("Illogical SL/TP for SHORT position.", ['entry' => $entryPrice, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice]);
            $this->isMissingProtectiveOrder = true;
            $this->isPlacingOrManagingOrder = false;
            $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Illogical SL/TP for SHORT position. Position unprotected.", 'decision' => null];
            $this->triggerAIUpdate(true);
            return;
        }


        $slOrderPromise = $this->placeFuturesStopMarketOrder(
            $this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedSlPrice, true
        )->then(function ($orderData) {
            $this->activeSlOrderId = (string)$orderData['orderId'];
            $this->logger->info("Stop Loss order placed attempt successful (via API).", ['orderId' => $this->activeSlOrderId, 'stopPrice' => $this->aiSuggestedSlPrice, 'status' => $orderData['status'] ?? 'N/A']);
            return $orderData;
        });

        $tpOrderPromise = $this->placeFuturesTakeProfitMarketOrder(
            $this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedTpPrice, true
        )->then(function ($orderData) {
            $this->activeTpOrderId = (string)$orderData['orderId'];
            $this->logger->info("Take Profit order placed attempt successful (via API).", ['orderId' => $this->activeTpOrderId, 'stopPrice' => $this->aiSuggestedTpPrice, 'status' => $orderData['status'] ?? 'N/A']);
            return $orderData;
        });

        \React\Promise\all([$slOrderPromise, $tpOrderPromise])
            ->then(
                function (array $results) {
                    $slStatus = $results[0]['status'] ?? 'UNKNOWN';
                    $tpStatus = $results[1]['status'] ?? 'UNKNOWN';
                    // Order placement might result in NEW or other statuses before fill.
                    // If any are not 'NEW' or accepted, it's an issue.
                    // For simplicity, we log and assume WS will confirm. If issues, `isMissingProtectiveOrder` will be set by other logic.
                    $this->logger->info("SL and TP orders placement requests sent.", [
                        'sl_order_id' => $this->activeSlOrderId, 'sl_status_api' => $slStatus,
                        'tp_order_id' => $this->activeTpOrderId, 'tp_status_api' => $tpStatus
                        ]);
                    // isMissingProtectiveOrder remains false unless an error occurs or WS indicates an issue
                    $this->isPlacingOrManagingOrder = false; // Unlock after both attempted
                    // Don't set lastAIDecisionResult here, let the entry order success dictate the overall success
                },
                function (\Throwable $e) {
                    // This block handles failure in *placing* one of the orders via API
                    $this->logger->critical("CRITICAL: Error placing one or both SL/TP orders API request failed. Position might be unprotected!", [
                        'exception_class' => get_class($e),
                        'exception' => $e->getMessage(),
                        'current_sl_id_state' => $this->activeSlOrderId, // This might be set if one succeeded before the other failed
                        'current_tp_id_state' => $this->activeTpOrderId, // Same here
                        'position_details' => $this->currentPositionDetails
                    ]);
                    // Check which ones are missing IDs and flag
                    if (!$this->activeSlOrderId || !$this->activeTpOrderId) {
                        $this->isMissingProtectiveOrder = true;
                    }
                    $this->isPlacingOrManagingOrder = false; // Unlock
                    $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Failed placing SL/TP orders (API error: ".$e->getMessage()."). Position potentially unprotected.", 'decision' => null];
                    $this->triggerAIUpdate(true); // Emergency AI update
                }
            );
    }

    private function handlePositionClosed(?string $otherOrderIdToCancel = null): void
    {
        $closedPositionDetails = $this->currentPositionDetails; // Log details before reset
        $this->logger->info("Position closed action triggered for {$this->tradingSymbol}.", ['details_before_reset' => $closedPositionDetails, 'other_order_to_cancel' => $otherOrderIdToCancel]);

        $cancelPromises = [];
        if ($otherOrderIdToCancel) {
            $cancelPromises[] = $this->cancelOrderAndLog($otherOrderIdToCancel, "remaining SL/TP during position close");
        } else {
            // If no specific other order, try to cancel any known SL/TP
            if ($this->activeSlOrderId) {
                $cancelPromises[] = $this->cancelOrderAndLog($this->activeSlOrderId, "active SL on position close");
            }
            if ($this->activeTpOrderId) {
                $cancelPromises[] = $this->cancelOrderAndLog($this->activeTpOrderId, "active TP on position close");
            }
        }

        if (!empty($cancelPromises)) {
            \React\Promise\all($cancelPromises)->finally(function() {
                // All cancellation attempts (successful or not) are done. Reset state.
                $this->resetTradeState();
                $this->logger->info("Trade state reset after position close and cancellation attempts.");
                // Trigger AI update after a short delay to allow dust to settle
                $this->loop->addTimer(5, function () { $this->triggerAIUpdate(); });
            });
        } else {
            // No orders to cancel, just reset state
            $this->resetTradeState();
            $this->logger->info("Trade state reset after position close (no SL/TP orders were active to cancel).");
            $this->loop->addTimer(5, function () { $this->triggerAIUpdate(); });
        }
    }

    private function cancelOrderAndLog(string $orderId, string $reasonForCancel): PromiseInterface {
        $deferred = new Deferred();
        $this->cancelFuturesOrder($this->tradingSymbol, $orderId)->then(
            function($data) use ($orderId, $reasonForCancel, $deferred) {
                $this->logger->info("Successfully cancelled order: {$orderId} ({$reasonForCancel}).", ['api_response_status' => $data['status'] ?? 'N/A']);
                if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
                $deferred->resolve($data);
            },
            function ($e) use ($orderId, $reasonForCancel, $deferred) {
                 // Error -2011: "Unknown order sent." - means it was already filled, canceled, or never existed.
                 if (str_contains($e->getMessage(), '-2011') || stripos($e->getMessage(), "order does not exist") !== false) {
                     $this->logger->info("Attempt to cancel order {$orderId} ({$reasonForCancel}) failed, likely already resolved/gone.", ['err_preview' => substr($e->getMessage(),0,100)]);
                 } else {
                     $this->logger->error("Failed to cancel order: {$orderId} ({$reasonForCancel}).", ['err' => $e->getMessage()]);
                 }
                 // Even on failure, clear the order ID from state if it matches
                if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
                $deferred->reject($e); // Propagate error if needed, but mostly for logging here
            }
        );
        return $deferred->promise();
    }


    private function resetTradeState(): void {
        $this->logger->info("Resetting trade state.", [
            'active_entry_before' => $this->activeEntryOrderId,
            'active_sl_before' => $this->activeSlOrderId,
            'active_tp_before' => $this->activeTpOrderId,
            'position_before' => !is_null($this->currentPositionDetails)
        ]);
        $this->activeEntryOrderId = null;
        $this->activeEntryOrderTimestamp = null;
        $this->activeSlOrderId = null;
        $this->activeTpOrderId = null;
        $this->currentPositionDetails = null;
        $this->isPlacingOrManagingOrder = false;
        $this->isMissingProtectiveOrder = false;
        // Do not reset $this->lastAIDecisionResult here, it's valuable for the next AI cycle
    }

    private function addOrderToLog(string $orderId, string $status, string $side, string $assetPair, ?float $limitPrice, ?float $amountUsed, ?string $amountAsset, int $timestamp, ?float $realizedPnl): void
    {
        $logEntry = [
            'orderId' => $orderId,
            'status' => $status,
            'side' => $side,
            'assetPair' => $assetPair,
            'price' => $limitPrice, // This could be avg fill price for filled orders
            'quantity' => $amountUsed, // This could be filled quantity
            'marginAsset' => $amountAsset,
            'timestamp' => date('Y-m-d H:i:s', $timestamp),
            'realizedPnl' => $realizedPnl,
        ];
        array_unshift($this->recentOrderLogs, $logEntry);
        $this->recentOrderLogs = array_slice($this->recentOrderLogs, 0, self::MAX_ORDER_LOG_ENTRIES);
        $this->logger->info('Futures trade outcome/order event logged for AI', $logEntry);
    }

    private function attemptOpenPosition(): void
    {
        if ($this->currentPositionDetails || $this->activeEntryOrderId || $this->isPlacingOrManagingOrder) {
            $this->logger->info('Skipping position opening: existing position, active entry order, or operation in progress.',[
                'has_pos' => !is_null($this->currentPositionDetails),
                'has_entry_order' => !is_null($this->activeEntryOrderId),
                'is_managing' => $this->isPlacingOrManagingOrder
            ]);
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped OPEN_POSITION: Pre-condition not met (in position, pending entry, or op in progress).', 'decision' => ['action' => 'OPEN_POSITION']]; // AI made this decision
            return;
        }

        // Parameters should have been validated by executeAIDecision before calling this.
        // Redundant check for safety.
        if ($this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedQuantity <= 0 || $this->aiSuggestedSlPrice <=0 || $this->aiSuggestedTpPrice <= 0) {
            $this->logger->error("CRITICAL INTERNAL ERROR: AttemptOpenPosition called with invalid AI parameters (zero/negative). Should have been caught earlier.", [
                 'entry' => $this->aiSuggestedEntryPrice, 'qty' => $this->aiSuggestedQuantity, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice
            ]);
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => 'Internal Error: OPEN_POSITION rejected due to invalid parameters at execution.', 'decision' => ['action' => 'OPEN_POSITION']];
            return;
        }

        $this->isPlacingOrManagingOrder = true;
        $aiParamsForLog = [
            'side' => $this->aiSuggestedSide, 'quantity' => $this->aiSuggestedQuantity,
            'entry' => $this->aiSuggestedEntryPrice, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice,
            'leverage' => $this->aiSuggestedLeverage
        ];
        $this->logger->info('Attempting to open new position based on AI.', $aiParamsForLog);

        $this->setLeverage($this->tradingSymbol, $this->aiSuggestedLeverage)
            ->then(function () {
                // Ensure leverage is set before placing order.
                // Add a small delay if needed, though typically API calls are sequential.
                // return \React\Promise\Timer\resolve(0.1, $this->loop)->then(function() { // Optional small delay
                    return $this->placeFuturesLimitOrder(
                        $this->tradingSymbol,
                        $this->aiSuggestedSide,
                        $this->aiSuggestedQuantity,
                        $this->aiSuggestedEntryPrice
                    );
                // });
            })
            ->then(function ($orderData) use ($aiParamsForLog) {
                $this->activeEntryOrderId = (string)$orderData['orderId'];
                $this->activeEntryOrderTimestamp = time();
                $this->logger->info("Entry limit order placed successfully via API.", [
                    'orderId' => $this->activeEntryOrderId,
                    'clientOrderId' => $orderData['clientOrderId'],
                    'status_api' => $orderData['status'] ?? 'N/A',
                    'placement_timestamp' => date('Y-m-d H:i:s', $this->activeEntryOrderTimestamp)
                ]);
                 // Success here means the order was accepted by Binance. WS will confirm fill.
                 $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed entry order {$this->activeEntryOrderId}.", 'decision_executed' => ['action' => 'OPEN_POSITION'] + $aiParamsForLog];
                $this->isPlacingOrManagingOrder = false;
            })
            ->catch(function (\Throwable $e) use ($aiParamsForLog) {
                $this->logger->error('Failed to open position (set leverage or place order).', [
                    'exception_class' => get_class($e), 'exception' => $e->getMessage(),
                    'ai_params' => $aiParamsForLog
                ]);
                 $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed to place entry order: " . $e->getMessage(), 'decision_attempted' => ['action' => 'OPEN_POSITION'] + $aiParamsForLog];
                $this->isPlacingOrManagingOrder = false;
                $this->resetTradeState(); // Clean up if entry placement failed
            });
    }
    private function attemptClosePositionByAI(): void
    {
        if (!$this->currentPositionDetails) {
            $this->logger->info('Skipping AI close: No position exists.');
            $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped CLOSE_POSITION: No position exists.', 'decision' => ['action' => 'CLOSE_POSITION']];
            return;
        }
         if ($this->isPlacingOrManagingOrder){
             $this->logger->info('Skipping AI close: Operation already in progress.');
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped CLOSE_POSITION: Operation in progress.', 'decision' => ['action' => 'CLOSE_POSITION']];
             return;
         }

        $this->isPlacingOrManagingOrder = true;
        $positionToClose = $this->currentPositionDetails; // Copy for logging
        $this->logger->info("AI requests to close current position for {$this->tradingSymbol} at market.", ['position' => $positionToClose]);

        // Cancel existing SL/TP orders first
        $cancellationPromises = [];
        $slIdToCancel = $this->activeSlOrderId; // Store before nulling
        $tpIdToCancel = $this->activeTpOrderId; // Store before nulling

        if ($slIdToCancel) {
            $cancellationPromises[] = $this->cancelOrderAndLog($slIdToCancel, "SL for AI market close");
        }
        if ($tpIdToCancel) {
            $cancellationPromises[] = $this->cancelOrderAndLog($tpIdToCancel, "TP for AI market close");
        }
        
        // activeSlOrderId and activeTpOrderId are nulled by cancelOrderAndLog if successful or order not found

        \React\Promise\all($cancellationPromises)->then(function() use ($positionToClose) {
            // All cancellations attempted (or none if no orders). Proceed to market close.
            // Refresh position details just in case it was closed by one of the SL/TPs already
            return $this->getPositionInformation($this->tradingSymbol)->then(function($refreshedPositionData) use ($positionToClose){
                $this->currentPositionDetails = $this->formatPositionDetails($refreshedPositionData);
                if (!$this->currentPositionDetails) {
                    $this->logger->info("Position already closed before market order placement (likely by SL/TP cancellation race).", ['original_pos_to_close' => $positionToClose]);
                    // No need to place market order. handlePositionClosed would have been called by SL/TP fill.
                    // Or if not (e.g. manual cancel), reset state here.
                    if (!$this->isPlacingOrManagingOrder) { // if handlePositionClosed already reset this, don't trigger AI again from here
                         $this->resetTradeState(); // Ensure state is clean
                         // $this->triggerAIUpdate(); // Let handlePositionClosed do this or the natural cycle
                    }
                    return \React\Promise\resolve(['status' => 'ALREADY_CLOSED', 'orderId' => 'N/A_ALREADY_CLOSED']); // Dummy resolved promise
                }

                // Position still exists, proceed with market close
                $closeSide = $this->currentPositionDetails['side'] === 'LONG' ? 'SELL' : 'BUY';
                $quantityToClose = $this->currentPositionDetails['quantity'];
                $this->logger->info("Attempting to place market order to close position after cancelling SL/TP.", ['side' => $closeSide, 'quantity' => $quantityToClose, 'current_pos_details' => $this->currentPositionDetails]);
                return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $quantityToClose, true); // reduceOnly = true
            });
        })->then(function($closeOrderData) use ($positionToClose, $slIdToCancel, $tpIdToCancel) {
            if (($closeOrderData['status'] ?? '') === 'ALREADY_CLOSED') {
                 $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Position found already closed during AI close sequence.", 'decision_executed' => ['action' => 'CLOSE_POSITION', 'original_pos' => $positionToClose]];
            } else {
                $this->logger->info("Market order placed by AI to close position.", [
                    'orderId' => $closeOrderData['orderId'],
                    'status_api' => $closeOrderData['status'] // Should be FILLED or NEW then FILLED via WS
                ]);
                // WS will confirm fill and trigger handlePositionClosed.
                // Here we log the AI's intent fulfillment.
                $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed market close order {$closeOrderData['orderId']}.", 'decision_executed' => ['action' => 'CLOSE_POSITION', 'cancelled_sl' => $slIdToCancel, 'cancelled_tp' => $tpIdToCancel]];
            }
        })->catch(function(\Throwable $e) use ($positionToClose) {
            $this->logger->error("Error during AI-driven position close process.", ['exception' => $e->getMessage(), 'position_at_time_of_decision' => $positionToClose]);
            // State might be uncertain. SL/TP might be cancelled, market order failed.
            $this->isMissingProtectiveOrder = true; // Position might be unprotected
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Error during AI close: " . $e->getMessage(), 'decision_attempted' => ['action' => 'CLOSE_POSITION']];
            $this->triggerAIUpdate(true); // Emergency AI update
        })->finally(function() {
            $this->isPlacingOrManagingOrder = false; // Unlock regardless of outcome
        });
    }

    private function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $timestamp = round(microtime(true) * 1000);
        $params['timestamp'] = $timestamp;
        $params['recvWindow'] = self::BINANCE_API_RECV_WINDOW;

        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986); // RFC3986 for spaces becoming %20 not +
        
        $signature = hash_hmac('sha256', $queryString, $this->binanceApiSecret);

        $url = $this->currentRestApiBaseUrl . $endpoint;
        $body = null;

        if ($method === 'GET' || $method === 'DELETE') { // DELETE can also have query params
            $url .= '?' . $queryString . '&signature=' . $signature;
        } else { // POST, PUT
            // For POST/PUT, params are typically in the body, not query string for signing (though Binance varies)
            // Binance API usually signs all parameters, whether in query or body.
            // The $queryString already contains all params.
            // If method is POST/PUT, these params become the body.
            $body = $queryString . '&signature=' . $signature;
        }
        return ['url' => $url, 'headers' => ['X-MBX-APIKEY' => $this->binanceApiKey], 'postData' => $body];
    }

    private function makeAsyncApiRequest(string $method, string $url, array $headers = [], ?string $body = null, bool $isPublic = false): PromiseInterface
    {
        $options = ['follow_redirects' => false, 'timeout' => 15.0];
        $finalHeaders = $headers;
        if (!$isPublic && !isset($finalHeaders['X-MBX-APIKEY'])) {
            $finalHeaders['X-MBX-APIKEY'] = $this->binanceApiKey;
        }

        // For POST/PUT/DELETE with body, set Content-Type
        if (in_array($method, ['POST', 'PUT', 'DELETE']) && is_string($body) && !empty($body)) {
            $finalHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
            $requestPromise = $this->browser->request($method, $url, $finalHeaders, $body, $options);
        } else {
            // For GET or bodyless requests
            $requestPromise = $this->browser->request($method, $url, $finalHeaders, '', $options);
        }
        

        return $requestPromise->then(
            function (ResponseInterface $response) use ($method, $url) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $logCtx = ['method' => $method, 'url_path' => parse_url($url, PHP_URL_PATH), 'status' => $statusCode];
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('Failed to decode API JSON response', $logCtx + ['json_err' => json_last_error_msg(), 'body_preview' => substr($body, 0, 200)]);
                    throw new \RuntimeException("JSON decode error: " . json_last_error_msg() . " for " . $url . ". Response body: " . substr($body,0,200) );
                }
                // Binance specific error check: code < 0 indicates an API error, but -2011 (Unknown order) is often ignorable for cancellations.
                // We handle -2011 specifically where needed (e.g., in cancelOrderAndLog).
                if (isset($data['code']) && (int)$data['code'] < 0 ) { // Simpler check, handle specific codes upstream if needed
                    $this->logger->error('Binance Futures API Error reported in response body', $logCtx + ['api_code' => $data['code'], 'api_msg' => $data['msg'] ?? 'N/A', 'response_body' => $body]);
                    throw new \RuntimeException("Binance Futures API error ({$data['code']}): " . ($data['msg'] ?? 'Unknown error') . " for " . $url);
                }
                // HTTP status code check (e.g. 4xx, 5xx)
                if ($statusCode >= 300) {
                     $this->logger->error('HTTP Error Status received from API', $logCtx + ['api_code_in_body' => $data['code'] ?? 'N/A', 'api_msg_in_body' => $data['msg'] ?? 'N/A', 'response_body' => $body]);
                     throw new \RuntimeException("HTTP error {$statusCode} for " . $url . ". Body: " . $body);
                }
                return $data;
            },
            function (\Throwable $e) use ($method, $url) {
                $logCtx = ['method' => $method, 'url_path' => parse_url($url, PHP_URL_PATH), 'err_type' => get_class($e), 'err_msg' => $e->getMessage()];
                if ($e instanceof \React\Http\Message\ResponseException) {
                    $response = $e->getResponse();
                    $logCtx['response_status_code'] = $response->getStatusCode();
                    $responseBody = (string) $response->getBody();
                    $logCtx['response_body_preview'] = substr($responseBody, 0, 500);
                    // Try to parse Binance error from body if available
                    $responseData = json_decode($responseBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($responseData['code'], $responseData['msg'])) {
                        $logCtx['binance_api_code_from_exception'] = $responseData['code'];
                        $logCtx['binance_api_msg_from_exception'] = $responseData['msg'];
                         // Throw a more specific exception if it's a Binance API error within an HTTP error response
                        throw new \RuntimeException("Binance API error via HTTP Exception ({$responseData['code']}): {$responseData['msg']} for {$method} " . parse_url($url, PHP_URL_PATH), (int)$responseData['code'], $e);
                    }
                }
                $this->logger->error('API Request Failed (Promise Rejected or Network Error)', $logCtx);
                throw new \RuntimeException("API Request failure for {$method} " . parse_url($url, PHP_URL_PATH) . ": " . $e->getMessage(), 0, $e);
            }
        );
    }

    // Precision formatters - ensure they match symbol's requirements if more symbols are used.
    // These are for formatting numbers to strings for API requests.
    private function getPricePrecisionFormat(string $symbol): string { 
        // Example: BTCUSDT uses 1 decimal for price. Others vary.
        // This could be fetched from exchangeInfo if needed.
        if ($symbol === 'BTCUSDT') return '%.1f';
        return '%.8f'; // Default, adjust as needed
    }
    private function getQuantityPrecisionFormat(string $symbol): string {
        // Example: BTCUSDT uses 3 decimals for quantity. Others vary.
        if ($symbol === 'BTCUSDT') return '%.3f';
        return '%.8f'; // Default, adjust as needed
    }

    private function getFuturesAccountBalance(): PromiseInterface {
        $endpoint = '/fapi/v2/balance';
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) {
                 if (!is_array($data)) throw new \RuntimeException("Invalid response for getFuturesAccountBalance: not an array.");
                $balances = [];
                foreach ($data as $assetInfo) {
                    if (isset($assetInfo['asset'], $assetInfo['balance'], $assetInfo['availableBalance'])) {
                        $balances[strtoupper($assetInfo['asset'])] = [
                            'balance' => (float)$assetInfo['balance'],
                            'availableBalance' => (float)$assetInfo['availableBalance']
                        ];
                    }
                }
                $this->logger->debug("Fetched futures balances", ['margin_asset' => $this->marginAsset, 'balance_data_for_margin_asset' => $balances[$this->marginAsset] ?? 'N/A']);
                return $balances;
            });
    }

    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface {
        $endpoint = '/fapi/v1/klines';
        $params = ['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => 1];
        // Public endpoint, no signing needed for klines
        $url = $this->currentRestApiBaseUrl . $endpoint . '?' . http_build_query($params);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true) // isPublic = true
             ->then(function ($data) use ($interval, $symbol) {
                 if (!is_array($data) || empty($data) || !isset($data[0][4])) { // Index 4 is close price
                    throw new \RuntimeException("Invalid klines response format for {$symbol}, interval {$interval}. Data: " . json_encode($data));
                 }
                $price = (float)$data[0][4];
                if ($price <=0) throw new \RuntimeException("Invalid kline price: {$price} for {$symbol}, interval {$interval}");
                return ['price' => $price, 'timestamp' => (int)$data[0][0]]; // Index 0 is open time
            });
    }

    private function getHistoricalKlines(string $symbol, string $interval, int $limit = 100): PromiseInterface {
        $endpoint = '/fapi/v1/klines';
        $params = ['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => min($limit, 1500)]; // Max limit 1500 for klines
        $url = $this->currentRestApiBaseUrl . $endpoint . '?' . http_build_query($params);
        $this->logger->debug("Fetching historical klines", ['symbol' => $symbol, 'interval' => $interval, 'limit' => $params['limit']]);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true) // isPublic = true
            ->then(function ($data) use ($symbol, $interval, $limit){
                if (!is_array($data)) {
                    $this->logger->warning("Invalid historical klines response format, expected array.", ['symbol' => $symbol, 'interval' => $interval, 'response_type' => gettype($data)]);
                    throw new \RuntimeException("Invalid klines response format for {$symbol}, interval {$interval}. Expected array.");
                }
                $formattedKlines = array_map(function($kline) {
                    // Standard kline format: [Open time, Open, High, Low, Close, Volume, Close time, Quote asset volume, Number of trades, Taker buy base asset volume, Taker buy quote asset volume, Ignore]
                    if (is_array($kline) && count($kline) >= 6) { 
                        return [
                            'openTime' => (int)$kline[0],
                            'open' => (string)$kline[1],
                            'high' => (string)$kline[2],
                            'low' => (string)$kline[3],
                            'close' => (string)$kline[4],
                            'volume' => (string)$kline[5],
                            // 'closeTime' => (int)$kline[6], // Optionally include
                        ];
                    } return null;
                }, $data);
                $formattedKlines = array_filter($formattedKlines); 
                $this->logger->debug("Fetched historical klines successfully", ['symbol' => $symbol, 'interval' => $interval, 'count_fetched' => count($formattedKlines)]);
                return $formattedKlines;
            });
    }

    private function getPositionInformation(string $symbol): PromiseInterface {
        $endpoint = '/fapi/v2/positionRisk'; // v2 is generally preferred
        $params = ['symbol' => strtoupper($symbol)];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) use ($symbol) {
                 if (!is_array($data)) throw new \RuntimeException("Invalid response for getPositionInformation for {$symbol}: not an array.");
                // positionRisk can return an array of positions if symbol is not specified.
                // If symbol is specified, it should return an array with one element (or empty if no such symbol traded).
                $positionToReturn = null;
                if (!empty($data)) {
                    // Find the specific symbol if multiple are returned (should not happen if symbol is in params)
                    foreach ($data as $pos) {
                        if (isset($pos['symbol']) && $pos['symbol'] === strtoupper($symbol)) {
                            $positionToReturn = $pos;
                            break;
                        }
                    }
                }
                
                if ($positionToReturn) {
                    $this->logger->debug("Fetched position information for {$symbol}", ['position_data_preview' => substr(json_encode($positionToReturn),0,150)]);
                } else {
                    $this->logger->debug("No active position or specific position data found for {$symbol} via getPositionInformation. This is normal if no position exists.", ['raw_response_preview' => substr(json_encode($data),0,150)]);
                }
                return $positionToReturn; // Can be null if no position or symbol not found
            });
    }

    private function setLeverage(string $symbol, int $leverage): PromiseInterface {
        $endpoint = '/fapi/v1/leverage';
        $params = ['symbol' => strtoupper($symbol), 'leverage' => $leverage];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
             ->then(function ($data) use ($symbol, $leverage) {
                 $this->logger->info("Leverage set attempt result for {$symbol}", ['requested' => $leverage, 'response_symbol' => $data['symbol'] ?? 'N/A', 'response_leverage' => $data['leverage'] ?? 'N/A', 'response_msg' => $data['msg'] ?? ($data['message'] ?? 'N/A')]);
                 if (($data['leverage'] ?? null) != $leverage && ($data['code'] ?? 200) == 200) {
                     // Sometimes API responds with 200 OK but a message like "Leverage not modified" if it's already set.
                     if (isset($data['msg']) && stripos($data['msg'], "leverage not modified") !== false) {
                         $this->logger->info("Leverage for {$symbol} was already {$leverage}. Not modified.");
                     } else {
                        // This case might not happen if API errors are thrown by makeAsyncApiRequest
                        // $this->logger->warning("Leverage set for {$symbol}, but response value mismatch.", ['requested' => $leverage, 'responded' => $data['leverage'] ?? 'N/A']);
                     }
                 }
                return $data;
            });
    }

    private function placeFuturesLimitOrder(string $symbol, string $side, float $quantity, float $price, ?string $timeInForce = 'GTC', ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($price <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid price/quantity for limit order. P:{$price} Q:{$quantity} for {$symbol}"));
        
        $params = [
            'symbol' => strtoupper($symbol), 
            'side' => strtoupper($side), 
            'positionSide' => strtoupper($positionSide), // BOTH, LONG, SHORT (for Hedge Mode)
            'type' => 'LIMIT', 
            'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
            'price' => sprintf($this->getPricePrecisionFormat($symbol), $price), 
            'timeInForce' => $timeInForce,
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesMarketOrder(string $symbol, string $side, float $quantity, ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid quantity for market order. Q:{$quantity} for {$symbol}"));
        
        $params = [
            'symbol' => strtoupper($symbol), 
            'side' => strtoupper($side), 
            'positionSide' => strtoupper($positionSide),
            'type' => 'MARKET', 
            'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesStopMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice, bool $reduceOnly = true, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for STOP_MARKET. SP:{$stopPrice} Q:{$quantity} for {$symbol}"));
        
        $params = [
            'symbol' => strtoupper($symbol), 
            'side' => strtoupper($side), 
            'positionSide' => strtoupper($positionSide),
            'type' => 'STOP_MARKET', 
            'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
            'stopPrice' => sprintf($this->getPricePrecisionFormat($symbol), $stopPrice),
            'reduceOnly' => $reduceOnly ? 'true' : 'false', 
            'workingType' => 'MARK_PRICE' // Or CONTRACT_PRICE. MARK_PRICE is usually preferred for SL/TP to avoid bad fills on wicks.
            // 'priceProtect' => 'TRUE' // For STOP_MARKET and TAKE_PROFIT_MARKET, protects against extreme price slippage. Optional.
        ];

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesTakeProfitMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice, bool $reduceOnly = true, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
         if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for TAKE_PROFIT_MARKET. SP:{$stopPrice} Q:{$quantity} for {$symbol}"));
        
        $params = [
            'symbol' => strtoupper($symbol), 
            'side' => strtoupper($side), 
            'positionSide' => strtoupper($positionSide),
            'type' => 'TAKE_PROFIT_MARKET', 
            'quantity' => sprintf($this->getQuantityPrecisionFormat($symbol), $quantity),
            'stopPrice' => sprintf($this->getPricePrecisionFormat($symbol), $stopPrice),
            'reduceOnly' => $reduceOnly ? 'true' : 'false', 
            'workingType' => 'MARK_PRICE'
        ];

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function getFuturesOrderStatus(string $symbol, string $orderId): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        $params = ['symbol' => strtoupper($symbol), 'orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers']);
    }

    private function checkActiveOrderStatus(string $orderId, string $orderTypeLabel): void {
        if ($this->isPlacingOrManagingOrder) {
             $this->logger->debug("Skipping status check for {$orderId} ({$orderTypeLabel}) as an operation is in progress.");
             return;
        }
        $this->getFuturesOrderStatus($this->tradingSymbol, $orderId)
        ->then(function (array $orderStatusData) use ($orderId, $orderTypeLabel) {
            $status = $orderStatusData['status'] ?? 'UNKNOWN';
            $this->logger->debug("Checked {$orderTypeLabel} order status (fallback)", ['orderId' => $orderId, 'status' => $status, 'api_data' => $orderStatusData]);

             // This fallback is crucial if WebSocket messages are missed or misinterpreted.
             if (($orderTypeLabel === 'ENTRY' || $orderTypeLabel === 'ENTRY_TIMEOUT_CANCEL_FAILED') && $this->activeEntryOrderId === $orderId) {
                 if (in_array($status, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                     $this->logger->warning("Fallback: Active entry order {$orderId} found as {$status}. Resetting state.");
                     $this->addOrderToLog($orderId, $status, $orderStatusData['side'] ?? 'N/A', $this->tradingSymbol, (float)($orderStatusData['price'] ?? 0), (float)($orderStatusData['origQty'] ?? 0), $this->marginAsset, time(), (float)($orderStatusData['realizedPnl'] ?? 0));
                     $this->resetTradeState();
                     $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} found {$status} via fallback. State reset.", 'decision' => null];
                 } elseif ($status === 'FILLED' || ($status === 'PARTIALLY_FILLED' && (float)($orderStatusData['executedQty'] ?? 0) > 0) ) {
                     $this->logger->critical("CRITICAL FALLBACK: Entry order {$orderId} found {$status} by fallback check. WS might have missed this. Updating position and attempting SL/TP.", ['order_data' => $orderStatusData]);
                     
                     $filledQty = (float)($orderStatusData['executedQty'] ?? 0);
                     $avgFilledPrice = (float)($orderStatusData['avgPrice'] ?? 0);

                     if ($filledQty > 0 && $avgFilledPrice > 0) {
                         // Update internal position state
                         $this->currentPositionDetails = [
                            'symbol' => $this->tradingSymbol,
                            'side' => $orderStatusData['side'] === 'BUY' ? 'LONG' : 'SHORT',
                            'entryPrice' => $avgFilledPrice, 
                            'quantity' => $filledQty,
                            'leverage' => (int)($this->currentPositionDetails['leverage'] ?? $this->aiSuggestedLeverage), // Use existing or AI's
                            'markPrice' => $avgFilledPrice, 
                            'unrealizedPnl' => 0 // Initial PNL is 0
                        ];
                         $this->logger->info("Position details updated from fallback {$status} check.", $this->currentPositionDetails);
                         
                         // If fully FILLED, clear entry order state and place SL/TP
                         if ($status === 'FILLED') {
                            $this->activeEntryOrderId = null;
                            $this->activeEntryOrderTimestamp = null;
                            $this->isMissingProtectiveOrder = false; // Will be set true if SL/TP fails
                            $this->placeSlAndTpOrders();
                            $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Entry order {$orderId} FILLED via fallback. SL/TP placement initiated.", 'decision' => null];
                         } else { // PARTIALLY_FILLED
                            $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Entry order {$orderId} PARTIALLY_FILLED via fallback. Position updated.", 'decision' => null];
                            // For partial fills, we might not place SL/TP yet, or place for the partial amount.
                            // Current logic places SL/TP on full fill. This might need adjustment if partial fills are common.
                         }
                     } else {
                         $this->logger->error("Could not update position from fallback {$status} check due to missing fill data.", ['order_data' => $orderStatusData]);
                         $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Entry order {$orderId} {$status} via fallback, but fill data missing. State uncertain.", 'decision' => null];
                     }
                 }
             }
             // Add similar checks for SL/TP orders if necessary, though usually their fills lead to position closure handled by WS.
        })
        ->catch(function (\Throwable $e) use ($orderId, $orderTypeLabel) {
            // -2013: "Order does not exist."
            if (str_contains($e->getMessage(), '-2013') || stripos($e->getMessage(), 'Order does not exist') !== false) {
                $this->logger->info("{$orderTypeLabel} order {$orderId} not found on exchange (fallback check). Likely resolved or never existed properly.", ['err_preview' => substr($e->getMessage(),0,100)]);
                 if (($orderTypeLabel === 'ENTRY' || $orderTypeLabel === 'ENTRY_TIMEOUT_CANCEL_FAILED') && $this->activeEntryOrderId === $orderId) {
                    $this->logger->warning("Active entry order {$orderId} disappeared from exchange according to fallback. Resetting state.");
                    // Add to log as 'UNKNOWN_DISAPPEARED' or similar if needed
                    $this->resetTradeState();
                    $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} not found via fallback and was active. State reset.", 'decision' => null];
                 }
            } else {
                $this->logger->error("Failed to get {$orderTypeLabel} order status (fallback).", ['orderId' => $orderId, 'exception_class' => get_class($e), 'exception' => $e->getMessage()]);
            }
        });
    }

    private function cancelFuturesOrder(string $symbol, string $orderId): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        $params = ['symbol' => strtoupper($symbol), 'orderId' => $orderId];
        // origClientOrderId could also be used if known
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function($data) use ($orderId, $symbol){ // Added symbol for logging
                $this->logger->info("Cancel order request processed for {$orderId} on {$symbol}", ['response_status' => $data['status'] ?? 'N/A', 'response_orderId' => $data['orderId'] ?? 'N/A', 'full_response' => $data]);
                return $data;
            })
            ->catch(function(\Throwable $e) use ($orderId, $symbol){ // Catch specific cancellation errors here if needed
                // Error -2011: "Unknown order sent." (already filled, cancelled, or never existed)
                // This is often not a "failure" in the context of blanket cancellations.
                if (str_contains($e->getMessage(), '-2011') || stripos($e->getMessage(), "order does not exist") !== false) {
                     $this->logger->info("Cancel order {$orderId} on {$symbol}: Order likely already resolved/gone (e.g., filled, previously cancelled).", ['error_code' => '-2011', 'message' => $e->getMessage()]);
                     // Resolve with a mock success or specific status if upstream needs to know it's "gone"
                     return ['status' => 'ALREADY_RESOLVED', 'orderId' => $orderId, 'symbol' => $symbol, 'message' => $e->getMessage()]; 
                }
                // For other errors, rethrow to be caught by caller
                $this->logger->error("Cancel order request failed for {$orderId} on {$symbol}", ['exception' => $e->getMessage()]);
                throw $e;
            });
    }

    private function getFuturesTradeHistory(string $symbol, int $limit = 50): PromiseInterface {
        $endpoint = '/fapi/v1/userTrades';
        $params = ['symbol' => strtoupper($symbol), 'limit' => min($limit, 1000)]; // Max limit 1000
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function($data) use ($symbol) {
                $this->logger->debug("Fetched recent futures trades for {$symbol}", ['count' => is_array($data) ? count($data) : 'N/A']);
                return $data; // Returns array of trade objects
            });
    }

    private function startUserDataStream(): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        // This is a POST request, but typically doesn't need a body for creation
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'POST'); 
        // Ensure 'postData' is null or empty if POST has no body, or let makeAsyncApiRequest handle it.
        // createSignedRequestData for POST will create a body from params + signature. If params is empty, body is just signature.
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function keepAliveUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        // This is a PUT request. Params in query string.
        $signedRequestData = $this->createSignedRequestData($endpoint, ['listenKey' => $listenKey], 'PUT');
        // For PUT with query params, body might be empty or not needed by createSignedRequestData.
        // Let's ensure postData is what Binance expects for PUT /listenKey
        // Binance PUT /listenKey: params in query string, no body. Signature on query params.
        // Our createSignedRequestData for POST/PUT currently puts all params in body.
        // Let's adjust createSignedRequestData or ensure this call works.
        // If createSignedRequestData for PUT creates a body when it shouldn't:
        // $signedRequestData['postData'] = null; // if PUT should have no body.
        // However, Binance typically wants signed params in body for POST/PUT.
        // Let's assume current createSignedRequestData is fine.
        return $this->makeAsyncApiRequest('PUT', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function closeUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        // DELETE request. Params in query string.
        $signedRequestData = $this->createSignedRequestData($endpoint, ['listenKey' => $listenKey], 'DELETE');
        // Similar to PUT, ensure body handling is correct for DELETE.
        // Binance DELETE /listenKey: params in query string, no body. Signature on query params.
        // $signedRequestData['postData'] = null; // if DELETE should have no body.
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    public function triggerAIUpdate(bool $isEmergency = false): void
    {
        if ($this->isPlacingOrManagingOrder && !$isEmergency) {
            $this->logger->debug("AI Update: Operation in progress, skipping non-emergency AI update cycle.");
            return;
        }
        $this->logger->info('Starting AI parameter update cycle...', ['emergency_mode' => $isEmergency]);

        $this->collectDataForAI()
            ->then(function (array $dataForAI) use ($isEmergency) {
                $promptPayload = $this->constructAIPrompt($dataForAI, $isEmergency);
                return $this->sendRequestToAI($promptPayload);
            })
            ->then(
                function ($rawResponse) { // This is the string body from AI
                    return $this->processAIResponse($rawResponse); // This method will execute or log errors
                }
            )
            ->catch(function (\Throwable $e) { // Catches errors from collectDataForAI, sendRequestToAI, or processAIResponse
                if ($e->getCode() === 429) { // Gemini Rate Limit
                     $this->logger->warning("AI update cycle hit rate limit (429). Will retry on next scheduled interval.", ['exception_msg' => $e->getMessage()]);
                     // No specific lastAIDecisionResult update here, as it's a temporary API issue.
                     // The existing lastAIDecisionResult (if any) remains.
                } else {
                    $this->logger->error('AI update cycle failed comprehensively.', ['exception_class' => get_class($e), 'exception' => $e->getMessage(), 'trace_preview' => substr($e->getTraceAsString(),0,500)]);
                    // Set a generic error if not already set by a sub-function.
                    if ($this->lastAIDecisionResult === null || $this->lastAIDecisionResult['status'] !== 'ERROR') {
                        $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "AI update cycle failed: " . $e->getMessage(), 'decision' => null];
                    }
                }
            });
    }

    private function collectDataForAI(): PromiseInterface
    {
        $this->logger->debug("Collecting data for AI...");
        $historicalKlineLimit = 100; 

        $promises = [
            'balance' => $this->getFuturesAccountBalance()->otherwise(fn($e) => ['error' => $e->getMessage(), $this->marginAsset => ['availableBalance' => 'ERROR']]),
            'position' => $this->getPositionInformation($this->tradingSymbol)->otherwise(fn($e) => ['error' => $e->getMessage(), 'data' => null]),
            'trade_history' => $this->getFuturesTradeHistory($this->tradingSymbol, 10)->otherwise(fn($e) => ['error' => $e->getMessage(), 'data' => []]),
            'historical_klines' => $this->getHistoricalKlines($this->tradingSymbol, $this->historicalKlineIntervalAI, $historicalKlineLimit)->otherwise(fn($e) => ['error' => $e->getMessage(), 'data' => []])
        ];

        return \React\Promise\all($promises)->then(function (array $results) {
            // Process position data even if it had an error, formatPositionDetails handles null.
            $this->currentPositionDetails = $this->formatPositionDetails($results['position']['data'] ?? $results['position'] ?? null);

            $currentBalanceInfo = $results['balance'][$this->marginAsset] ?? ['availableBalance' => 0.0];
            if(isset($results['balance']['error'])) $currentBalanceInfo['availableBalance'] = "Error: " . $results['balance']['error'];

            $currentPositionRaw = $results['position']['data'] ?? $results['position'] ?? null;
            if(isset($results['position']['error'])) $currentPositionRaw = ['error' => $results['position']['error']];
            
            $historicalKlinesData = $results['historical_klines']['data'] ?? $results['historical_klines'] ?? [];
            if(isset($results['historical_klines']['error'])) $historicalKlinesData = ['error' => $results['historical_klines']['error']];

            $tradeHistoryData = $results['trade_history']['data'] ?? $results['trade_history'] ?? [];
             if(isset($results['trade_history']['error'])) $tradeHistoryData = ['error' => $results['trade_history']['error']];


            $activeEntryOrderDetails = null;
            if($this->activeEntryOrderId && $this->activeEntryOrderTimestamp) {
                 $secondsPending = time() - $this->activeEntryOrderTimestamp;
                 $timeoutIn = max(0, $this->pendingEntryOrderCancelTimeoutSeconds - $secondsPending);
                $activeEntryOrderDetails = [
                    'orderId' => $this->activeEntryOrderId,
                    'placedAt' => date('Y-m-d H:i:s', $this->activeEntryOrderTimestamp),
                    'side_intent' => $this->aiSuggestedSide ?? 'N/A', // Side AI intended for this order
                    'price_intent' => $this->aiSuggestedEntryPrice ?? 0,
                    'quantity_intent' => $this->aiSuggestedQuantity ?? 0,
                    'seconds_pending' => $secondsPending,
                    'timeout_in_seconds' => $timeoutIn
                ];
            }

            // Update isMissingProtectiveOrder flag based on fresh position data
             if ($this->currentPositionDetails && (!$this->activeSlOrderId || !$this->activeTpOrderId) && !$this->isPlacingOrManagingOrder) {
                 if (!$this->isMissingProtectiveOrder) { // Log only on first detection
                     $this->logger->warning("CRITICAL STATE DETECTED: Open position missing one or both SL/TP orders.", [
                         'position' => $this->currentPositionDetails,
                         'sl_id_state' => $this->activeSlOrderId,
                         'tp_id_state' => $this->activeTpOrderId
                     ]);
                 }
                 $this->isMissingProtectiveOrder = true;
             } elseif (!$this->currentPositionDetails) { // No position, so no missing orders
                 if ($this->isMissingProtectiveOrder) { // Log if flag was true and now it's false
                    $this->logger->info("Critical state (missing SL/TP) resolved as position is now closed.");
                 }
                 $this->isMissingProtectiveOrder = false;
             }
             // If position exists, SL/TP IDs exist, and not managing: isMissingProtectiveOrder should be false.
             // This is implicitly handled by the above.

            $dataForAI = [
                'current_timestamp' => date('Y-m-d H:i:s P'),
                'trading_symbol' => $this->tradingSymbol,
                'current_market_price_main_interval' => $this->lastClosedKlinePrice, 
                'main_kline_interval' => $this->klineInterval,
                'current_margin_asset_balance' => $currentBalanceInfo['availableBalance'],
                'margin_asset' => $this->marginAsset,
                'current_position_details_formatted' => $this->currentPositionDetails, // This is what AI should primarily use for position state
                'current_position_raw_api' => $currentPositionRaw, // Raw API data for reference
                'active_pending_entry_order' => $activeEntryOrderDetails,
                'position_missing_protective_orders_FLAG' => $this->isMissingProtectiveOrder, // Critical flag
                'last_ai_decision_outcome' => $this->lastAIDecisionResult, // Outcome of the bot's attempt to execute AI's previous decision
                'historical_klines_for_ai_analysis' => $historicalKlinesData, 
                'historical_klines_interval_for_ai' => $this->historicalKlineIntervalAI, 
                'recent_bot_order_log_outcomes' => $this->recentOrderLogs, // Bot's internal log of its own order actions
                'recent_account_trades_from_api' => array_map(function($trade){ // Raw trades from exchange
                    return ['price' => $trade['price'], 'qty' => $trade['qty'], 'commission' => $trade['commission'], 'realizedPnl' => $trade['realizedPnl'], 'side' => $trade['side'], 'isBuyer' => $trade['buyer'], 'isMaker' => $trade['maker'], 'time' => date('Y-m-d H:i:s', (int)($trade['time']/1000))];
                }, (is_array($tradeHistoryData) ? $tradeHistoryData : [])),
                'current_bot_parameters_summary' => [
                    'mainKlineInterval' => $this->klineInterval,
                    'historicalKlineIntervalAI' => $this->historicalKlineIntervalAI,
                    'defaultLeverageIfAIOmits' => $this->defaultLeverage,
                    'aiUpdateIntervalSeconds' => $this->aiUpdateIntervalSeconds,
                    'pendingEntryOrderTimeoutSeconds' => $this->pendingEntryOrderCancelTimeoutSeconds,
                ],
                 'trade_logic_summary' => "Bot trades {$this->tradingSymbol} futures. Main price updates from '{$this->klineInterval}' kline stream. AI analyzes detailed '{$this->historicalKlineIntervalAI}' historical klines. AI suggests entry/SL/TP. Bot places LIMIT entry, then STOP_MARKET SL & TAKE_PROFIT_MARKET TP. Entry timeout: {$this->pendingEntryOrderCancelTimeoutSeconds}s. AI can request early close. `position_missing_protective_orders_FLAG` is CRITICAL if true.",
            ];
            $this->logger->debug("Data collected for AI", [
                'market_price_main_interval' => $dataForAI['current_market_price_main_interval'],
                'balance' => $dataForAI['current_margin_asset_balance'],
                'position_exists' => !is_null($this->currentPositionDetails),
                'pending_entry_exists' => !is_null($activeEntryOrderDetails),
                'missing_orders_flag_current_state' => $this->isMissingProtectiveOrder,
                'historical_kline_count_ai' => is_array($dataForAI['historical_klines_for_ai_analysis']) ? count($dataForAI['historical_klines_for_ai_analysis']) : 'N/A (or error)'
                ]);
            return $dataForAI;
        })->catch(function (\Throwable $e) { // Should not be hit if `otherwise` is used on individual promises
            $this->logger->error("CRITICAL FAILURE in collectDataForAI promise ALL.", ['exception_class'=>get_class($e), 'exception' => $e->getMessage()]);
            // Set a clear error state for lastAIDecisionResult if something catastrophic happened here
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Data collection failed critically: " . $e->getMessage(), 'decision' => null];
            throw $e; // Rethrow to be caught by triggerAIUpdate's main catch
        });
    }

    private function constructAIPrompt(array $dataForAI, bool $isEmergency): string
    {
        $mainKlineInterval = $dataForAI['main_kline_interval'] ?? $this->klineInterval;
        $historicalKlineIntervalForAI = $dataForAI['historical_klines_interval_for_ai'] ?? $this->historicalKlineIntervalAI;

        $promptText = "You are the lead decision-making AI for managing trades on Binance USDM Futures for {$this->tradingSymbol}.\n";
        $promptText .= "You operate with significant autonomy. Your decisions must be well-reasoned, leveraging all provided past and present context, and your analysis of kline data for future outlook.\n";
        $promptText .= "The bot primarily uses the '{$mainKlineInterval}' kline for live price updates (`current_market_price_main_interval`) and general decision timing.\n";
        $promptText .= "For your detailed analysis, you are provided with historical klines at a '{$historicalKlineIntervalForAI}' interval (`historical_klines_for_ai_analysis`).\n\n";

        if ($isEmergency) {
            $promptText .= "ALERT: This is an EMERGENCY AI consultation due to a potentially critical bot state (e.g., `position_missing_protective_orders_FLAG` is true, or an error occurred). Review all data, especially `last_ai_decision_outcome` and `position_missing_protective_orders_FLAG`. Prioritize safety and provide clear reasoning for your action.\n\n";
        }
        $promptText .= "=== Current Bot State & Market Data ===\n";
        $dataForPrompt = $dataForAI; // Working copy

        // Handle potentially large arrays for the prompt
        $historicalKlinesCount = is_array($dataForAI['historical_klines_for_ai_analysis']) ? count($dataForAI['historical_klines_for_ai_analysis']) : 0;
        if ($historicalKlinesCount > 0) {
             $previewCount = min($historicalKlinesCount, 100); // Max 150 klines in prompt
             $dataForPrompt['historical_klines_for_ai_analysis'] = ['info' => "Showing last {$previewCount} of {$historicalKlinesCount} klines ('{$historicalKlineIntervalForAI}' interval)", 'klines' => array_slice($dataForAI['historical_klines_for_ai_analysis'], -$previewCount)];
        } elseif (isset($dataForAI['historical_klines_for_ai_analysis']['error'])) {
            $dataForPrompt['historical_klines_for_ai_analysis'] = ['error' => "Failed to fetch: " . $dataForAI['historical_klines_for_ai_analysis']['error']];
        }


        $recentBotOutcomesCount = is_array($dataForAI['recent_bot_order_log_outcomes']) ? count($dataForAI['recent_bot_order_log_outcomes']) : 0;
        if ($recentBotOutcomesCount > 5) { // Max 5 recent bot logs
            $dataForPrompt['recent_bot_order_log_outcomes'] = ['info' => "Showing last 5 of {$recentBotOutcomesCount} bot order log outcomes", 'logs' => array_slice($dataForAI['recent_bot_order_log_outcomes'], 0, 5)];
        }
        
        $recentAccountTradesCount = is_array($dataForAI['recent_account_trades_from_api']) ? count($dataForAI['recent_account_trades_from_api']) : 0;
        if ($recentAccountTradesCount > 5) { // Max 5 recent API trades
            $dataForPrompt['recent_account_trades_from_api'] = ['info' => "Showing last 5 of {$recentAccountTradesCount} account trades from API", 'trades' => array_slice($dataForAI['recent_account_trades_from_api'], 0, 5)];
        }  elseif (isset($dataForAI['recent_account_trades_from_api']['error'])) {
             $dataForPrompt['recent_account_trades_from_api'] = ['error' => "Failed to fetch: " . $dataForAI['recent_account_trades_from_api']['error']];
        }


        $promptText .= json_encode($dataForPrompt, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES) . "\n\n";

        // Determine current state description for AI guidance
        $stateDescription = "Unknown";
        $possibleActions = []; // Define relevant actions based on state

        if ($dataForAI['position_missing_protective_orders_FLAG']) {
            $stateDescription = "CRITICAL: Position Open BUT Protective Orders (SL/TP) are Missing or Inactive!";
            $possibleActions = ['CLOSE_POSITION', 'HOLD_POSITION']; // HOLD is very risky here
        } elseif ($dataForAI['active_pending_entry_order']) {
            $stateDescription = "Waiting for Pending Entry Order ({$dataForAI['active_pending_entry_order']['timeout_in_seconds']}s remaining until auto-cancel)";
            $possibleActions = ['HOLD_POSITION']; // Cannot open/close another while entry is pending
        } elseif ($dataForAI['current_position_details_formatted']) {
            $stateDescription = "Position Currently Open (SL/TP orders are presumed active unless `position_missing_protective_orders_FLAG` is true)";
            $possibleActions = ['HOLD_POSITION', 'CLOSE_POSITION'];
        } else { // No position and no pending entry order
            $stateDescription = "No Position and No Pending Entry Order";
            $possibleActions = ['OPEN_POSITION', 'DO_NOTHING'];
        }

        $promptText .= "=== AI Analysis & Decision Task ===\n";
        $promptText .= "Current Bot Operating Interval (for live price): {$mainKlineInterval}\n";
        $promptText .= "Your Detailed Analysis Interval (for kline patterns): {$historicalKlineIntervalForAI}\n";
        $promptText .= "Current Deduced State: {$stateDescription}\n";
        $promptText .= "Outcome of Last AI Decision Execution by Bot: " . json_encode($dataForAI['last_ai_decision_outcome'] ?? 'None yet or N/A', JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES) . "\n\n";

        $promptText .= "=== Candlestick/Kline Analysis Guidance (using '{$historicalKlineIntervalForAI}' data from `historical_klines_for_ai_analysis`) ===\n";
        $promptText .= "Your primary analysis should be on these '{$historicalKlineIntervalForAI}' klines. Use this to identify potential entries, exits, and to set logical SL/TP levels. Correlate these granular patterns with the `current_market_price_main_interval` (close of last {$mainKlineInterval} kline) for broader context.\n";
        $promptText .= "Consider patterns for: Trend Reversals (e.g., Hammer, Engulfing, Stars), Trend Continuation, Indecision (e.g., Doji).\n";
        $promptText .= "Reflect on `last_ai_decision_outcome` and `recent_bot_order_log_outcomes`. If a strategy led to an undesirable result (e.g., entry timeout, quick SL hit), learn and adapt. Your reasoning should reflect this.\n\n";

        $promptText .= "Based on the current state ('{$stateDescription}'), your kline analysis, and past outcomes, choose ONE of the following relevant actions. Provide parameters precisely as specified:\n\n";

        $actionList = [];
        $actionCounter = 1;

        if (in_array('OPEN_POSITION', $possibleActions)) {
            $actionList[] = "{$actionCounter}. Open New Position: `{\"action\": \"OPEN_POSITION\", \"leverage\": <int>, \"side\": \"BUY\"|\"SELL\", \"entryPrice\": <float>, \"quantity\": <float>, \"stopLossPrice\": <float>, \"takeProfitPrice\": <float>, \"trade_rationale\": \"<brief_explanation_of_setup_and_SLTP_choice>\"}`\n" .
                            "   - Use ONLY if state is 'No Position and No Pending Entry Order' AND your analysis identifies a strong setup.\n" .
                            "   - `trade_rationale` (max 150 chars) is MANDATORY: Briefly explain your kline-based reasoning for entry, SL, and TP levels.\n" .
                            "   - `leverage` must be positive.\n" .
                            "   - `entryPrice`, `stopLossPrice`, `takeProfitPrice` must be positive and logical for the `side` (e.g., for BUY: SL < Entry < TP).\n" .
                            "   - Target an initial margin of ~10.5 USDT for the trade. Calculate `quantity` based on this: `Quantity = (10.5 * Leverage) / EntryPrice`.\n" .
                            "   - Then, ensure your `stopLossPrice` results in a total risk of approximately \$10 USDT if hit: `Risk = Abs(EntryPrice - StopLossPrice) * Quantity`. Adjust SL or quantity slightly if necessary, prioritizing the risk target if margin varies a bit.\n" .
                            "   - Adhere to {$this->tradingSymbol} price/quantity precision (e.g. Price 0.1, Qty 0.001 for BTCUSDT). Bot will format final values.";
            $actionCounter++;
        }
        if (in_array('CLOSE_POSITION', $possibleActions)) {
             $actionList[] = "{$actionCounter}. Close Current Position: `{\"action\": \"CLOSE_POSITION\", \"reason\": \"<brief_reason_based_on_market_or_state_max_100_chars>\"}`\n" .
                             "   - STRONGLY RECOMMENDED if `position_missing_protective_orders_FLAG` is true.\n" .
                             "   - Also use if 'Position Currently Open' AND your analysis/market conditions suggest exiting the trade.";
             $actionCounter++;
        }
        if (in_array('HOLD_POSITION', $possibleActions)) {
            $actionList[] = "{$actionCounter}. Hold / Wait: `{\"action\": \"HOLD_POSITION\", \"reason\": \"<brief_reason_for_holding_max_100_chars>\"}`\n" .
                            "   - Use if 'Waiting for Pending Entry Order'.\n" .
                            "   - Use if 'Position Currently Open' and your analysis supports continuing the trade.\n" .
                            "   - If `position_missing_protective_orders_FLAG` is true, HOLD is EXTREMELY RISKY. If you choose HOLD, `reason` MUST be a very compelling, specific, and actionable justification for overriding the strong recommendation to close (e.g., 're-placing SL/TP now').";
            $actionCounter++;
        }
         if (in_array('DO_NOTHING', $possibleActions)) {
             if (count($possibleActions) <= 2 && in_array('OPEN_POSITION', $possibleActions)) { // Only show if it's a primary choice when no position
                 $actionList[] = "{$actionCounter}. Do Nothing: `{\"action\": \"DO_NOTHING\", \"reason\": \"<brief_reason_no_setup_max_100_chars>\"}`\n" .
                                 "   - Use ONLY if 'No Position and No Pending Entry Order' AND your analysis finds no suitable entry setup.";
                 $actionCounter++;
             }
         }

        $promptText .= implode("\n", $actionList);
        $promptText .= "\n\n";

        $promptText .= "=== MANDATORY Rules & Output Format ===\n";
        $promptText .= "- Your response MUST be ONLY the single JSON object for your chosen action. No extra text, markdown, or explanations outside the JSON fields.\n";
        $promptText .= "- Adhere strictly to the action choices relevant to the current `{$stateDescription}`.\n";
        $promptText .= "- Parameter Precision for {$this->tradingSymbol}: Provide reasonable float values. Bot will format to exact exchange requirements (e.g., BTCUSDT price 1 decimal, quantity 3 decimals).\n";
        $promptText .= "- For `OPEN_POSITION`: You are responsible for calculating `quantity` and setting `stopLossPrice` to meet ~10.5 USDT initial margin AND ~\$10 USDT risk on SL, given your chosen `entryPrice` and `leverage`.\n";
        $promptText .= "- Illogical Parameters from AI will be rejected by the bot (e.g., SL/TP relationship to entry price for chosen side). This will be noted in `last_ai_decision_outcome`.\n";
        $promptText .= "- Critical State (`position_missing_protective_orders_FLAG` is true): Safety is paramount. `CLOSE_POSITION` is the default safe action. Deviate to `HOLD_POSITION` only with EXTREME caution and an explicit, strong, actionable justification in the `reason` field.\n";
        $promptText .= "- Testnet Environment: Be proactive in finding valid trade setups when 'No Position and No Pending Entry Order'. Avoid `DO_NOTHING` if a good setup (according to your kline analysis) exists and state allows opening. Use Margin 10.5usdt.\n";
        
        $promptText .= "\nProvide ONLY the JSON object for your chosen action.";

        // Generation config example for Gemini
        $generationConfig = [
            'temperature' => 0.6, // Controls randomness. Lower for more deterministic, higher for more creative.
            'topK' => 1,          // Consider adjusting if needed, but for JSON output, 1 is often good.
            'topP' => 0.95,        // Nucleus sampling.
            'maxOutputTokens' => 65536, // Max tokens for the response.
            // 'stopSequences' => ["}"], // Might be too aggressive if JSON is complex.
        ];

        return json_encode(['contents' => [['parts' => [['text' => $promptText]]]], 'generationConfig' => $generationConfig]);
    }

    private function sendRequestToAI(string $jsonPayload): PromiseInterface
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModelName . ':generateContent?key=' . $this->geminiApiKey;
        $headers = ['Content-Type' => 'application/json'];
        $this->logger->debug('Sending request to Gemini AI', ['url_path' => 'models/' . $this->geminiModelName . ':generateContent', 'payload_length' => strlen($jsonPayload), 'model' => $this->geminiModelName]);

        // Log a snippet of the payload for debugging, careful with sensitive data if any (API keys are not in payload)
        // $this->logger->debug('AI Payload Snippet:', ['snippet' => substr($jsonPayload, 0, 500) . (strlen($jsonPayload) > 500 ? '...' : '')]);


        return $this->browser->post($url, $headers, $jsonPayload)->then(
            function (ResponseInterface $response) {
                $body = (string)$response->getBody();
                $this->logger->debug('Received response from Gemini AI', ['status' => $response->getStatusCode(), 'body_preview' => substr($body, 0, 200) . '...']);
                if ($response->getStatusCode() >= 300) {
                    // Specific check for 429 Rate Limit
                    if ($response->getStatusCode() === 429) {
                        $this->logger->warning('Gemini API rate limit hit (429 Too Many Requests). Will retry on next cycle.', ['response_body' => substr($body,0,500)]);
                        throw new \RuntimeException("Gemini API rate limit hit (429).", 429); // Specific code for this error
                    }
                    // General HTTP error from AI
                    $this->logger->error('Gemini API HTTP error.', ['status_code' => $response->getStatusCode(), 'response_body' => $body]);
                    throw new \RuntimeException("Gemini API HTTP error: " . $response->getStatusCode() . " Body: " . substr($body, 0, 500));
                }
                return $body; // Return the raw string body
            },
            function (\Throwable $e) { // Catches network errors or other client-side issues with the request
                if ($e->getCode() === 429) { // Check if it's already a 429 from a previous throw
                     $this->logger->warning('Gemini API request failed due to rate limit (429).', ['exception_msg' => $e->getMessage()]);
                } elseif (!isset($this->lastAIDecisionResult) || ($this->lastAIDecisionResult['status'] ?? '') !== 'ERROR') {
                    // Log as error only if not already in an error state from previous AI attempt, to avoid log spam
                    $this->logger->error('Gemini AI request failed (Network/Client Error).', ['exception_class' => get_class($e), 'exception_msg' => $e->getMessage()]);
                }
                throw $e; // Rethrow to be handled by triggerAIUpdate's main catch
            }
        );
    }

    private function processAIResponse(string $rawResponse): void
    {
        $this->logger->debug('Processing AI response.', ['raw_response_preview' => substr($rawResponse,0,500)]);
        try {
            $responseDecoded = json_decode($rawResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Failed to decode AI JSON response: " . json_last_error_msg() . ". Raw: " . substr($rawResponse,0,200));
            }

            // Check for Gemini specific error structure or content filtering
            if (isset($responseDecoded['promptFeedback']['blockReason'])) {
                $blockReason = $responseDecoded['promptFeedback']['blockReason'];
                $safetyRatings = $responseDecoded['promptFeedback']['safetyRatings'] ?? [];
                $this->logger->error("AI prompt was blocked.", ['reason' => $blockReason, 'safety_ratings' => $safetyRatings, 'raw_response' => substr($rawResponse,0,500)]);
                throw new \InvalidArgumentException("AI prompt blocked: {$blockReason}. Safety: " . json_encode($safetyRatings));
            }

             if (!isset($responseDecoded['candidates'][0]['content']['parts'][0]['text'])) {
                 $finishReason = $responseDecoded['candidates'][0]['finishReason'] ?? 'UNKNOWN_FINISH_REASON';
                 $safetyRatingsContent = $responseDecoded['candidates'][0]['safetyRatings'] ?? [];
                 $this->logger->error("AI response missing expected text content.", [
                    'finish_reason' => $finishReason, 
                    'safety_ratings' => $safetyRatingsContent,
                    'response_structure_preview' => substr($rawResponse,0,500)
                 ]);
                 throw new \InvalidArgumentException("AI response missing text. Finish Reason: {$finishReason}. Safety Ratings: " . json_encode($safetyRatingsContent));
             }
             $aiTextResponse = $responseDecoded['candidates'][0]['content']['parts'][0]['text'];

            // Clean up potential markdown code block delimiters
            $paramsJson = trim($aiTextResponse);
            if (str_starts_with($paramsJson, '```json')) $paramsJson = substr($paramsJson, 7);
            if (str_starts_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 3); // General ``` case
            if (str_ends_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 0, -3);
            $paramsJson = trim($paramsJson);

            $aiDecision = json_decode($paramsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Failed to decode JSON parameters from AI's cleaned text response.", [
                    'cleaned_ai_text_preview' => substr($paramsJson, 0, 200), 'json_error' => json_last_error_msg()
                ]);
                throw new \InvalidArgumentException("Failed to decode JSON from AI: " . json_last_error_msg() . " - Cleaned Input: " . substr($paramsJson,0,100));
            }
            
            // Successfully decoded AI's decision, now execute it
            $this->executeAIDecision($aiDecision);

        } catch (\Throwable $e) { // Catches errors from this processing block
            $this->logger->error('Error processing AI response or preparing for execution.', [
                'exception_class' => get_class($e), 'exception_msg' => $e->getMessage(), 
                'raw_ai_response_preview' => substr($rawResponse, 0, 500)
            ]);
            // Ensure lastAIDecisionResult reflects this failure
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed processing AI response: " . $e->getMessage(), 'decision_content_preview' => substr($rawResponse, 0, 100)];
            // This error will propagate to triggerAIUpdate's catch block if not already handled.
        }
    }

    private function executeAIDecision(array $decision): void
    {
        $actionToExecute = strtoupper($decision['action'] ?? 'UNKNOWN_ACTION');
        $this->logger->info("AI Decision Received by executeAIDecision", ['action' => $actionToExecute, 'full_decision_params' => $decision]);
        
        $originalDecisionForLog = $decision; 
        $overrideReason = null;
        $currentBotContextSummary = [
            'isMissingProtectiveOrder' => $this->isMissingProtectiveOrder,
            'activeEntryOrderId' => $this->activeEntryOrderId,
            'currentPositionExists' => !is_null($this->currentPositionDetails),
            'isPlacingOrManagingOrder' => $this->isPlacingOrManagingOrder,
        ];

        // Contextual Overrides / Sanity Checks by the Bot
        if ($this->isMissingProtectiveOrder) {
            if ($actionToExecute === 'HOLD_POSITION') {
                if (empty($decision['reason'])) {
                    $overrideReason = "AI chose HOLD in CRITICAL state (missing SL/TP) without justification. Bot enforces CLOSE.";
                    $actionToExecute = 'CLOSE_POSITION';
                } else {
                    $this->logger->warning("AI chose HOLD_POSITION in CRITICAL state (missing SL/TP) but provided justification. Proceeding with AI's HOLD.", ['justification' => $decision['reason'], 'bot_context' => $currentBotContextSummary]);
                    // Allow AI's HOLD if justified
                }
            } elseif ($actionToExecute !== 'CLOSE_POSITION') {
                $overrideReason = "AI chose '{$actionToExecute}' in CRITICAL state (missing SL/TP). Bot enforces CLOSE for safety.";
                $actionToExecute = 'CLOSE_POSITION';
            }
        } elseif ($this->activeEntryOrderId) { // Pending entry order exists
            if ($actionToExecute === 'OPEN_POSITION') {
                $overrideReason = "AI chose OPEN_POSITION while an entry order ({$this->activeEntryOrderId}) is already pending. Bot enforces HOLD.";
                $actionToExecute = 'HOLD_POSITION';
            } elseif ($actionToExecute === 'CLOSE_POSITION') {
                 $overrideReason = "AI chose CLOSE_POSITION while an entry order ({$this->activeEntryOrderId}) is pending (no position to close). Bot enforces HOLD.";
                 $actionToExecute = 'HOLD_POSITION';
            }
        } elseif ($this->currentPositionDetails) { // Position is open (and not missing SL/TP, from above)
            if ($actionToExecute === 'OPEN_POSITION') {
                $overrideReason = "AI chose OPEN_POSITION while a position already exists. Bot enforces HOLD.";
                $actionToExecute = 'HOLD_POSITION';
            } elseif ($actionToExecute === 'DO_NOTHING') {
                 $overrideReason = "AI chose DO_NOTHING while a position exists. Bot enforces HOLD to maintain position.";
                 $actionToExecute = 'HOLD_POSITION';
            }
        } else { // No position, no pending entry
            if ($actionToExecute === 'CLOSE_POSITION') {
                $overrideReason = "AI chose CLOSE_POSITION when no position exists. Bot enforces DO_NOTHING.";
                $actionToExecute = 'DO_NOTHING';
            } elseif ($actionToExecute === 'HOLD_POSITION' && empty($decision['reason'])) { 
                // HOLD without reason when no position/pending is effectively DO_NOTHING
                $overrideReason = "AI chose HOLD_POSITION (no reason given) when no position or pending order exists. Bot interprets as DO_NOTHING.";
                $actionToExecute = 'DO_NOTHING';
            }
        }

        if ($overrideReason) {
            $this->logger->warning("AI Action Overridden by Bot Logic: {$overrideReason}", [
                'original_ai_action' => $originalDecisionForLog['action'] ?? 'N/A', 
                'forced_bot_action' => $actionToExecute, 
                'original_ai_decision_details' => $originalDecisionForLog,
                'bot_context_at_override' => $currentBotContextSummary
            ]);
            // This override becomes the new "decision" context for lastAIDecisionResult
            $this->lastAIDecisionResult = ['status' => 'WARN_OVERRIDE', 'message' => "Bot Override: " . $overrideReason, 'original_ai_decision' => $originalDecisionForLog, 'executed_action_by_bot' => $actionToExecute];
        }

        // Execute the (potentially overridden) action
        switch ($actionToExecute) {
            case 'OPEN_POSITION':
                // This action is only reached if not overridden.
                // Extract and validate AI parameters for opening a position.
                $this->aiSuggestedLeverage = (int)($decision['leverage'] ?? $this->defaultLeverage);
                $this->aiSuggestedSide = strtoupper($decision['side'] ?? '');
                $this->aiSuggestedEntryPrice = (float)($decision['entryPrice'] ?? 0);
                $this->aiSuggestedQuantity = (float)($decision['quantity'] ?? 0); 
                $this->aiSuggestedSlPrice = (float)($decision['stopLossPrice'] ?? 0);
                $this->aiSuggestedTpPrice = (float)($decision['takeProfitPrice'] ?? 0);
                $tradeRationale = $decision['trade_rationale'] ?? 'AI did not provide a rationale.';

                $this->logger->info("AI requests OPEN_POSITION. Validating parameters...", [
                    'leverage' => $this->aiSuggestedLeverage, 'side' => $this->aiSuggestedSide,
                    'entry' => $this->aiSuggestedEntryPrice, 'quantity' => $this->aiSuggestedQuantity,
                    'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice,
                    'rationale' => $tradeRationale, 'bot_context' => $currentBotContextSummary
                ]);

                // Rigorous Parameter Validation by Bot
                $validationError = null;
                if (!in_array($this->aiSuggestedSide, ['BUY', 'SELL'])) $validationError = "Invalid side: {$this->aiSuggestedSide}.";
                elseif ($this->aiSuggestedLeverage <= 0 || $this->aiSuggestedLeverage > 125) $validationError = "Invalid leverage: {$this->aiSuggestedLeverage} (must be >0 and typically <=125).";
                elseif ($this->aiSuggestedEntryPrice <= 0) $validationError = "Invalid entryPrice: {$this->aiSuggestedEntryPrice}.";
                elseif ($this->aiSuggestedQuantity <= 0) $validationError = "Invalid quantity: {$this->aiSuggestedQuantity} (must be >0).";
                elseif ($this->aiSuggestedSlPrice <= 0) $validationError = "Invalid stopLossPrice: {$this->aiSuggestedSlPrice}.";
                elseif ($this->aiSuggestedTpPrice <= 0) $validationError = "Invalid takeProfitPrice: {$this->aiSuggestedTpPrice}.";
                elseif ($this->aiSuggestedSide === 'BUY' && !($this->aiSuggestedSlPrice < $this->aiSuggestedEntryPrice && $this->aiSuggestedEntryPrice < $this->aiSuggestedTpPrice)) {
                    $validationError = "Illogical SL/TP for BUY: Expected SL ({$this->aiSuggestedSlPrice}) < Entry ({$this->aiSuggestedEntryPrice}) < TP ({$this->aiSuggestedTpPrice}).";
                } elseif ($this->aiSuggestedSide === 'SELL' && !($this->aiSuggestedTpPrice < $this->aiSuggestedEntryPrice && $this->aiSuggestedEntryPrice < $this->aiSuggestedSlPrice)) {
                    $validationError = "Illogical SL/TP for SELL: Expected TP ({$this->aiSuggestedTpPrice}) < Entry ({$this->aiSuggestedEntryPrice}) < SL ({$this->aiSuggestedSlPrice}).";
                }
                // Add check for trade_rationale presence
                elseif (empty($tradeRationale) || strlen($tradeRationale) < 5) $validationError = "Missing or too short trade_rationale.";


                if ($validationError) {
                    $this->logger->error("AI OPEN_POSITION parameters FAILED BOT VALIDATION: {$validationError}", ['failed_decision_params' => $decision, 'bot_context' => $currentBotContextSummary]);
                    $this->lastAIDecisionResult = ['status' => 'ERROR_VALIDATION', 'message' => "AI OPEN_POSITION params rejected by bot: " . $validationError, 'invalid_decision_by_ai' => $decision];
                } else {
                    // Validation passed, attempt to open the position
                    $this->attemptOpenPosition(); // This method will set its own lastAIDecisionResult based on API call outcome
                }
                break;

            case 'CLOSE_POSITION':
                $this->logger->info("Bot to execute AI's CLOSE_POSITION request.", ['reason_from_ai' => $decision['reason'] ?? 'N/A', 'bot_context' => $currentBotContextSummary]);
                $this->attemptClosePositionByAI(); // This method sets its own lastAIDecisionResult
                break;

            case 'HOLD_POSITION':
                 // If not overridden, this is the AI's explicit choice.
                 if (!$overrideReason) { 
                    $this->lastAIDecisionResult = ['status' => 'OK_ACTION', 'message' => 'Bot will HOLD as per AI.', 'executed_decision' => $originalDecisionForLog];
                 } // If overridden, lastAIDecisionResult was already set by override logic.
                 $this->logger->info("Bot action: HOLD / Wait.", ['reason_from_ai' => $decision['reason'] ?? 'N/A', 'final_decision_log_context' => $this->lastAIDecisionResult]);
                 break;

            case 'DO_NOTHING':
                 if (!$overrideReason) {
                    $this->lastAIDecisionResult = ['status' => 'OK_ACTION', 'message' => 'Bot will DO_NOTHING as per AI.', 'executed_decision' => $originalDecisionForLog];
                 }
                 $this->logger->info("Bot action: DO_NOTHING.", ['reason_from_ai' => $decision['reason'] ?? 'N/A', 'final_decision_log_context' => $this->lastAIDecisionResult]);
                 break;

            default: // Unknown action
                if (!$overrideReason) {
                    $this->lastAIDecisionResult = ['status' => 'ERROR_ACTION', 'message' => "Bot received unknown AI action '{$actionToExecute}'. No action taken.", 'unknown_decision_by_ai' => $originalDecisionForLog];
                }
                $this->logger->error("Unknown or unhandled AI action received by bot.", ['action_received' => $actionToExecute, 'full_ai_decision' => $originalDecisionForLog, 'final_decision_log_context' => $this->lastAIDecisionResult]);
        }
    }
}


// --- Script Execution ---
$loop = Loop::get();

$bot = new AiTradingBotFutures(
    binanceApiKey: $binanceApiKey,
    binanceApiSecret: $binanceApiSecret,
    geminiApiKey: $geminiApiKey,
    geminiModelName: $geminiModelName, // e.g., 'gemini-1.5-flash-latest' or 'gemini-1.0-pro'
    tradingSymbol: 'BTCUSDT',
    klineInterval: '1h', 
    historicalKlineIntervalAI: '1h', // AI analysis interval
    marginAsset: 'USDT',
    defaultLeverage: 10, // Fallback if AI omits
    amountPercentage: 0, // Unused, AI calculates quantity
    orderCheckIntervalSeconds: 5, // Check pending orders, etc.
    maxScriptRuntimeSeconds: 86400, // 24 hours
    aiUpdateIntervalSeconds: 10, // 5 minutes to ask AI
    useTestnet: $useTestnet,
    pendingEntryOrderCancelTimeoutSeconds: 30 // 5 minutes for pending entry order to timeout
);

$bot->run();

?>
