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
$geminiModelName = getenv('GEMINI_MODEL_NAME') ?: 'gemini-1.5-flash-latest'; // Or your preferred model

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
    private string $klineInterval; // e.g., 1m, 5m, 1s
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
    private ?float $lastClosedKlinePrice = null;
    private ?string $activeEntryOrderId = null;
    private ?int $activeEntryOrderTimestamp = null; // Timestamp of when the entry order was placed
    private ?string $activeSlOrderId = null;
    private ?string $activeTpOrderId = null;
    private ?array $currentPositionDetails = null; // [symbol, side, entryPrice, quantity, leverage, unrealizedPnl, markPrice]
    private array $recentOrderLogs = [];
    private bool $isPlacingOrManagingOrder = false; // General lock for complex operations
    private ?string $listenKey = null;
    private ?\React\EventLoop\TimerInterface $listenKeyRefreshTimer = null;
    private bool $isMissingProtectiveOrder = false; // NEW: Flag if position open but SL/TP missing
    private ?array $lastAIDecisionResult = null; // NEW: Store outcome of last AI action

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
        string $klineInterval,
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
        $this->marginAsset = strtoupper($marginAsset);
        $this->defaultLeverage = $defaultLeverage;
        $this->amountPercentage = $amountPercentage;
        $this->orderCheckIntervalSeconds = $orderCheckIntervalSeconds;
        $this->maxScriptRuntimeSeconds = $maxScriptRuntimeSeconds;
        $this->aiUpdateIntervalSeconds = $aiUpdateIntervalSeconds;
        $this->useTestnet = $useTestnet;
        $this->pendingEntryOrderCancelTimeoutSeconds = $pendingEntryOrderCancelTimeoutSeconds;

        $this->currentRestApiBaseUrl = $this->useTestnet ? self::BINANCE_FUTURES_TEST_REST_API_BASE_URL : self::BINANCE_FUTURES_PROD_REST_API_BASE_URL;
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
            'kline_interval' => $this->klineInterval,
            'ai_update_interval_seconds' => $this->aiUpdateIntervalSeconds,
            'gemini_model_name' => $this->geminiModelName,
            'using_testnet' => $this->useTestnet,
            'pending_entry_cancel_timeout' => $this->pendingEntryOrderCancelTimeoutSeconds,
            'rest_url' => $this->currentRestApiBaseUrl,
            'ws_url_base' => $this->currentWsBaseUrl
        ]);

        $this->aiSuggestedLeverage = $this->defaultLeverage;
    }

    public function run(): void
    {
        $this->logger->info('Starting AI Trading Bot (Futures) initialization...');
        \React\Promise\all([
            'initial_balance' => $this->getFuturesAccountBalance(),
            'initial_price' => $this->getLatestKlineClosePrice($this->tradingSymbol, $this->klineInterval),
            'initial_position' => $this->getPositionInformation($this->tradingSymbol),
            'listen_key' => $this->startUserDataStream(),
        ])->then(
            function ($results) {
                $initialBalance = $results['initial_balance'][$this->marginAsset] ?? ['availableBalance' => 0.0, 'balance' => 0.0];
                $this->lastClosedKlinePrice = (float)($results['initial_price']['price'] ?? 0);
                $this->currentPositionDetails = $this->formatPositionDetails($results['initial_position']);
                $this->listenKey = $results['listen_key']['listenKey'] ?? null;

                if ($this->lastClosedKlinePrice <= 0) {
                    throw new \RuntimeException("Failed to fetch a valid initial price for {$this->tradingSymbol}.");
                }
                if (!$this->listenKey) {
                    throw new \RuntimeException("Failed to obtain a listenKey for User Data Stream.");
                }

                $this->logger->info('Initialization Success', [
                    'startup_' . $this->marginAsset . '_balance' => $initialBalance,
                    'initial_market_price_' . $this->tradingSymbol => $this->lastClosedKlinePrice,
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
                    $this->stop(); // Consider reconnect logic later
                });
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->wsConnection = null; // Mark as disconnected
                    $this->stop(); // Consider reconnect logic later
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                $this->stop(); // Consider reconnect logic later
            }
        );
    }

    private function setupTimers(): void
    {
        // Fallback Order Check / Timeout Timer
        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            // --- Pending Entry Order Timeout Check ---
            // This check only applies to ENTRY orders, not SL/TP
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder && $this->activeEntryOrderTimestamp !== null) {
                $secondsPassed = time() - $this->activeEntryOrderTimestamp;
                if ($secondsPassed > $this->pendingEntryOrderCancelTimeoutSeconds) {
                    $this->logger->warning("Pending entry order {$this->activeEntryOrderId} timed out ({$secondsPassed}s > {$this->pendingEntryOrderCancelTimeoutSeconds}s). Attempting cancellation.");
                    $this->isPlacingOrManagingOrder = true; // Lock
                    $orderIdToCancel = $this->activeEntryOrderId;
                    $timedOutOrderSide = $this->aiSuggestedSide ?? 'N/A'; // Capture state at time of decision
                    $timedOutOrderPrice = $this->aiSuggestedEntryPrice ?? 0;
                    $timedOutOrderQty = $this->aiSuggestedQuantity ?? 0;

                    // Attempt cancellation
                    $this->cancelFuturesOrder($this->tradingSymbol, $orderIdToCancel)
                        ->then(
                            function ($cancellationData) use ($orderIdToCancel, $timedOutOrderSide, $timedOutOrderPrice, $timedOutOrderQty) {
                                $this->logger->info("Pending entry order {$orderIdToCancel} successfully cancelled due to timeout.", ['response_status' => $cancellationData['status'] ?? 'N/A']);
                                // State reset primarily handled by ORDER_TRADE_UPDATE(CANCELED), but good fallback.
                                if ($this->activeEntryOrderId === $orderIdToCancel) {
                                    $this->addOrderToLog($orderIdToCancel, 'CANCELED_TIMEOUT', $timedOutOrderSide, $this->tradingSymbol, $timedOutOrderPrice, $timedOutOrderQty, $this->marginAsset, time(), 0.0);
                                    $this->resetTradeState();
                                    $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderIdToCancel} cancelled due to timeout.", 'decision' => null];
                                }
                            },
                            function (\Throwable $e) use ($orderIdToCancel) {
                                $this->logger->error("Failed attempt to cancel timed-out pending entry order {$orderIdToCancel}.", ['exception' => $e->getMessage()]);
                                // If cancellation fails (e.g., -2011 order not found), it might have filled or already been cancelled.
                                // We rely on WS or the subsequent status check to potentially catch the final state.
                                // If it's still the active order internally, check its status again.
                                if ($this->activeEntryOrderId === $orderIdToCancel) {
                                     $this->checkActiveOrderStatus($orderIdToCancel, 'ENTRY_TIMEOUT_CANCEL_FAILED');
                                }
                                $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed cancellation attempt for timed-out order {$orderIdToCancel}.", 'decision' => null];
                            }
                        )->finally(function () {
                            $this->isPlacingOrManagingOrder = false; // Unlock
                        });
                    return; // Don't proceed to regular status check if timeout cancellation was attempted
                }
            }

            // --- Regular Status Check (if no timeout occurred) ---
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder) {
                $this->checkActiveOrderStatus($this->activeEntryOrderId, 'ENTRY');
            }
            // NOTE: We don't routinely check SL/TP status here because their state changes (FILL/CANCEL)
            // are critical events expected to come through the User Data Stream immediately.
            // Checking them periodically adds API calls and complexity for less benefit than the entry check.
            // The "missing protective order" state handles potential issues there.
        });
        $this->logger->info('Fallback order check timer started', ['interval_seconds' => $this->orderCheckIntervalSeconds, 'entry_order_timeout' => $this->pendingEntryOrderCancelTimeoutSeconds]);

        // Max Runtime Timer
        $this->loop->addTimer($this->maxScriptRuntimeSeconds, function () {
            $this->logger->warning('Maximum script runtime reached. Stopping.', ['max_runtime_seconds' => $this->maxScriptRuntimeSeconds]);
            $this->stop();
        });
        $this->logger->info('Max runtime timer started', ['limit_seconds' => $this->maxScriptRuntimeSeconds]);

        // AI Update Timer
        $this->loop->addPeriodicTimer($this->aiUpdateIntervalSeconds, function () {
            $this->triggerAIUpdate();
        });
        $this->logger->info('AI parameter update timer started', ['interval_seconds' => $this->aiUpdateIntervalSeconds]);

        // Listen Key Refresh Timer
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

        if (str_ends_with($streamName, '@kline_' . $this->klineInterval)) {
            if (isset($data['e']) && $data['e'] === 'kline' && isset($data['k']['x']) && $data['k']['x'] === true && isset($data['k']['c'])) {
                $this->lastClosedKlinePrice = (float)$data['k']['c'];
                $this->logger->debug('Kline update received (closed)', [
                    'symbol' => $data['k']['s'],
                    'close_price' => $this->lastClosedKlinePrice
                ]);
            }
        } elseif ($streamName === $this->listenKey) {
            $this->handleUserDataStreamEvent($data);
        }
    }

    private function handleUserDataStreamEvent(array $eventData): void
    {
        $eventType = $eventData['e'] ?? null;
        $this->logger->debug("User Data Stream Event", ['type' => $eventType, 'data_preview' => substr(json_encode($eventData), 0, 200)]);

        switch ($eventType) {
            case 'ACCOUNT_UPDATE':
                // Handle position changes from ACCOUNT_UPDATE (e.g., liquidation, ADL)
                if (isset($eventData['a']['P'])) {
                    foreach($eventData['a']['P'] as $posData) {
                        if ($posData['s'] === $this->tradingSymbol) {
                            $oldPositionDetails = $this->currentPositionDetails;
                            $newPositionDetails = $this->formatPositionDetails($posData);

                            if ($newPositionDetails && !$oldPositionDetails) {
                                $this->logger->info("Position opened/updated via ACCOUNT_UPDATE.", $newPositionDetails);
                                $this->currentPositionDetails = $newPositionDetails;
                                // If position appears but we thought we had no SL/TP, maybe flag? Unlikely needed.
                            } elseif (!$newPositionDetails && $oldPositionDetails) {
                                $this->logger->info("Position for {$this->tradingSymbol} detected as closed via ACCOUNT_UPDATE.", ['reason_code' => $posData['cr'] ?? 'N/A']);
                                $this->handlePositionClosed(); // Will attempt to cancel any lingering SL/TP known to the bot
                            } elseif ($newPositionDetails && $oldPositionDetails) {
                                // Update existing position details (like PnL)
                                $this->currentPositionDetails = $newPositionDetails;
                                $this->logger->debug("Position details updated via ACCOUNT_UPDATE.", $newPositionDetails);
                            }
                            // else: no change (e.g., update for other symbol, or zero position update)
                        }
                    }
                }
                // Handle balance changes
                if (isset($eventData['a']['B'])) {
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
                    'status' => $order['X'], 'type' => $order['o'], 'side' => $order['S'],
                    'price' => $order['p'], 'quantity' => $order['q'], 'filled_qty' => $order['z'],
                    'avg_fill_price' => $order['ap'], 'reduce_only' => $order['R'] ?? 'N/A',
                    'stop_price' => $order['sp'] ?? 'N/A', 'pnl' => $order['rp'] ?? 'N/A'
                ]);

                if ($order['s'] !== $this->tradingSymbol) return;

                $orderId = (string)$order['i'];
                $orderStatus = $order['X'];

                // --- Entry Order Update ---
                if ($orderId === $this->activeEntryOrderId) {
                    if (in_array($orderStatus, ['FILLED', 'PARTIALLY_FILLED'])) {
                        // Update position details based on fill
                        $filledQty = (float)$order['z'];
                        $avgFilledPrice = (float)$order['ap'];
                        $isFirstFill = !$this->currentPositionDetails || (float)($this->currentPositionDetails['quantity'] ?? 0) == 0;

                        if ($isFirstFill) {
                             $this->currentPositionDetails = [
                                'symbol' => $this->tradingSymbol,
                                'side' => $order['S'] === 'BUY' ? 'LONG' : 'SHORT',
                                'entryPrice' => $avgFilledPrice,
                                'quantity' => $filledQty,
                                'leverage' => $this->aiSuggestedLeverage, // Assume leverage set correctly before order
                                'markPrice' => $avgFilledPrice,
                                'unrealizedPnl' => 0
                            ];
                             $this->logger->info("Entry order first fill. Updated position.", $this->currentPositionDetails);
                        } else {
                             // Handle averaging down/up if partial fills happen (though less common for futures entry)
                             $existingQty = (float)$this->currentPositionDetails['quantity'];
                             $existingEntry = (float)$this->currentPositionDetails['entryPrice'];
                             $this->currentPositionDetails['entryPrice'] = (($existingEntry * $existingQty) + ($avgFilledPrice * $filledQty)) / ($existingQty + $filledQty);
                             $this->currentPositionDetails['quantity'] = $existingQty + $filledQty;
                             $this->logger->info("Entry order partial fill increased position.", $this->currentPositionDetails);
                        }

                        // If fully filled, clear entry state and place SL/TP
                        if ($orderStatus === 'FILLED') {
                            $this->logger->info("Entry order fully filled: {$this->activeEntryOrderId}. Placing SL/TP orders.");
                            $this->activeEntryOrderId = null;
                            $this->activeEntryOrderTimestamp = null;
                            $this->isMissingProtectiveOrder = false; // Reset flag on successful entry
                            $this->placeSlAndTpOrders(); // This will set isPlacingOrManagingOrder = true
                        }
                    } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                        $this->logger->warning("Active entry order {$this->activeEntryOrderId} ended without fill via WS: {$orderStatus}. Resetting.");
                        $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)$order['p'], (float)$order['q'], $this->marginAsset, time(), (float)($order['rp'] ?? 0));
                        $this->resetTradeState();
                         $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} ended without fill: {$orderStatus}.", 'decision' => null];
                    }
                }
                // --- SL/TP Order Update ---
                elseif ($orderId === $this->activeSlOrderId || $orderId === $this->activeTpOrderId) {
                    if ($orderStatus === 'FILLED') {
                        $isSlFill = ($orderId === $this->activeSlOrderId);
                        $otherOrderId = $isSlFill ? $this->activeTpOrderId : $this->activeSlOrderId;
                        $logSide = 'UNKNOWN';
                        if ($this->currentPositionDetails) {
                             $logSide = ($this->currentPositionDetails['side'] === 'LONG' ? 'SELL' : 'BUY');
                        }
                        $this->logger->info("{$order['ot']} order {$orderId} (SL/TP) filled. Position closed.", ['realized_pnl' => $order['rp'] ?? 'N/A']);
                        $this->addOrderToLog($orderId, $orderStatus, $logSide, $this->tradingSymbol, (float)($order['ap'] > 0 ? $order['ap'] : ($order['sp'] ?? 0)), (float)$order['z'], $this->marginAsset, time(), (float)($order['rp'] ?? 0));
                        $this->handlePositionClosed($otherOrderId); // Pass the other order ID to be cancelled
                        $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Position closed by " . ($isSlFill ? "SL" : "TP") . " order {$orderId}.", 'decision' => null];

                    } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                         $this->logger->warning("SL/TP order {$orderId} ended without fill: {$orderStatus}. Possible external cancellation or issue.");
                         if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                         if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;

                         // If position still exists but now missing potentially both SL/TP
                         if ($this->currentPositionDetails && !$this->activeSlOrderId && !$this->activeTpOrderId) {
                              $this->logger->critical("Position open but BOTH SL/TP orders are now gone unexpectedly. Flagging critical state.");
                              $this->isMissingProtectiveOrder = true;
                              $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Position unprotected: SL/TP order {$orderId} ended with status {$orderStatus}.", 'decision' => null];
                              $this->triggerAIUpdate(true); // Emergency check
                         } elseif ($this->currentPositionDetails && (!$this->activeSlOrderId || !$this->activeTpOrderId)) {
                             $this->logger->warning("Position open but ONE SL/TP order is now gone unexpectedly. Flagging potentially unsafe state.");
                              $this->isMissingProtectiveOrder = true; // Flag state
                              $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Position missing one protective order: {$orderId} ended with status {$orderStatus}.", 'decision' => null];
                              $this->triggerAIUpdate(true); // Ask AI what to do
                         }
                    }
                }
                break; // End ORDER_TRADE_UPDATE

            case 'listenKeyExpired':
                $this->logger->warning("ListenKey expired. Attempting to get a new one and reconnect WebSocket.");
                $this->listenKey = null;
                if ($this->wsConnection) { try { $this->wsConnection->close(); } catch (\Exception $_){}}
                $this->wsConnection = null;
                $this->startUserDataStream()->then(function ($data) {
                    $this->listenKey = $data['listenKey'] ?? null;
                    if ($this->listenKey) {
                        $this->logger->info("New ListenKey obtained. Reconnecting WebSocket.");
                        $this->connectWebSocket(); // Reconnect logic
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
                $this->isMissingProtectiveOrder = true; // Treat margin call as critical state
                $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "MARGIN CALL RECEIVED!", 'decision' => null];
                $this->triggerAIUpdate(true); // Emergency trigger
                break;

            default:
                $this->logger->debug('Unhandled user data event type', ['type' => $eventType]);
        }
    }

    private function formatPositionDetails(?array $positionsInput): ?array
    {
        if (empty($positionsInput)) return null;
        $positionData = null;
        $isSingleObject = !isset($positionsInput[0]) && isset($positionsInput['symbol']);

        if ($isSingleObject) {
            $currentQty = (float)($positionsInput['positionAmt'] ?? $positionsInput['pa'] ?? 0);
            if ($positionsInput['symbol'] === $this->tradingSymbol && $currentQty != 0) {
                $positionData = $positionsInput;
            }
        } elseif (is_array($positionsInput) && isset($positionsInput[0]['symbol'])) {
            foreach ($positionsInput as $p) {
                $currentQty = (float)($p['positionAmt'] ?? $p['pa'] ?? 0);
                if (isset($p['symbol']) && $p['symbol'] === $this->tradingSymbol && $currentQty != 0) {
                    $positionData = $p;
                    break;
                }
            }
        }
        if (!$positionData) return null;

        $quantityVal = (float)($positionData['positionAmt'] ?? $positionData['pa'] ?? 0);
        $entryPriceVal = (float)($positionData['entryPrice'] ?? $positionData['ep'] ?? 0);
        $markPriceVal = (float)($positionData['markPrice'] ?? $positionData['mp'] ?? ($this->lastClosedKlinePrice ?? 0));
        $unrealizedPnlVal = (float)($positionData['unrealizedProfit'] ?? $positionData['up'] ?? 0);
        $leverageVal = (int)($positionData['leverage'] ?? $this->defaultLeverage); // Use default if not provided
        $initialMarginVal = (float)($positionData['initialMargin'] ?? $positionData['iw'] ?? 0);
        $maintMarginVal = (float)($positionData['maintMargin'] ?? 0);
        $isolatedWalletVal = (float)($positionData['isolatedWallet'] ?? 0);

        if ($quantityVal == 0) return null;
        $side = $quantityVal > 0 ? 'LONG' : 'SHORT';

        return [
            'symbol' => $this->tradingSymbol,
            'side' => $side,
            'entryPrice' => $entryPriceVal,
            'quantity' => abs($quantityVal),
            'leverage' => $leverageVal ?: $this->defaultLeverage, // Fallback again just in case
            'markPrice' => $markPriceVal,
            'unrealizedPnl' => $unrealizedPnlVal,
            'initialMargin' => $initialMarginVal,
            'maintMargin' => $maintMarginVal,
            'isolatedWallet' => $isolatedWalletVal,
        ];
    }

    private function placeSlAndTpOrders(): void
    {
        if (!$this->currentPositionDetails) {
            $this->logger->error("Attempted to place SL/TP orders without a current position.");
            return;
        }
         if ($this->isPlacingOrManagingOrder) {
             $this->logger->warning("SL/TP placement already in progress, skipping.");
             return;
         }
        $this->isPlacingOrManagingOrder = true;
        $this->isMissingProtectiveOrder = false; // Assume success initially

        $positionSide = $this->currentPositionDetails['side'];
        $quantity = (float)$this->currentPositionDetails['quantity'];
        $orderSideForSlTp = ($positionSide === 'LONG') ? 'SELL' : 'BUY';

        // Ensure AI parameters are valid before attempting placement
        if ($this->aiSuggestedSlPrice <= 0 || $this->aiSuggestedTpPrice <= 0) {
             $this->logger->critical("Invalid AI SL/TP prices during placement attempt.", ['sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice]);
             $this->isMissingProtectiveOrder = true;
             $this->isPlacingOrManagingOrder = false;
             $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Invalid SL/TP prices detected at placement time.", 'decision' => null];
             $this->triggerAIUpdate(true); // Ask AI to handle this critical state
             return;
        }


        $slOrderPromise = $this->placeFuturesStopMarketOrder(
            $this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedSlPrice, true
        )->then(function ($orderData) {
            $this->activeSlOrderId = (string)$orderData['orderId'];
            $this->logger->info("Stop Loss order placed.", ['orderId' => $this->activeSlOrderId, 'stopPrice' => $this->aiSuggestedSlPrice]);
            return $orderData; // Pass data for potential logging in catch
        });

        $tpOrderPromise = $this->placeFuturesTakeProfitMarketOrder(
            $this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedTpPrice, true
        )->then(function ($orderData) {
            $this->activeTpOrderId = (string)$orderData['orderId'];
            $this->logger->info("Take Profit order placed.", ['orderId' => $this->activeTpOrderId, 'stopPrice' => $this->aiSuggestedTpPrice]);
            return $orderData;
        });

        \React\Promise\all([$slOrderPromise, $tpOrderPromise])
            ->then(
                function (array $results) {
                    // Both SL and TP were placed successfully according to the API response
                    $this->logger->info("SL and TP orders successfully placed via API.", [
                        'sl_order_id' => $results[0]['orderId'] ?? 'N/A',
                        'tp_order_id' => $results[1]['orderId'] ?? 'N/A'
                        ]);
                    // State ($activeSlOrderId, $activeTpOrderId) was set in the individual 'then' blocks above
                    $this->isMissingProtectiveOrder = false; // Confirm state is good
                    $this->isPlacingOrManagingOrder = false;
                     $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "SL/TP orders placed successfully.", 'decision' => null]; // Update status
                },
                function (\Throwable $e) {
                    // One or both of the SL/TP placements failed
                    $this->logger->critical("CRITICAL: Error placing one or both SL/TP orders. Position might be unprotected!", [
                        'exception_class' => get_class($e),
                        'exception' => $e->getMessage(),
                        'current_sl_id_state' => $this->activeSlOrderId, // ID might be set if one succeeded before the other failed
                        'current_tp_id_state' => $this->activeTpOrderId,
                        'position_details' => $this->currentPositionDetails
                    ]);
                    // --- MODIFIED LOGIC ---
                    // Do NOT cancel the order that might have succeeded.
                    // Flag the critical state instead.
                    $this->isMissingProtectiveOrder = true;
                    $this->isPlacingOrManagingOrder = false;
                    // Trigger AI immediately to assess the situation (e.g., close the unprotected position)
                     $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Failed placing SL/TP orders. Position potentially unprotected.", 'decision' => null];
                    $this->triggerAIUpdate(true);
                }
            );
    }

    private function handlePositionClosed(?string $otherOrderIdToCancel = null): void
    {
        $closedPositionDetails = $this->currentPositionDetails; // Log details before resetting
        $this->logger->info("Position closed action triggered for {$this->tradingSymbol}.", ['details_before_reset' => $closedPositionDetails]);

        // Attempt to cancel the other SL/TP order if provided
        if ($otherOrderIdToCancel) {
            $this->cancelFuturesOrder($this->tradingSymbol, $otherOrderIdToCancel)->then(
                fn() => $this->logger->info("Successfully cancelled remaining SL/TP order: {$otherOrderIdToCancel} during position close."),
                function ($e) use ($otherOrderIdToCancel) {
                    // If cancel fails, it might be -2011 (already filled/cancelled) - this is usually OK.
                     if (!str_contains($e->getMessage(), '-2011')) {
                         $this->logger->error("Failed to cancel remaining SL/TP order: {$otherOrderIdToCancel} during position close.", ['err' => $e->getMessage()]);
                     } else {
                         $this->logger->info("Attempt to cancel remaining SL/TP order {$otherOrderIdToCancel} failed (likely already resolved).", ['err_preview' => substr($e->getMessage(),0,50)]);
                     }
                }
            );
        } else {
            // If no specific other order provided, try cancelling any known active SL/TP (e.g., if closed via ACCOUNT_UPDATE)
            $cancelPromises = [];
            if ($this->activeSlOrderId) $cancelPromises[] = $this->cancelFuturesOrder($this->tradingSymbol, $this->activeSlOrderId);
            if ($this->activeTpOrderId) $cancelPromises[] = $this->cancelFuturesOrder($this->tradingSymbol, $this->activeTpOrderId);
            if (!empty($cancelPromises)) {
                \React\Promise\all($cancelPromises)->then(
                     fn() => $this->logger->info("Attempted cancellation of any known active SL/TP orders during position close."),
                     fn($e) => $this->logger->warning("Error during blanket cancellation of SL/TP orders on position close (might be normal if already resolved).", ['err' => $e->getMessage()])
                );
            }
        }

        $this->resetTradeState(); // Crucial: Resets all position/order state variables

        // Trigger AI update after a short delay to allow state to settle and potentially get final balance updates
        $this->loop->addTimer(5, function () {
            $this->triggerAIUpdate();
        });
    }

    private function resetTradeState(): void {
        $this->logger->info("Resetting trade state.");
        $this->activeEntryOrderId = null;
        $this->activeEntryOrderTimestamp = null;
        $this->activeSlOrderId = null;
        $this->activeTpOrderId = null;
        $this->currentPositionDetails = null;
        $this->isPlacingOrManagingOrder = false;
        $this->isMissingProtectiveOrder = false; // Reset flag
        // Keep $lastAIDecisionResult for the next prompt, don't reset it here.
    }

    private function addOrderToLog(string $orderId, string $status, string $side, string $assetPair, ?float $limitPrice, ?float $amountUsed, ?string $amountAsset, int $timestamp, ?float $realizedPnl): void
    {
        $logEntry = [
            'orderId' => $orderId,
            'status' => $status,
            'side' => $side,
            'assetPair' => $assetPair,
            'price' => $limitPrice,
            'quantity' => $amountUsed,
            'marginAsset' => $amountAsset,
            'timestamp' => date('Y-m-d H:i:s', $timestamp),
            'realizedPnl' => $realizedPnl,
        ];
        array_unshift($this->recentOrderLogs, $logEntry);
        $this->recentOrderLogs = array_slice($this->recentOrderLogs, 0, self::MAX_ORDER_LOG_ENTRIES);
        $this->logger->info('Futures trade outcome logged for AI', $logEntry);
    }

    private function attemptOpenPosition(): void
    {
        if ($this->currentPositionDetails || $this->activeEntryOrderId || $this->isPlacingOrManagingOrder) {
            $this->logger->debug('Skipping position opening: existing position, active entry order, or operation in progress.');
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped OPEN_POSITION: Already in position or pending entry.', 'decision' => null]; // Update status
            return;
        }

        // Basic check if AI parameters are even set
        if (!isset($this->aiSuggestedEntryPrice, $this->aiSuggestedSlPrice, $this->aiSuggestedTpPrice, $this->aiSuggestedQuantity, $this->aiSuggestedSide, $this->aiSuggestedLeverage)) {
            $this->logger->warning('AI parameters for opening position are not fully set. Waiting for AI update.');
            $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped OPEN_POSITION: AI parameters not fully set.', 'decision' => null];
            return;
        }
         // Final check for positive values before attempting API calls
        if ($this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedQuantity <= 0 || $this->aiSuggestedSlPrice <=0 || $this->aiSuggestedTpPrice <= 0) {
            $this->logger->error("Cannot attempt open position: one or more critical AI parameters are zero/negative.", [
                 'entry' => $this->aiSuggestedEntryPrice, 'qty' => $this->aiSuggestedQuantity, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice
            ]);
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => 'Rejected OPEN_POSITION: Invalid parameters (zero/negative).', 'decision' => ['action' => 'OPEN_POSITION']]; // Simplified decision log
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
                return $this->placeFuturesLimitOrder(
                    $this->tradingSymbol,
                    $this->aiSuggestedSide,
                    $this->aiSuggestedQuantity,
                    $this->aiSuggestedEntryPrice
                );
            })
            ->then(function ($orderData) use ($aiParamsForLog) {
                $this->activeEntryOrderId = (string)$orderData['orderId'];
                $this->activeEntryOrderTimestamp = time();
                $this->logger->info("Entry limit order placed successfully.", [
                    'orderId' => $this->activeEntryOrderId,
                    'clientOrderId' => $orderData['clientOrderId'],
                    'placement_timestamp' => date('Y-m-d H:i:s', $this->activeEntryOrderTimestamp)
                ]);
                 $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed entry order {$this->activeEntryOrderId}.", 'decision' => ['action' => 'OPEN_POSITION'] + $aiParamsForLog];
                $this->isPlacingOrManagingOrder = false;
            })
            ->catch(function (\Throwable $e) use ($aiParamsForLog) {
                $this->logger->error('Failed to open position.', [
                    'exception_class' => get_class($e), 'exception' => $e->getMessage(),
                    'ai_params' => $aiParamsForLog
                ]);
                 $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed to place entry order: " . $e->getMessage(), 'decision' => ['action' => 'OPEN_POSITION'] + $aiParamsForLog];
                $this->isPlacingOrManagingOrder = false;
                $this->resetTradeState(); // Reset state on failure
            });
    }
    private function attemptClosePositionByAI(): void
    {
        if (!$this->currentPositionDetails) {
            $this->logger->debug('Skipping AI close: No position exists.');
            $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped CLOSE_POSITION: No position exists.', 'decision' => null];
            return;
        }
         if ($this->isPlacingOrManagingOrder){
             $this->logger->debug('Skipping AI close: Operation already in progress.');
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped CLOSE_POSITION: Operation in progress.', 'decision' => null];
             return;
         }

        $this->isPlacingOrManagingOrder = true;
        $positionToClose = $this->currentPositionDetails; // Capture details before potential modification
        $this->logger->info("AI requests to close current position for {$this->tradingSymbol} at market.", ['position' => $positionToClose]);

        $cancellationPromises = [];
        if ($this->activeSlOrderId) {
            $cancellationPromises[] = $this->cancelFuturesOrder($this->tradingSymbol, $this->activeSlOrderId)
                ->then(fn() => $this->logger->info("SL Order {$this->activeSlOrderId} cancelled for AI close."))
                ->otherwise(fn($e) => $this->logger->error("Failed to cancel SL {$this->activeSlOrderId} for AI close.", ['err' => $e->getMessage()]));
        }
        if ($this->activeTpOrderId) {
            $cancellationPromises[] = $this->cancelFuturesOrder($this->tradingSymbol, $this->activeTpOrderId)
                ->then(fn() => $this->logger->info("TP Order {$this->activeTpOrderId} cancelled for AI close."))
                ->otherwise(fn($e) => $this->logger->error("Failed to cancel TP {$this->activeTpOrderId} for AI close.", ['err' => $e->getMessage()]));
        }
        // Clear internal IDs immediately after attempting cancellation
        $slIdCancelled = $this->activeSlOrderId;
        $tpIdCancelled = $this->activeTpOrderId;
        $this->activeSlOrderId = null;
        $this->activeTpOrderId = null;

        \React\Promise\all($cancellationPromises)->then(function() use ($positionToClose) {
            $closeSide = $positionToClose['side'] === 'LONG' ? 'SELL' : 'BUY';
            $quantityToClose = $positionToClose['quantity'];
            $this->logger->info("Attempting to place market order to close position.", ['side' => $closeSide, 'quantity' => $quantityToClose]);
            return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $quantityToClose, true); // reduceOnly = true
        })->then(function($closeOrderData) use ($positionToClose, $slIdCancelled, $tpIdCancelled) {
            $this->logger->info("Market order placed by AI to close position.", [
                'orderId' => $closeOrderData['orderId'],
                'status' => $closeOrderData['status']
            ]);
             // Position closure confirmation and state reset happens via WS ORDER_TRADE_UPDATE(FILLED) -> handlePositionClosed
            $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed market close order {$closeOrderData['orderId']}.", 'decision' => ['action' => 'CLOSE_POSITION', 'cancelled_sl' => $slIdCancelled, 'cancelled_tp' => $tpIdCancelled]];
            // DO NOT reset state here; wait for the fill event.
        })->catch(function(\Throwable $e) use ($positionToClose) {
            $this->logger->error("Error during AI-driven position close process.", ['exception' => $e->getMessage(), 'position' => $positionToClose]);
            // State might be inconsistent here (SL/TP potentially cancelled but close failed).
            // Triggering AI might be best fallback.
            $this->isMissingProtectiveOrder = true; // Assume potentially unsafe state
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Error during AI close: " . $e->getMessage(), 'decision' => ['action' => 'CLOSE_POSITION']];
            $this->triggerAIUpdate(true);
        })->finally(function() {
            $this->isPlacingOrManagingOrder = false; // Unlock managing flag
        });
    }


    // --- Binance API Helper Methods ---
    private function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $timestamp = round(microtime(true) * 1000);
        $params['timestamp'] = $timestamp;
        $params['recvWindow'] = self::BINANCE_API_RECV_WINDOW;

        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $queryString, $this->binanceApiSecret);

        $url = $this->currentRestApiBaseUrl . $endpoint;
        $body = null;

        if ($method === 'GET') {
            $url .= '?' . $queryString . '&signature=' . $signature;
        } else {
            $bodyParamsWithSignature = $params;
            $bodyParamsWithSignature['signature'] = $signature;
            $body = http_build_query($bodyParamsWithSignature, '', '&', PHP_QUERY_RFC3986);
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

        $requestPromise = (in_array($method, ['POST', 'PUT', 'DELETE']) && is_string($body) && !empty($body))
            ? $this->browser->request($method, $url, $finalHeaders + ['Content-Type' => 'application/x-www-form-urlencoded'], $body, $options)
            : $this->browser->request($method, $url, $finalHeaders, '', $options);

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
                if (isset($data['code']) && (int)$data['code'] < 0 && (int)$data['code'] !== -2011 ) { // Ignore -2011 'Order does not exist' for cancels primarily
                    $this->logger->error('Binance Futures API Error', $logCtx + ['api_code' => $data['code'], 'api_msg' => $data['msg'] ?? 'N/A']);
                    throw new \RuntimeException("Binance Futures API error ({$data['code']}): " . ($data['msg'] ?? 'Unknown error') . " for " . $url);
                }
                 // Allow 2xx status codes OR -2011 error code for specific cases like duplicate cancels
                if ($statusCode >= 300 && !(isset($data['code']) && (int)$data['code'] == -2011)) {
                     $this->logger->error('HTTP Error Status received from API', $logCtx + ['api_code' => $data['code'] ?? 'N/A', 'api_msg' => $data['msg'] ?? 'N/A', 'body_preview' => substr($body, 0, 200)]);
                     throw new \RuntimeException("HTTP error {$statusCode} for " . $url . ". Body: " . substr($body,0,200));
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
                    $responseData = json_decode($responseBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($responseData['code'], $responseData['msg'])) {
                        $logCtx['binance_api_code_from_exception'] = $responseData['code'];
                        $logCtx['binance_api_msg_from_exception'] = $responseData['msg'];
                    }
                }
                $this->logger->error('API Request Failed (Promise Rejected)', $logCtx);
                throw new \RuntimeException("API Req fail for {$method} " . parse_url($url, PHP_URL_PATH) . ": " . $e->getMessage(), 0, $e);
            }
        );
    }

    // --- Specific Binance Futures API Methods (using precision formatters) ---
    private function getPricePrecisionFormat(): string { return '%.1f'; } // BTCUSDT
    private function getQuantityPrecisionFormat(): string { return '%.3f'; } // BTCUSDT

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
                $this->logger->debug("Fetched futures balances", ['margin_asset' => $this->marginAsset, 'balance_data' => $balances[$this->marginAsset] ?? 'N/A']);
                return $balances;
            });
    }

    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface {
        $endpoint = '/fapi/v1/klines';
        $params = ['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => 1];
        $url = $this->currentRestApiBaseUrl . $endpoint . '?' . http_build_query($params);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
             ->then(function ($data) {
                 if (!is_array($data) || empty($data) || !isset($data[0][4])) throw new \RuntimeException("Invalid klines response format.");
                $price = (float)$data[0][4];
                if ($price <=0) throw new \RuntimeException("Invalid kline price: {$price}");
                return ['price' => $price, 'timestamp' => (int)$data[0][0]];
            });
    }

    private function getHistoricalKlines(string $symbol, string $interval, int $limit = 100): PromiseInterface {
        $endpoint = '/fapi/v1/klines';
        $params = ['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => $limit];
        $url = $this->currentRestApiBaseUrl . $endpoint . '?' . http_build_query($params);
        $this->logger->debug("Fetching historical klines", ['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit]);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
            ->then(function ($data) use ($symbol, $interval, $limit){
                if (!is_array($data)) {
                    $this->logger->warning("Invalid historical klines response format, expected array.", ['symbol' => $symbol]);
                    throw new \RuntimeException("Invalid klines response format for {$symbol}.");
                }
                $formattedKlines = array_map(function($kline) {
                    if (count($kline) >= 6) {
                        return ['openTime' => (int)$kline[0], 'open' => (string)$kline[1], 'high' => (string)$kline[2], 'low' => (string)$kline[3], 'close' => (string)$kline[4], 'volume' => (string)$kline[5]];
                    } return null;
                }, $data);
                $formattedKlines = array_filter($formattedKlines);
                $this->logger->debug("Fetched historical klines successfully", ['symbol' => $symbol, 'count' => count($formattedKlines)]);
                return $formattedKlines;
            });
    }

    private function getPositionInformation(string $symbol): PromiseInterface {
        $endpoint = '/fapi/v2/positionRisk';
        $params = ['symbol' => strtoupper($symbol)];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) {
                 if (!is_array($data)) throw new \RuntimeException("Invalid response for getPositionInformation: not an array.");
                $positionToReturn = null;
                if (is_array($data) && !empty($data) && isset($data[0]['symbol'])) {
                     foreach ($data as $pos) {
                        if (isset($pos['symbol']) && $pos['symbol'] === strtoupper($this->tradingSymbol)) {
                            $positionToReturn = $pos;
                            break;
                        }
                    }
                }
                if ($positionToReturn) {
                    $this->logger->debug("Fetched position information for {$this->tradingSymbol}", ['position_data_preview' => substr(json_encode($positionToReturn),0,100)]);
                    return $positionToReturn;
                }
                $this->logger->debug("No active position found for {$this->tradingSymbol} via getPositionInformation."); // Changed to debug
                return null;
            });
    }

    private function setLeverage(string $symbol, int $leverage): PromiseInterface {
        $endpoint = '/fapi/v1/leverage';
        $params = ['symbol' => strtoupper($symbol), 'leverage' => $leverage];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
             ->then(function ($data) use ($symbol, $leverage) {
                 $this->logger->info("Leverage set attempt result for {$symbol}", ['requested' => $leverage, 'response' => $data]);
                return $data;
            });
    }

    private function placeFuturesLimitOrder(string $symbol, string $side, float $quantity, float $price, ?string $timeInForce = 'GTC', ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($price <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid price/quantity for limit order. P:{$price} Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'LIMIT', 'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity),
            'price' => sprintf($this->getPricePrecisionFormat(), $price), 'timeInForce' => $timeInForce,
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesMarketOrder(string $symbol, string $side, float $quantity, ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid quantity for market order. Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'MARKET', 'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity),
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesStopMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice, bool $reduceOnly = true, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for STOP_MARKET. SP:{$stopPrice} Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'STOP_MARKET', 'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity),
            'stopPrice' => sprintf($this->getPricePrecisionFormat(), $stopPrice),
            'reduceOnly' => $reduceOnly ? 'true' : 'false', 'workingType' => 'MARK_PRICE'
        ];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesTakeProfitMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice, bool $reduceOnly = true, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
         if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for TAKE_PROFIT_MARKET. SP:{$stopPrice} Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => strtoupper($positionSide),
            'type' => 'TAKE_PROFIT_MARKET', 'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity),
            'stopPrice' => sprintf($this->getPricePrecisionFormat(), $stopPrice),
            'reduceOnly' => $reduceOnly ? 'true' : 'false', 'workingType' => 'MARK_PRICE'
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
        $this->getFuturesOrderStatus($this->tradingSymbol, $orderId)
        ->then(function (array $orderStatusData) use ($orderId, $orderTypeLabel) {
            $status = $orderStatusData['status'] ?? 'UNKNOWN';
            $this->logger->debug("Checked {$orderTypeLabel} order status (fallback)", ['orderId' => $orderId, 'status' => $status]);

             // Fallback check specifically for ENTRY orders that might have been missed by WS
             if (($orderTypeLabel === 'ENTRY' || $orderTypeLabel === 'ENTRY_TIMEOUT_CANCEL_FAILED') && $this->activeEntryOrderId === $orderId) {
                 if (in_array($status, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                     $this->logger->warning("Active entry order {$orderId} found as {$status} by fallback check. Resetting state via fallback.");
                     $this->addOrderToLog($orderId, $status, $orderStatusData['side'] ?? 'N/A', $this->tradingSymbol, (float)($orderStatusData['price'] ?? 0), (float)($orderStatusData['origQty'] ?? 0), $this->marginAsset, time(), (float)($orderStatusData['realizedPnl'] ?? 0));
                     $this->resetTradeState();
                     $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} found {$status} via fallback.", 'decision' => null];
                 } elseif ($status === 'FILLED') {
                      // This is tricky. If WS missed the fill, we don't have the AI's SL/TP params readily available here
                      // to place the protective orders. The safest fallback is probably to flag the critical state.
                     $this->logger->critical("CRITICAL FALLBACK: Active entry order {$orderId} found as FILLED by fallback check, but WS likely missed it. Position potentially unprotected!");
                     $this->isMissingProtectiveOrder = true;
                     $this->activeEntryOrderId = null; // Mark entry as done
                     $this->activeEntryOrderTimestamp = null;
                      // Update position details based on this order data if possible
                     $filledQty = (float)($orderStatusData['executedQty'] ?? 0);
                     $avgFilledPrice = (float)($orderStatusData['avgPrice'] ?? 0);
                     if ($filledQty > 0 && $avgFilledPrice > 0) {
                         $this->currentPositionDetails = [
                            'symbol' => $this->tradingSymbol,
                            'side' => $orderStatusData['side'] === 'BUY' ? 'LONG' : 'SHORT',
                            'entryPrice' => $avgFilledPrice, 'quantity' => $filledQty,
                            'leverage' => $this->currentPositionDetails['leverage'] ?? $this->defaultLeverage, // Try to preserve leverage if known
                            'markPrice' => $avgFilledPrice, 'unrealizedPnl' => 0 ];
                         $this->logger->info("Position details updated from fallback FILLED check.", $this->currentPositionDetails);
                     } else {
                         $this->logger->error("Could not update position details from fallback FILLED check due to missing data.", ['order_data' => $orderStatusData]);
                     }

                     $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Entry order {$orderId} found FILLED via fallback (WS missed?). Protective orders NOT placed.", 'decision' => null];
                     $this->triggerAIUpdate(true); // Ask AI to close the now-unprotected position
                 }
                 // else: Still NEW or PARTIALLY_FILLED, WS should handle transitions.
             }
             // No explicit fallback check for SL/TP status here, rely on WS or `isMissingProtectiveOrder` state.
        })
        ->catch(function (\Throwable $e) use ($orderId, $orderTypeLabel) {
            // Handle -2013 Order does not exist
            if (str_contains($e->getMessage(), '-2013') || stripos($e->getMessage(), 'Order does not exist') !== false) {
                $this->logger->info("{$orderTypeLabel} order {$orderId} not found on check (likely resolved or never existed).", ['err_preview' => substr($e->getMessage(),0,100)]);
                 if (($orderTypeLabel === 'ENTRY' || $orderTypeLabel === 'ENTRY_TIMEOUT_CANCEL_FAILED') && $this->activeEntryOrderId === $orderId) {
                    $this->logger->warning("Active entry order {$orderId} disappeared from exchange according to fallback check. Resetting state.");
                    $this->resetTradeState();
                     $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order {$orderId} not found via fallback.", 'decision' => null];
                 }
                 // If an SL/TP order disappears, the WS check for missing orders should catch it if position still exists.
            } else {
                $this->logger->error("Failed to get {$orderTypeLabel} order status (fallback).", ['orderId' => $orderId, 'exception' => $e->getMessage()]);
            }
        });
    }

    private function cancelFuturesOrder(string $symbol, string $orderId): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        $params = ['symbol' => strtoupper($symbol), 'orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function($data) use ($orderId){
                $this->logger->info("Cancel order request processed for {$orderId}", ['response_status' => $data['status'] ?? 'N/A', 'response_orderId' => $data['orderId'] ?? 'N/A']);
                return $data;
            });
            // Catch is handled by the caller (e.g., handlePositionClosed, timeout logic)
    }

    private function getFuturesTradeHistory(string $symbol, int $limit = 50): PromiseInterface {
        $endpoint = '/fapi/v1/userTrades';
        $params = ['symbol' => strtoupper($symbol), 'limit' => $limit];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function($data) use ($symbol) {
                $this->logger->debug("Fetched recent futures trades for {$symbol}", ['count' => is_array($data) ? count($data) : 'N/A']);
                return $data;
            });
    }

    // --- User Data Stream ListenKey Management ---
    private function startUserDataStream(): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        // POST request requires no parameters other than signature/timestamp/recvWindow in the body
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function keepAliveUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        // PUT request requires listenKey parameter
        $signedRequestData = $this->createSignedRequestData($endpoint, ['listenKey' => $listenKey], 'PUT');
        return $this->makeAsyncApiRequest('PUT', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function closeUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        // DELETE request requires listenKey parameter
        $signedRequestData = $this->createSignedRequestData($endpoint, ['listenKey' => $listenKey], 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }


    // --- AI Interaction Methods ---
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
                function ($rawResponse) {
                    return $this->processAIResponse($rawResponse);
                }
            )
            ->catch(function (\Throwable $e) {
                if ($e->getCode() !== 429) { // Don't log error for expected rate limit
                    $this->logger->error('AI update cycle failed.', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                    $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "AI update cycle failed: " . $e->getMessage(), 'decision' => null];
                } else {
                     $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "AI rate limit hit.", 'decision' => null];
                }
            });
    }

    private function collectDataForAI(): PromiseInterface
    {
        $this->logger->debug("Collecting data for AI...");
        $historicalKlineLimit = 100;

        // Fetch fresh data
        $promises = [
            'balance' => $this->getFuturesAccountBalance(),
            'position' => $this->getPositionInformation($this->tradingSymbol), // Fetch latest position info
            'trade_history' => $this->getFuturesTradeHistory($this->tradingSymbol, 10),
            'historical_klines' => $this->getHistoricalKlines($this->tradingSymbol, $this->klineInterval, $historicalKlineLimit)
        ];

        return \React\Promise\all($promises)->then(function (array $results) {
            // Process results and update internal state *before* sending to AI if needed
            // For example, update currentPositionDetails based on the fresh API call
            $this->currentPositionDetails = $this->formatPositionDetails($results['position']); // Update based on latest API data

            // Now build the data structure for the AI prompt using the potentially updated state
            $currentBalanceInfo = $results['balance'][$this->marginAsset] ?? ['availableBalance' => 0.0];
            $currentPositionRaw = $results['position']; // Send raw data from API as well

            $activeEntryOrderDetails = null;
            if($this->activeEntryOrderId && $this->activeEntryOrderTimestamp) {
                 $secondsPending = time() - $this->activeEntryOrderTimestamp;
                 $timeoutIn = max(0, $this->pendingEntryOrderCancelTimeoutSeconds - $secondsPending); // Ensure non-negative
                $activeEntryOrderDetails = [
                    'orderId' => $this->activeEntryOrderId,
                    'placedAt' => date('Y-m-d H:i:s', $this->activeEntryOrderTimestamp),
                    // Fetching side/price/qty from state set when order placed
                    'side' => $this->aiSuggestedSide ?? 'N/A',
                    'price' => $this->aiSuggestedEntryPrice ?? 0,
                    'quantity' => $this->aiSuggestedQuantity ?? 0,
                    'seconds_pending' => $secondsPending,
                    'timeout_in' => $timeoutIn
                ];
            }

            // Check consistency: If position exists, but SL/TP IDs are missing, flag it.
            // This handles cases where SL/TP might have been cancelled externally or failed placement partially.
             if ($this->currentPositionDetails && (!$this->activeSlOrderId || !$this->activeTpOrderId) && !$this->isPlacingOrManagingOrder) {
                 if (!$this->isMissingProtectiveOrder) { // Log only when the state changes to missing
                     $this->logger->warning("Detected open position missing one or both SL/TP orders internally.", [
                         'position' => $this->currentPositionDetails,
                         'sl_id' => $this->activeSlOrderId,
                         'tp_id' => $this->activeTpOrderId
                     ]);
                 }
                 $this->isMissingProtectiveOrder = true;
             } elseif (!$this->currentPositionDetails) {
                 $this->isMissingProtectiveOrder = false; // No position, so can't be missing orders
             }
             // Note: isMissingProtectiveOrder is also set true on placement failure or margin call


            $dataForAI = [
                'current_timestamp' => date('Y-m-d H:i:s'),
                'current_market_price' => $this->lastClosedKlinePrice,
                'current_margin_asset_balance' => $currentBalanceInfo['availableBalance'],
                'margin_asset' => $this->marginAsset,
                'current_position_details_formatted' => $this->currentPositionDetails, // Formatted internal state
                'current_position_raw' => $currentPositionRaw, // Raw API data for position
                'active_pending_entry_order' => $activeEntryOrderDetails,
                'position_missing_protective_orders' => $this->isMissingProtectiveOrder, // NEW state flag
                'last_ai_decision_result' => $this->lastAIDecisionResult, // NEW history field
                'historical_klines' => $results['historical_klines'] ?? [],
                'recent_trade_outcomes' => $this->recentOrderLogs,
                'recent_account_trades' => array_map(function($trade){
                    return ['price' => $trade['price'], 'qty' => $trade['qty'], 'commission' => $trade['commission'], 'realizedPnl' => $trade['realizedPnl'], 'side' => $trade['side'], 'time' => date('Y-m-d H:i:s', (int)($trade['time']/1000))];
                }, (is_array($results['trade_history']) ? $results['trade_history'] : [])),
                'current_bot_parameters' => [
                    'tradingSymbol' => $this->tradingSymbol,
                    'klineInterval' => $this->klineInterval,
                    'defaultLeverage' => $this->defaultLeverage,
                    'aiUpdateIntervalSeconds' => $this->aiUpdateIntervalSeconds,
                    'pendingEntryOrderTimeoutSeconds' => $this->pendingEntryOrderCancelTimeoutSeconds,
                ],
                 'trade_logic_summary' => "Bot trades {$this->tradingSymbol} futures using {$this->klineInterval} klines. AI provides parameters. Bot places LIMIT entry. On fill, STOP_MARKET SL and TAKE_PROFIT_MARKET TP are placed. Pending entry orders timeout after {$this->pendingEntryOrderCancelTimeoutSeconds}s. AI can suggest early position close. If SL/TP placement fails, 'position_missing_protective_orders' becomes true.",
            ];
            $this->logger->debug("Data collected for AI", [
                'market_price' => $dataForAI['current_market_price'],
                'balance' => $dataForAI['current_margin_asset_balance'],
                'position_exists' => !is_null($this->currentPositionDetails),
                'pending_entry_exists' => !is_null($activeEntryOrderDetails),
                'missing_orders_flag' => $this->isMissingProtectiveOrder,
                'kline_count' => is_array($dataForAI['historical_klines']) ? count($dataForAI['historical_klines']) : 'N/A' // Safely count
                ]);
            return $dataForAI;
        })->catch(function (\Throwable $e) {
            $this->logger->error("Failed to collect data for AI.", ['exception_class'=>get_class($e), 'exception' => $e->getMessage()]);
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed data collection: " . $e->getMessage(), 'decision' => null];
            throw $e; // Re-throw so triggerAIUpdate catches it
        });
    }

    private function constructAIPrompt(array $dataForAI, bool $isEmergency): string
    {
        $promptText = "You are an AI trading assistant for Binance USDM Futures ({$this->tradingSymbol}, {$this->klineInterval}). Your goal is to manage risk and maximize profit.\n\n";
        if ($isEmergency) {
            $promptText .= "INFO: This is an emergency AI trigger, likely due to an unexpected state or error. Review carefully.\n\n";
        }
        $promptText .= "=== Current Bot State & Data ===\n";
        $dataForPrompt = $dataForAI; // Create a copy to modify for prompt length
        // Abbreviate long data fields for prompt clarity
        if (isset($dataForPrompt['historical_klines'])) { $dataForPrompt['historical_klines'] = "[Showing last " . min(count($dataForAI['historical_klines']), 50) . " klines of ".count($dataForAI['historical_klines'])."...]"; }
        if (isset($dataForPrompt['recent_trade_outcomes'])) { $dataForPrompt['recent_trade_outcomes'] = "[Showing last " . min(count($dataForAI['recent_trade_outcomes']), 5) . " outcomes...]"; }
        if (isset($dataForPrompt['recent_account_trades'])) { $dataForPrompt['recent_account_trades'] = "[Showing last " . min(count($dataForAI['recent_account_trades']), 5) . " trades...]"; }
        $promptText .= json_encode($dataForPrompt, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES) . "\n\n";

        // --- Determine Current Bot State & Possible Actions ---
        $stateDescription = "Unknown";
        $possibleActions = [];

        if ($dataForAI['position_missing_protective_orders']) {
            $stateDescription = "CRITICAL: Position Open BUT Protective Orders (SL/TP) Missing!";
            $possibleActions = ['CLOSE_POSITION', 'HOLD_POSITION']; // Hold is highly risky
        } elseif ($dataForAI['active_pending_entry_order']) {
            $stateDescription = "Waiting for Pending Entry Order ({$dataForAI['active_pending_entry_order']['timeout_in']}s remaining)";
            $possibleActions = ['HOLD_POSITION']; // Only sensible action is to wait
        } elseif ($dataForAI['current_position_details_formatted']) {
            $stateDescription = "Position Currently Open (with SL/TP presumed active)";
            $possibleActions = ['HOLD_POSITION', 'CLOSE_POSITION'];
        } else {
            $stateDescription = "No Position and No Pending Entry Order";
            $possibleActions = ['OPEN_POSITION', 'DO_NOTHING'];
        }

        $promptText .= "=== Analysis Task ===\n";
        $promptText .= "Current State: {$stateDescription}\n";
        $promptText .= "Last AI Action Result: " . json_encode($dataForAI['last_ai_decision_result'] ?? 'None', JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES) . "\n";
        $promptText .= "Analyze all provided data. Based ONLY on the current state ('{$stateDescription}'), choose ONE of the following relevant actions:\n\n";

        // --- Dynamically List Possible Actions ---
        $actionList = [];
        $actionCounter = 1;
        if (in_array('OPEN_POSITION', $possibleActions)) {
            $actionList[] = "{$actionCounter}. Open New Position: `{\"action\": \"OPEN_POSITION\", \"leverage\": <int>, \"side\": \"BUY\"|\"SELL\", \"entryPrice\": <float_1dec>, \"quantity\": <float_3dec>, \"stopLossPrice\": <float_1dec>, \"takeProfitPrice\": <float_1dec>}`\n   - Use ONLY if state is 'No Position and No Pending Entry Order'. Follow precision rules (BTCUSDT: Qty 3 dec, Price 1 dec). Aim for ~100 USD risk on SL.";
            $actionCounter++;
        }
        if (in_array('CLOSE_POSITION', $possibleActions)) {
             $actionList[] = "{$actionCounter}. Close Current Position: `{\"action\": \"CLOSE_POSITION\", \"reason\": \"<brief_reason>\"}`\n   - Strongly recommended if state is 'CRITICAL: ... Missing!'. Also use if analysis suggests immediate exit from a normal open position.";
             $actionCounter++;
        }
        if (in_array('HOLD_POSITION', $possibleActions)) {
            $actionList[] = "{$actionCounter}. Hold / Wait: `{\"action\": \"HOLD_POSITION\"}`\n   - Use if 'Waiting for Pending Entry Order'. Use if 'Position Currently Open' and analysis suggests keeping it. Can use cautiously if 'CRITICAL: ... Missing!' but closing is safer.";
            $actionCounter++;
        }
         if (in_array('DO_NOTHING', $possibleActions)) {
             // Explicitly add DO_NOTHING only if it's a valid alternative to OPEN_POSITION
             if (count($possibleActions) == 2 && in_array('OPEN_POSITION', $possibleActions)) {
                 $actionList[] = "{$actionCounter}. Do Nothing: `{\"action\": \"DO_NOTHING\"}`\n   - Use ONLY if state is 'No Position and No Pending Entry Order' AND no suitable entry setup is found.";
                 $actionCounter++;
             }
         }

        $promptText .= implode("\n", $actionList);
        $promptText .= "\n\n";

        $promptText .= "=== Important Rules & Guidelines ===\n";
        $promptText .= "- Adhere strictly to the Possible Actions listed for the current state.\n";
        $promptText .= "- BTCUSDT Precision: Quantity = 3 decimal places (multiple of 0.001), Price = 1 decimal place (multiple of 0.1).\n";
        $promptText .= "- Testnet Mode: Be proactive in finding trades ONLY when state is 'No Position and No Pending Entry Order'. Avoid `DO_NOTHING` in that state if a reasonable setup exists.\n";
        $promptText .= "- Risk Target: New positions should target ~\$100 potential loss if SL hits (`abs(entryPrice - stopLossPrice) * quantity` should approximate 100).\n";
        $promptText .= "- Critical State: If `position_missing_protective_orders` is true, PRIORITY IS TO CLOSE THE POSITION for safety.\n";
        $promptText .= "- Logic: Ensure SL/TP prices are logical relative to entry and side (SL protects, TP targets profit).\n\n";
        $promptText .= "- Am on testnet.Ignore do nothing! Give active order to test logic after active order.\n\n";

        $promptText .= "Provide ONLY the JSON object for your chosen action as the response. No extra text or markdown formatting.";

        // Consider adding safety settings to the generation config if needed
        // 'safetySettings': [{'category': 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold': 'BLOCK_NONE'}, ...]
        return json_encode(['contents' => [['parts' => [['text' => $promptText]]]], 'generationConfig' => ['temperature' => 0.6]]);
    }

    private function sendRequestToAI(string $jsonPayload): PromiseInterface
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModelName . ':generateContent?key=' . $this->geminiApiKey;
        $headers = ['Content-Type' => 'application/json'];
        $this->logger->debug('Sending request to Gemini AI', ['url_path' => 'models/' . $this->geminiModelName . ':generateContent', 'payload_length' => strlen($jsonPayload)]);

        return $this->browser->post($url, $headers, $jsonPayload)->then(
            function (ResponseInterface $response) {
                $body = (string)$response->getBody();
                $this->logger->debug('Received response from Gemini AI', ['status' => $response->getStatusCode(), 'body_preview' => substr($body, 0, 200) . '...']);
                if ($response->getStatusCode() >= 300) {
                    if ($response->getStatusCode() === 429) {
                        $this->logger->warning('Gemini API rate limit hit (429 Too Many Requests). Will retry later.');
                        throw new \RuntimeException("HTTP status code 429 (Too Many Requests)", 429);
                    }
                    throw new \RuntimeException("Gemini API HTTP error: " . $response->getStatusCode() . " Body: " . substr($body, 0, 500));
                }
                return $body;
            },
            function (\Throwable $e) {
                // Don't log error again here if already logged in triggerAIUpdate catch block for non-429 errors
                if ($e->getCode() === 429) {
                     $this->logger->warning('Gemini API request failed (429 Rate Limit)', ['exception' => $e->getMessage()]);
                } elseif (!isset($this->lastAIDecisionResult) || $this->lastAIDecisionResult['status'] !== 'ERROR') {
                    // Log only if not already logged as a cycle failure or rate limit
                    $this->logger->error('Gemini AI request failed (Network/Client Error)', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                }
                throw $e; // Re-throw
            }
        );
    }

    private function processAIResponse(string $rawResponse): void
    {
        $this->logger->debug('Processing AI response.', ['raw_response_preview' => substr($rawResponse,0,100)]);
        try {
            $responseDecoded = json_decode($rawResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Failed to decode AI JSON response: " . json_last_error_msg());
            }

            // Handle potential Gemini safety blocks or empty responses
             if (!isset($responseDecoded['candidates'][0]['content']['parts'][0]['text'])) {
                 $finishReason = $responseDecoded['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                 $blockReason = $responseDecoded['promptFeedback']['blockReason'] ?? 'None';
                 $safetyRatings = json_encode($responseDecoded['candidates'][0]['safetyRatings'] ?? []);
                 throw new \InvalidArgumentException("AI response missing text. Finish Reason: {$finishReason}. Block Reason: {$blockReason}. Safety Ratings: {$safetyRatings}. Full response: " . substr($rawResponse,0,500));
             }
             $aiTextResponse = $responseDecoded['candidates'][0]['content']['parts'][0]['text'];


            $paramsJson = trim($aiTextResponse);
            // Clean potential markdown
            if (str_starts_with($paramsJson, '```json')) $paramsJson = substr($paramsJson, 7);
            if (str_starts_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 3);
            if (str_ends_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 0, -3);
            $paramsJson = trim($paramsJson);

            $aiDecision = json_decode($paramsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Failed to decode JSON parameters from AI's text response.", [
                    'ai_text_response_preview' => substr($aiTextResponse, 0, 200), 'json_error' => json_last_error_msg()
                ]);
                throw new \InvalidArgumentException("Failed to decode JSON from AI: " . json_last_error_msg() . " - Input: " . substr($aiTextResponse,0,100));
            }
            $this->executeAIDecision($aiDecision);

        } catch (\Throwable $e) {
            $this->logger->error('Error processing AI response or executing decision.', [
                'exception_class' => get_class($e), 'exception' => $e->getMessage(), 'raw_response_preview' => substr($rawResponse, 0, 500)
            ]);
             $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed processing AI response: " . $e->getMessage(), 'decision' => null];
        }
    }

    private function executeAIDecision(array $decision): void
    {
        $action = strtoupper($decision['action'] ?? 'UNKNOWN');
        $this->logger->info("AI Decision Received", ['action' => $action, 'details' => $decision]);
        $this->lastAIDecisionResult = null; // Reset before processing new decision

        // --- Validate AI action against current state ---
        $isValidAction = true;
        $actionToExecute = $action; // Start with AI's suggestion

        if ($this->isMissingProtectiveOrder) {
             if ($action !== 'CLOSE_POSITION' && $action !== 'HOLD_POSITION') {
                 $this->logger->error("Invalid AI Action: Bot in CRITICAL state (missing SL/TP), but AI suggested '{$action}'. Forcing CLOSE.", ['decision' => $decision]);
                 $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Invalid AI Action '{$action}' during critical state. Forcing Close.", 'decision' => $decision];
                 $actionToExecute = 'CLOSE_POSITION'; // Override to CLOSE for safety
                 $isValidAction = true; // Allow the overridden action
             } elseif ($action === 'HOLD_POSITION') {
                 $this->logger->warning("AI requested HOLD during CRITICAL state (missing SL/TP). Proceeding with HOLD, but this is risky.", ['decision' => $decision]);
                 // Allow HOLD, but it's noted as risky.
             }
        } elseif ($this->activeEntryOrderId) {
             if ($action === 'OPEN_POSITION') {
                 $this->logger->warning("Invalid AI Action: Bot has pending entry order, but AI suggested 'OPEN_POSITION'. Forcing HOLD.", ['decision' => $decision]);
                 $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Ignored AI OPEN_POSITION due to pending entry order. Forcing Hold.", 'decision' => $decision];
                 $actionToExecute = 'HOLD_POSITION'; // Override to HOLD
                 $isValidAction = false; // Prevent original action logic
             }
         } elseif ($this->currentPositionDetails) {
             if ($action === 'OPEN_POSITION' || $action === 'DO_NOTHING') {
                  $this->logger->warning("Invalid AI Action: Bot has open position, but AI suggested '{$action}'. Forcing HOLD.", ['decision' => $decision]);
                  $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Ignored AI '{$action}' due to open position. Forcing Hold.", 'decision' => $decision];
                  $actionToExecute = 'HOLD_POSITION'; // Override to HOLD
                  $isValidAction = false; // Prevent original action logic
             }
         } else { // No position, no pending entry
             if ($action === 'CLOSE_POSITION' || ($action === 'HOLD_POSITION' && !isset($decision['reason'])) /* Allow hold if reason given, else treat as do nothing */ ) {
                 $this->logger->warning("Invalid AI Action: Bot has no position/pending order, but AI suggested '{$action}'. Forcing DO_NOTHING.", ['decision' => $decision]);
                 $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Ignored AI '{$action}' as no position/pending order exists. Forcing Do Nothing.", 'decision' => $decision];
                 $actionToExecute = 'DO_NOTHING';
                 $isValidAction = false; // Prevent original action logic
             }
         }


        // --- Execute Action ---
        switch ($actionToExecute) {
            case 'OPEN_POSITION':
                if (!$isValidAction) break; // Should have been caught/overridden above
                // Assign AI parameters
                $this->aiSuggestedLeverage = (int)($decision['leverage'] ?? $this->defaultLeverage);
                $this->aiSuggestedSide = strtoupper($decision['side'] ?? '');
                $this->aiSuggestedEntryPrice = (float)($decision['entryPrice'] ?? 0);
                $this->aiSuggestedQuantity = (float)($decision['quantity'] ?? 0);
                $this->aiSuggestedSlPrice = (float)($decision['stopLossPrice'] ?? 0);
                $this->aiSuggestedTpPrice = (float)($decision['takeProfitPrice'] ?? 0);

                // Log potential validation issues found previously, but proceed
                 if (!in_array($this->aiSuggestedSide, ['BUY', 'SELL']) || $this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedQuantity <= 0 || $this->aiSuggestedSlPrice <= 0 || $this->aiSuggestedTpPrice <= 0) {
                     $this->logger->error("AI OPEN_POSITION: Parameters appear invalid (zero/negative) - attempting anyway.", $decision);
                 }
                 if (fmod(round($this->aiSuggestedQuantity,3), 0.001) > 1e-9) { // Use tolerance for float comparison
                     $this->logger->warning("AI OPEN_POSITION: Suggested quantity {$this->aiSuggestedQuantity} not multiple of 0.001 - attempting anyway.", $decision);
                 }
                 if (fmod(round($this->aiSuggestedEntryPrice,1), 0.1) > 1e-9 || fmod(round($this->aiSuggestedSlPrice,1), 0.1) > 1e-9 || fmod(round($this->aiSuggestedTpPrice,1), 0.1) > 1e-9) {
                      $this->logger->warning("AI OPEN_POSITION: Suggested prices do not meet 0.1 step size - attempting anyway.", $decision);
                 }
                 if ($this->aiSuggestedSide === 'BUY' && ($this->aiSuggestedSlPrice >= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice <= $this->aiSuggestedEntryPrice)) {
                      $this->logger->error("AI OPEN_POSITION (BUY): Illogical SL/TP vs Entry - attempting anyway.", $decision);
                 }
                  if ($this->aiSuggestedSide === 'SELL' && ($this->aiSuggestedSlPrice <= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice >= $this->aiSuggestedEntryPrice)) {
                      $this->logger->error("AI OPEN_POSITION (SELL): Illogical SL/TP vs Entry - attempting anyway.", $decision);
                  }

                 if ($this->aiSuggestedQuantity <= 0 || $this->aiSuggestedEntryPrice <=0) {
                     $this->logger->error("AI OPEN_POSITION: Final check - quantity or entry price is zero/negative. Cannot proceed.");
                      $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Rejected OPEN_POSITION: Invalid qty/price.", 'decision' => $decision];
                 } else {
                     // Attempt to open position (outcome stored in $lastAIDecisionResult within the attempt function)
                     $this->attemptOpenPosition();
                 }
                break;

            case 'CLOSE_POSITION':
                 // Attempt to close (outcome stored in $lastAIDecisionResult within the attempt function)
                $this->attemptClosePositionByAI();
                break;

            case 'HOLD_POSITION':
                 $this->logger->info("AI recommended HOLD / Wait. No trade action taken.", ['reason' => $decision['reason'] ?? 'N/A']);
                 $this->lastAIDecisionResult = ['status' => 'OK', 'message' => 'Holding position/state as per AI.', 'decision' => $decision];
                 break;

            case 'DO_NOTHING':
                 $this->logger->info("AI recommended DO_NOTHING. No trade action taken.");
                 $this->lastAIDecisionResult = ['status' => 'OK', 'message' => 'No action taken as per AI.', 'decision' => $decision];
                 break;

            default: // Includes UNKNOWN
                $this->logger->warning("Unknown or unhandled AI action received.", ['action' => $actionToExecute, 'original_decision' => $decision]);
                $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => "Unknown AI action '{$actionToExecute}'.", 'decision' => $decision];
        }
    }
}


// --- Script Execution ---
$loop = Loop::get();

$bot = new AiTradingBotFutures(
    binanceApiKey: $binanceApiKey,
    binanceApiSecret: $binanceApiSecret,
    geminiApiKey: $geminiApiKey,
    geminiModelName: $geminiModelName,
    tradingSymbol: 'BTCUSDT',
    klineInterval: '1m',
    marginAsset: 'USDT',
    defaultLeverage: 10,
    amountPercentage: 10.0, // This is currently unused, AI calculates absolute quantity
    orderCheckIntervalSeconds: 5, // Check reasonably often for timeouts/fallbacks
    maxScriptRuntimeSeconds: 86400, // 24 hours
    aiUpdateIntervalSeconds: 10, // AI update interval
    useTestnet: $useTestnet,
    pendingEntryOrderCancelTimeoutSeconds: 30 // 3 minutes timeout for pending entry
);

$bot->run();

?>