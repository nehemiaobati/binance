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
                    $this->stop();
                });
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->stop();
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                $this->stop();
            }
        );
    }

    private function setupTimers(): void
    {
        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder) {
                // Check for timeout first
                if ($this->activeEntryOrderTimestamp !== null &&
                    (time() - $this->activeEntryOrderTimestamp) > $this->pendingEntryOrderCancelTimeoutSeconds) {
                    
                    $this->logger->warning("Pending entry order {$this->activeEntryOrderId} timed out after {$this->pendingEntryOrderCancelTimeoutSeconds} seconds. Attempting cancellation.");
                    $this->isPlacingOrManagingOrder = true; // Lock during cancellation
                    $orderIdToCancel = $this->activeEntryOrderId; 
                    // Store AI params at time of order for logging, in case they change before log
                    $timedOutOrderSide = $this->aiSuggestedSide;
                    $timedOutOrderPrice = $this->aiSuggestedEntryPrice;
                    $timedOutOrderQty = $this->aiSuggestedQuantity;


                    $this->cancelFuturesOrder($this->tradingSymbol, $orderIdToCancel)
                        ->then(
                            function ($cancellationData) use ($orderIdToCancel, $timedOutOrderSide, $timedOutOrderPrice, $timedOutOrderQty) {
                                $this->logger->info("Pending entry order {$orderIdToCancel} successfully cancelled due to timeout.", ['response_status' => $cancellationData['status'] ?? 'N/A']);
                                // The ORDER_TRADE_UPDATE event for CANCELED status should primarily handle resetting state.
                                // This is a fallback/confirmation.
                                if ($this->activeEntryOrderId === $orderIdToCancel) { 
                                     $this->addOrderToLog(
                                        $orderIdToCancel,
                                        'CANCELED_TIMEOUT', 
                                        $timedOutOrderSide,
                                        $this->tradingSymbol,
                                        $timedOutOrderPrice,
                                        $timedOutOrderQty,
                                        $this->marginAsset,
                                        time(),
                                        0.0 
                                    );
                                    $this->resetTradeState(); 
                                }
                            },
                            function (\Throwable $e) use ($orderIdToCancel) {
                                $this->logger->error("Failed to cancel timed-out pending entry order {$orderIdToCancel}.", ['exception' => $e->getMessage()]);
                                // If cancellation fails, it might have filled or been cancelled just before.
                                // The regular checkActiveOrderStatus or WS events will eventually pick it up.
                                if ($this->activeEntryOrderId === $orderIdToCancel) {
                                     $this->checkActiveOrderStatus($orderIdToCancel, 'ENTRY_TIMEOUT_CANCEL_FAILED');
                                }
                            }
                        )
                        ->finally(function () {
                            $this->isPlacingOrManagingOrder = false;
                        });
                } else {
                    // If not timed out, perform the regular status check
                    $this->checkActiveOrderStatus($this->activeEntryOrderId, 'ENTRY');
                }
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
                if (isset($eventData['a']['P'])) {
                    foreach($eventData['a']['P'] as $posData) {
                        if ($posData['s'] === $this->tradingSymbol) {
                            $oldUnrealizedPnl = $this->currentPositionDetails['unrealizedPnl'] ?? 0;
                            $newPositionDetails = $this->formatPositionDetails($posData); 
                            if ($newPositionDetails) {
                                $this->currentPositionDetails = $newPositionDetails;
                                $this->logger->info("Position update from ACCOUNT_UPDATE", [
                                    'symbol' => $this->tradingSymbol,
                                    'amount' => $posData['pa'],
                                    'entry_price' => $posData['ep'],
                                    'unrealized_pnl' => $posData['up'],
                                    'old_unrealized_pnl' => $oldUnrealizedPnl
                                ]);
                            } else {
                                $currentQty = $this->currentPositionDetails['quantity'] ?? 0;
                                if ((float)$posData['pa'] == 0 && (float)$currentQty != 0) {
                                     $this->logger->info("Position for {$this->tradingSymbol} detected as closed via ACCOUNT_UPDATE (amount is zero).", [
                                        'reason_code' => $posData['cr'] ?? 'N/A'
                                    ]);
                                    $this->handlePositionClosed();
                                } else {
                                     $this->logger->debug("Received ACCOUNT_UPDATE for zero position, no action needed.", ['symbol' => $this->tradingSymbol]);
                                }
                            }
                        }
                    }
                }
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

                if ($orderId === $this->activeEntryOrderId) {
                    if ($orderStatus === 'FILLED' || $orderStatus === 'PARTIALLY_FILLED') {
                        $filledQty = (float)$order['z'];
                        $avgFilledPrice = (float)$order['ap'];
                        if (!$this->currentPositionDetails || (float)($this->currentPositionDetails['quantity'] ?? 0) == 0) {
                             $this->currentPositionDetails = [
                                'symbol' => $this->tradingSymbol,
                                'side' => $order['S'] === 'BUY' ? 'LONG' : 'SHORT',
                                'entryPrice' => $avgFilledPrice,
                                'quantity' => $filledQty,
                                'leverage' => $this->aiSuggestedLeverage,
                                'markPrice' => $avgFilledPrice,
                                'unrealizedPnl' => 0
                            ];
                            $this->logger->info("Entry order (partially/fully) filled. Updated position.", $this->currentPositionDetails);
                        } else {
                             $existingQty = (float)$this->currentPositionDetails['quantity'];
                             $existingEntry = (float)$this->currentPositionDetails['entryPrice'];
                             $this->currentPositionDetails['entryPrice'] = (($existingEntry * $existingQty) + ($avgFilledPrice * $filledQty)) / ($existingQty + $filledQty);
                             $this->currentPositionDetails['quantity'] = $existingQty + $filledQty;
                             $this->logger->info("Entry order increased existing position.", $this->currentPositionDetails);
                        }


                        if ($orderStatus === 'FILLED') {
                            $this->logger->info("Entry order fully filled: {$this->activeEntryOrderId}. Placing SL/TP orders.");
                            $this->activeEntryOrderId = null; 
                            $this->activeEntryOrderTimestamp = null; // Clear timestamp on fill
                            $this->placeSlAndTpOrders();
                        }
                    } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED', 'NEW_INSURANCE', 'NEW_ADL'])) {
                        $this->logger->warning("Active entry order {$this->activeEntryOrderId} ended without fill via WS: {$orderStatus}. Resetting.");
                        $this->addOrderToLog(
                            $orderId, $orderStatus, $order['S'], $this->tradingSymbol,
                            (float)$order['p'], (float)$order['q'], $this->marginAsset, time(), (float)($order['rp'] ?? 0)
                        );
                        $this->resetTradeState(); // This clears activeEntryOrderId and activeEntryOrderTimestamp
                    }
                } elseif ($orderId === $this->activeSlOrderId || $orderId === $this->activeTpOrderId) {
                    if ($orderStatus === 'FILLED') {
                        $logSide = 'UNKNOWN';
                        if ($this->currentPositionDetails && isset($this->currentPositionDetails['side'])) {
                             $logSide = ($this->currentPositionDetails['side'] === 'LONG' ? 'SELL' : 'BUY');
                        }
                        $this->logger->info("{$order['ot']} order {$orderId} (SL/TP) filled. Position closed.", [
                            'realized_pnl' => $order['rp'] ?? 'N/A'
                        ]);
                        $this->addOrderToLog(
                            $orderId, $orderStatus, $logSide,
                            $this->tradingSymbol, (float)($order['ap'] > 0 ? $order['ap'] : $order['sp']), (float)$order['z'],
                            $this->marginAsset, time(), (float)($order['rp'] ?? 0)
                        );
                        $this->handlePositionClosed($orderId === $this->activeSlOrderId ? $this->activeTpOrderId : $this->activeSlOrderId);
                    } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                         $this->logger->warning("SL/TP order {$orderId} ended without fill: {$orderStatus}. This might be due to manual cancellation or other logic.");
                         if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                         if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
                         if ($this->currentPositionDetails && !$this->activeSlOrderId && !$this->activeTpOrderId) {
                              $this->logger->warning("Position open but SL/TP orders are gone unexpectedly. Triggering AI.");
                              $this->triggerAIUpdate(true); 
                         }
                    }
                }
                break;
            case 'listenKeyExpired':
                $this->logger->warning("ListenKey expired. Attempting to get a new one and reconnect WebSocket.");
                $this->listenKey = null;
                if ($this->wsConnection) $this->wsConnection->close();
                $this->startUserDataStream()->then(function ($data) {
                    $this->listenKey = $data['listenKey'] ?? null;
                    if ($this->listenKey) {
                        $this->logger->info("New ListenKey obtained. Reconnecting WebSocket.");
                        $this->connectWebSocket();
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
                $this->triggerAIUpdate(true);
                break;
            default:
                $this->logger->debug('Unhandled user data event type', ['type' => $eventType]);
        }
    }

    private function formatPositionDetails(?array $positionsInput): ?array
    {
        if (empty($positionsInput)) return null;
        $positionData = null;
        if (isset($positionsInput['symbol']) && isset($positionsInput['positionAmt'])) {
             if ($positionsInput['symbol'] === $this->tradingSymbol && (float)($positionsInput['positionAmt'] ?? $positionsInput['pa'] ?? 0) != 0) {
                $positionData = $positionsInput;
             }
        }
        else if (is_array($positionsInput) && isset($positionsInput[0]['symbol'])) {
            foreach ($positionsInput as $p) {
                if (isset($p['symbol']) && $p['symbol'] === $this->tradingSymbol && (float)($p['positionAmt'] ?? $p['pa'] ?? 0) != 0) {
                    $positionData = $p;
                    break;
                }
            }
        }
        if (!$positionData) return null;
        $quantity = (float)($positionData['positionAmt'] ?? $positionData['pa'] ?? 0);
        $entryPrice = (float)($positionData['entryPrice'] ?? $positionData['ep'] ?? 0);
        $markPrice = (float)($positionData['markPrice'] ?? $positionData['mp'] ?? ($this->lastClosedKlinePrice ?? 0) );
        $unrealizedPnl = (float)($positionData['unrealizedProfit'] ?? $positionData['up'] ?? 0);
        $leverage = (int)($positionData['leverage'] ?? 0);
        $side = $quantity > 0 ? 'LONG' : ($quantity < 0 ? 'SHORT' : 'NONE');
        if ($side === 'NONE') return null;

        return [
            'symbol' => $this->tradingSymbol,
            'side' => $side,
            'entryPrice' => $entryPrice,
            'quantity' => abs($quantity),
            'leverage' => $leverage ?: $this->aiSuggestedLeverage,
            'markPrice' => $markPrice,
            'unrealizedPnl' => $unrealizedPnl,
            'initialMargin' => (float)($positionData['initialMargin'] ?? $positionData['iw'] ?? 0),
            'maintMargin' => (float)($positionData['maintMargin'] ?? 0),
            'isolatedWallet' => (float)($positionData['isolatedWallet'] ?? 0),
        ];
    }

    private function placeSlAndTpOrders(): void
    {
        if (!$this->currentPositionDetails || $this->isPlacingOrManagingOrder) {
            $this->logger->warning("Cannot place SL/TP: No current position or operation in progress.");
            return;
        }
        $this->isPlacingOrManagingOrder = true;

        $positionSide = $this->currentPositionDetails['side'];
        $quantity = (float)$this->currentPositionDetails['quantity'];
        $orderSideForSlTp = ($positionSide === 'LONG') ? 'SELL' : 'BUY';

        $slOrderPromise = $this->placeFuturesStopMarketOrder(
            $this->tradingSymbol,
            $orderSideForSlTp,
            $quantity,
            $this->aiSuggestedSlPrice,
            true
        )->then(function ($orderData) {
            $this->activeSlOrderId = (string)$orderData['orderId'];
            $this->logger->info("Stop Loss order placed.", ['orderId' => $this->activeSlOrderId, 'stopPrice' => $this->aiSuggestedSlPrice]);
            return $orderData;
        });

        $tpOrderPromise = $this->placeFuturesTakeProfitMarketOrder(
            $this->tradingSymbol,
            $orderSideForSlTp,
            $quantity,
            $this->aiSuggestedTpPrice,
            true
        )->then(function ($orderData) {
            $this->activeTpOrderId = (string)$orderData['orderId'];
            $this->logger->info("Take Profit order placed.", ['orderId' => $this->activeTpOrderId, 'stopPrice' => $this->aiSuggestedTpPrice]);
            return $orderData;
        });

        \React\Promise\all([$slOrderPromise, $tpOrderPromise])
            ->then(
                function () {
                    $this->logger->info("SL and TP orders successfully placed.");
                    $this->isPlacingOrManagingOrder = false;
                },
                function (\Throwable $e) {
                    $this->logger->error("Error placing SL/TP orders. Manual intervention may be needed.", [
                        'exception_class' => get_class($e), 'exception' => $e->getMessage(),
                        'sl_order_id' => $this->activeSlOrderId, 'tp_order_id' => $this->activeTpOrderId
                    ]);
                    if ($this->activeSlOrderId && !$this->activeTpOrderId) $this->cancelFuturesOrder($this->tradingSymbol, $this->activeSlOrderId);
                    if ($this->activeTpOrderId && !$this->activeSlOrderId) $this->cancelFuturesOrder($this->tradingSymbol, $this->activeTpOrderId);
                    $this->isPlacingOrManagingOrder = false;
                    $this->triggerAIUpdate(true);
                }
            );
    }

    private function handlePositionClosed(?string $otherOrderIdToCancel = null): void
    {
        $this->logger->info("Position closed for {$this->tradingSymbol}. Resetting state.");
        if ($otherOrderIdToCancel) {
            $this->cancelFuturesOrder($this->tradingSymbol, $otherOrderIdToCancel)->then(
                fn() => $this->logger->info("Successfully cancelled other SL/TP order: {$otherOrderIdToCancel}"),
                fn($e) => $this->logger->error("Failed to cancel other SL/TP order: {$otherOrderIdToCancel}", ['err' => $e->getMessage()])
            );
        } else {
            if ($this->activeSlOrderId) {
                $this->cancelFuturesOrder($this->tradingSymbol, $this->activeSlOrderId);
                $this->logger->info("Attempting to cancel active SL order: {$this->activeSlOrderId}");
            }
            if ($this->activeTpOrderId) {
                $this->cancelFuturesOrder($this->tradingSymbol, $this->activeTpOrderId);
                 $this->logger->info("Attempting to cancel active TP order: {$this->activeTpOrderId}");
            }
        }
        $this->resetTradeState();
        $this->loop->addTimer(10, function () {
            $this->triggerAIUpdate();
        });
    }

    private function resetTradeState(): void {
        $this->logger->info("Resetting trade state.");
        $this->activeEntryOrderId = null;
        $this->activeEntryOrderTimestamp = null; // Reset timestamp
        $this->activeSlOrderId = null;
        $this->activeTpOrderId = null;
        $this->currentPositionDetails = null;
        $this->isPlacingOrManagingOrder = false;
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
            return;
        }

        // Basic check if AI parameters are even set (values themselves will be validated before sending to exchange API)
        if (!isset($this->aiSuggestedEntryPrice, $this->aiSuggestedSlPrice, $this->aiSuggestedTpPrice, $this->aiSuggestedQuantity, $this->aiSuggestedSide, $this->aiSuggestedLeverage)) {
            $this->logger->warning('AI parameters for opening position are not fully set. Waiting for AI update.');
            return;
        }
        
        $this->isPlacingOrManagingOrder = true;
        $this->logger->info('Attempting to open new position based on AI (values will be formatted before sending).', [
            'side' => $this->aiSuggestedSide, 'quantity_raw' => $this->aiSuggestedQuantity,
            'entry_raw' => $this->aiSuggestedEntryPrice, 'sl_raw' => $this->aiSuggestedSlPrice, 'tp_raw' => $this->aiSuggestedTpPrice,
            'leverage' => $this->aiSuggestedLeverage
        ]);

        $this->setLeverage($this->tradingSymbol, $this->aiSuggestedLeverage)
            ->then(function () {
                return $this->placeFuturesLimitOrder(
                    $this->tradingSymbol,
                    $this->aiSuggestedSide,
                    $this->aiSuggestedQuantity,
                    $this->aiSuggestedEntryPrice
                );
            })
            ->then(function ($orderData) {
                $this->activeEntryOrderId = (string)$orderData['orderId'];
                $this->activeEntryOrderTimestamp = time(); // Set timestamp here
                $this->logger->info("Entry limit order placed successfully.", [
                    'orderId' => $this->activeEntryOrderId,
                    'clientOrderId' => $orderData['clientOrderId'],
                    'placement_timestamp' => date('Y-m-d H:i:s', $this->activeEntryOrderTimestamp)
                ]);
                $this->isPlacingOrManagingOrder = false;
            })
            ->catch(function (\Throwable $e) {
                $this->logger->error('Failed to open position.', [
                    'exception_class' => get_class($e), 'exception' => $e->getMessage(),
                    'ai_params_raw' => [
                        'side' => $this->aiSuggestedSide ?? 'N/A', 'qty' => $this->aiSuggestedQuantity ?? 'N/A',
                        'entry' => $this->aiSuggestedEntryPrice ?? 'N/A'
                    ]
                ]);
                $this->isPlacingOrManagingOrder = false;
                $this->resetTradeState();
            });
    }
    private function attemptClosePositionByAI(): void
    {
        if (!$this->currentPositionDetails || $this->isPlacingOrManagingOrder) {
            $this->logger->debug('Skipping AI close: No position or operation in progress.');
            return;
        }
        $this->isPlacingOrManagingOrder = true;
        $this->logger->info("AI requests to close current position for {$this->tradingSymbol} at market.");

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
        $this->activeSlOrderId = null;
        $this->activeTpOrderId = null;


        \React\Promise\all($cancellationPromises)->then(function() {
            $closeSide = $this->currentPositionDetails['side'] === 'LONG' ? 'SELL' : 'BUY';
            $quantityToClose = $this->currentPositionDetails['quantity'];

            return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $quantityToClose, true);
        })->then(function($closeOrderData) {
            $this->logger->info("Market order placed by AI to close position.", [
                'orderId' => $closeOrderData['orderId'],
                'status' => $closeOrderData['status']
            ]);
        })->catch(function(\Throwable $e) {
            $this->logger->error("Error during AI-driven position close.", ['exception' => $e->getMessage()]);
        })->finally(function() {
            $this->isPlacingOrManagingOrder = false;
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
                if (isset($data['code']) && (int)$data['code'] < 0 && (int)$data['code'] !== -2011 ) {
                    $this->logger->error('Binance Futures API Error', $logCtx + ['api_code' => $data['code'], 'api_msg' => $data['msg'] ?? 'N/A']);
                    throw new \RuntimeException("Binance Futures API error ({$data['code']}): " . ($data['msg'] ?? 'Unknown error') . " for " . $url);
                }
                if ($statusCode >= 400 && !(isset($data['code']) && (int)$data['code'] < 0) && !(isset($data['code']) && (int)$data['code'] == -2011) ) {
                     $this->logger->error('HTTP Error Status without specific handled API error', $logCtx + ['body_preview' => substr($body, 0, 200)]);
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


    private function getPricePrecisionFormat(): string { return '%.1f'; }
    private function getQuantityPrecisionFormat(): string { return '%.3f'; }


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
        $params = [
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'limit' => $limit
        ];
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
                        return [
                            'openTime' => (int)$kline[0], 
                            'open' => (string)$kline[1],
                            'high' => (string)$kline[2],
                            'low' => (string)$kline[3],
                            'close' => (string)$kline[4],
                            'volume' => (string)$kline[5],
                        ];
                    }
                    return null;
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

                $this->logger->info("No active position found for {$this->tradingSymbol} via getPositionInformation.");
                return null;
            });
    }


    private function setLeverage(string $symbol, int $leverage): PromiseInterface {
        $endpoint = '/fapi/v1/leverage';
        $params = ['symbol' => strtoupper($symbol), 'leverage' => $leverage];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
             ->then(function ($data) use ($symbol, $leverage) {
                $this->logger->info("Leverage set for {$symbol}", ['leverage' => $data['leverage'] ?? $leverage, 'response' => $data]);
                return $data;
            });
    }

    private function placeFuturesLimitOrder(string $symbol, string $side, float $quantity, float $price, ?string $timeInForce = 'GTC', ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($price <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid price/quantity for limit order. P:{$price} Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'LIMIT',
            'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity),
            'price' => sprintf($this->getPricePrecisionFormat(), $price),
            'timeInForce' => $timeInForce,
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }
    private function placeFuturesMarketOrder(string $symbol, string $side, float $quantity, ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid quantity for market order. Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'MARKET',
            'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity),
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }


    private function placeFuturesStopMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice, bool $reduceOnly = true, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for STOP_MARKET. SP:{$stopPrice} Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'STOP_MARKET',
            'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity), 
            'stopPrice' => sprintf($this->getPricePrecisionFormat(), $stopPrice), 
            'reduceOnly' => $reduceOnly ? 'true' : 'false',
            'workingType' => 'MARK_PRICE'
        ];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesTakeProfitMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice, bool $reduceOnly = true, ?string $positionSide = 'BOTH'): PromiseInterface {
        $endpoint = '/fapi/v1/order';
         if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for TAKE_PROFIT_MARKET. SP:{$stopPrice} Q:{$quantity}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'TAKE_PROFIT_MARKET',
            'quantity' => sprintf($this->getQuantityPrecisionFormat(), $quantity), 
            'stopPrice' => sprintf($this->getPricePrecisionFormat(), $stopPrice), 
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
        $this->getFuturesOrderStatus($this->tradingSymbol, $orderId)
        ->then(function (array $orderStatusData) use ($orderId, $orderTypeLabel) {
            $this->logger->debug("Checked {$orderTypeLabel} order status (fallback)", [
                'orderId' => $orderId, 'status' => $orderStatusData['status'] ?? 'UNKNOWN'
            ]);
             if ($orderTypeLabel === 'ENTRY' && $this->activeEntryOrderId === $orderId) {
                 $status = $orderStatusData['status'] ?? null;
                 if (in_array($status, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                     $this->logger->warning("Active entry order {$orderId} found as {$status} by fallback check. Resetting.");
                     $this->addOrderToLog($orderId, $status, $orderStatusData['side'] ?? 'N/A', $this->tradingSymbol, (float)($orderStatusData['price'] ?? 0), (float)($orderStatusData['origQty'] ?? 0), $this->marginAsset, time(), (float)($orderStatusData['realizedPnl'] ?? 0));
                     $this->resetTradeState();
                 } elseif ($status === 'FILLED') {
                     $this->logger->info("Active entry order {$orderId} found as FILLED by fallback check. WS should handle, but logging.");
                 }
             }
        })
        ->catch(function (\Throwable $e) use ($orderId, $orderTypeLabel) {
            if (str_contains($e->getMessage(), '-2013') || stripos($e->getMessage(), 'Order does not exist') !== false) {
                $this->logger->info("{$orderTypeLabel} order {$orderId} not found on check (likely resolved or never existed).", ['err_preview' => substr($e->getMessage(),0,100)]);
                 if ($orderTypeLabel === 'ENTRY' && $this->activeEntryOrderId === $orderId) {
                    $this->logger->warning("Active entry order {$orderId} disappeared from exchange. Resetting state.");
                    $this->resetTradeState();
                 }
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
                $this->logger->info("Cancel order request processed for {$orderId}", ['response_status' => $data['status'] ?? 'N/A']);
                return $data;
            });
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


    // User Data Stream ListenKey Management
    private function startUserDataStream(): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        $signedRequestData = $this->createSignedRequestData($endpoint, [], 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function keepAliveUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
        $signedRequestData = $this->createSignedRequestData($endpoint, ['listenKey' => $listenKey], 'PUT');
        return $this->makeAsyncApiRequest('PUT', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function closeUserDataStream(string $listenKey): PromiseInterface {
        $endpoint = '/fapi/v1/listenKey';
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
                if ($e->getCode() !== 429) {
                    $this->logger->error('AI update cycle failed.', ['exception_class' => get_class($e), 'exception' => $e->getMessage()]);
                }
            });
    }

    private function collectDataForAI(): PromiseInterface
    {
        $this->logger->debug("Collecting data for AI...");
        $historicalKlineLimit = 100; 

        return \React\Promise\all([
            'balance' => $this->getFuturesAccountBalance(),
            'position' => $this->getPositionInformation($this->tradingSymbol),
            'trade_history' => $this->getFuturesTradeHistory($this->tradingSymbol, 10),
            'historical_klines' => $this->getHistoricalKlines($this->tradingSymbol, $this->klineInterval, $historicalKlineLimit) 
        ])->then(function (array $results) {
            $currentBalanceInfo = $results['balance'][$this->marginAsset] ?? ['availableBalance' => 0.0];
            $currentPositionRaw = $results['position']; 

            $activeEntryOrderDetails = null;
            if($this->activeEntryOrderId && $this->activeEntryOrderTimestamp) {
                $activeEntryOrderDetails = [
                    'orderId' => $this->activeEntryOrderId,
                    'placedAt' => date('Y-m-d H:i:s', $this->activeEntryOrderTimestamp),
                    'side' => $this->aiSuggestedSide, 
                    'price' => $this->aiSuggestedEntryPrice,
                    'quantity' => $this->aiSuggestedQuantity,
                    'seconds_pending' => time() - $this->activeEntryOrderTimestamp,
                    'timeout_in' => $this->pendingEntryOrderCancelTimeoutSeconds - (time() - $this->activeEntryOrderTimestamp)
                ];
            }


            $dataForAI = [
                'current_market_price' => $this->lastClosedKlinePrice,
                'current_margin_asset_balance' => $currentBalanceInfo['availableBalance'],
                'margin_asset' => $this->marginAsset,
                'current_position_raw' => $currentPositionRaw, 
                'active_pending_entry_order' => $activeEntryOrderDetails, 
                'historical_klines' => $results['historical_klines'] ?? [], 
                'recent_trade_outcomes' => $this->recentOrderLogs,
                'recent_account_trades' => array_map(function($trade){
                    return ['price' => $trade['price'], 'qty' => $trade['qty'], 'commission' => $trade['commission'], 'realizedPnl' => $trade['realizedPnl'], 'side' => $trade['side'], 'time' => date('Y-m-d H:i:s', (int)($trade['time']/1000))];
                }, (is_array($results['trade_history']) ? $results['trade_history'] : [])),
                'current_bot_parameters' => [
                    'tradingSymbol' => $this->tradingSymbol,
                    'klineInterval' => $this->klineInterval,
                    'defaultLeverage' => $this->defaultLeverage,
                    'amountPercentageBase' => $this->amountPercentage,
                    'aiUpdateIntervalSeconds' => $this->aiUpdateIntervalSeconds,
                    'pendingEntryOrderTimeoutSeconds' => $this->pendingEntryOrderCancelTimeoutSeconds,
                ],
                 'trade_logic_summary' => "Bot trades {$this->tradingSymbol} futures using {$this->klineInterval} klines. AI provides leverage, entry price, quantity, SL price, and TP price. Bot places LIMIT entry. On fill, STOP_MARKET SL and TAKE_PROFIT_MARKET TP are placed. AI can also suggest closing an open position. Pending entry orders are automatically cancelled if not filled within {$this->pendingEntryOrderCancelTimeoutSeconds} seconds.",
            ];
            $this->logger->debug("Data collected for AI", ['market_price' => $dataForAI['current_market_price'], 'balance' => $dataForAI['current_margin_asset_balance'], 'position_raw_exists' => !is_null($dataForAI['current_position_raw']), 'pending_entry_exists' => !is_null($activeEntryOrderDetails), 'kline_count' => count($dataForAI['historical_klines'])]);
            return $dataForAI;
        })->catch(function (\Throwable $e) {
            $this->logger->error("Failed to collect data for AI.", ['exception' => $e->getMessage()]);
            throw $e;
        });
    }

    private function constructAIPrompt(array $dataForAI, bool $isEmergency): string
    {
        $promptText = "You are an AI trading assistant for Binance USDM Futures. Your goal is to optimize trading parameters to maximize profit while managing risk for the {$this->tradingSymbol} contract ({$this->klineInterval} interval).\n\n";
        if ($isEmergency) {
            $promptText .= "EMERGENCY SITUATION (e.g., margin call, rapid loss). Provide conservative actions or instructions to neutralize risk.\n\n";
        }
        $promptText .= "Bot's Current State & Data:\n";
        $dataForPrompt = $dataForAI; // Create a copy to modify for prompt length
        if (isset($dataForPrompt['historical_klines']) && count($dataForPrompt['historical_klines']) > 50) { // Limit klines in prompt to last 50
             $dataForPrompt['historical_klines'] = array_slice($dataForPrompt['historical_klines'], -50);
             $promptText .= "(Showing last 50 historical klines out of " . count($dataForAI['historical_klines']) . " provided to you)\n";
        }
        // Ensure JSON is valid even if data contains non-UTF8 characters
        $promptText .= json_encode($dataForPrompt, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE) . "\n\n";

        $promptText .= "Historical Klines Format Example:\n";
        $promptText .= "[{'openTime': 1672531200000, 'open': '20000.0', 'high': '20100.0', 'low': '19900.0', 'close': '20050.0', 'volume': '100.123'}, ...]\n\n";
        $promptText .= "Active Pending Entry Order Format (if present in data):\n";
        $promptText .= "{'orderId': '...', 'placedAt': '...', 'side': '...', 'price': ..., 'quantity': ..., 'seconds_pending': ..., 'timeout_in': ...}\n\n";

        $promptText .= "Your Task:\nAnalyze the provided data. Decide the next action. Output a single JSON object string.\n";
        $promptText .= "If 'active_pending_entry_order' exists in the data, the bot is waiting for that order to fill or timeout (after {$this->pendingEntryOrderCancelTimeoutSeconds} seconds). In this case, you should generally suggest 'HOLD_POSITION'. Do NOT suggest 'OPEN_POSITION' if 'active_pending_entry_order' is present.\n\n";

        $promptText .= "Possible Actions & JSON Output Structure:\n";
        $promptText .= "1. Open a New Position (ONLY if 'active_pending_entry_order' is null AND 'current_position_raw' indicates no open position OR 'current_position_raw' is null):\n";
        $promptText .= "   {\"action\": \"OPEN_POSITION\", \"leverage\": <int_1_to_125>, \"side\": \"BUY\"_or_\"SELL\", \"entryPrice\": <float_target_entry_price_1_decimal_USDT>, \"quantity\": <float_positive_trade_quantity_3_decimals_BTC>, \"stopLossPrice\": <float_sl_price_1_decimal_USDT>, \"takeProfitPrice\": <float_tp_price_1_decimal_USDT>}\n";
        $promptText .= "   - For {$this->tradingSymbol}: 'quantity' must be a positive float with 3 decimal places and a multiple of 0.001 (e.g., 0.001, 0.012, 0.100).\n";
        $promptText .= "   - For {$this->tradingSymbol}: 'entryPrice', 'stopLossPrice', 'takeProfitPrice' must be positive floats with 1 decimal place and a multiple of 0.1 (e.g., 60000.1, 60123.0).\n";
        $promptText .= "   - Ensure prices are logical: For BUY, SL < Entry < TP. For SELL, SL > Entry > TP.\n\n";

        $promptText .= "2. Close Current Position (if 'current_position_raw' indicates an open position and your analysis suggests closing it now, e.g., due to market reversal or target met early):\n";
        $promptText .= "   {\"action\": \"CLOSE_POSITION\", \"reason\": \"<brief_reason_for_early_closure>\"}\n\n";

        $promptText .= "3. Hold Current Position/State (Use this if: \n";
        $promptText .= "   a) 'current_position_raw' indicates an open position and your analysis suggests maintaining it (SL/TP are still valid).\n";
        $promptText .= "   b) 'active_pending_entry_order' is present (bot is waiting for it to resolve).\n";
        $promptText .= "   c) Market conditions are unclear but not warranting an immediate close if a position is open.):\n";
        $promptText .= "   {\"action\": \"HOLD_POSITION\"}\n\n";

        $promptText .= "4. Do Nothing (ONLY if no current position, no active pending entry order, and current market conditions are not suitable for a new trade based on your analysis):\n";
        $promptText .= "   {\"action\": \"DO_NOTHING\"}\n\n";

        $promptText .= "Key Considerations & Instructions for {$this->tradingSymbol}:\n";
        $promptText .= "- TESTNET MODE: Actively look for trading opportunities. If no position is open AND no entry order is pending, and you see a valid setup, suggest 'OPEN_POSITION'. Avoid 'DO_NOTHING' unless absolutely necessary.\n";
        $promptText .= "- LEVERAGE: Choose based on risk and market volatility observed in klines. Default is {$this->defaultLeverage}x.\n";
        $promptText .= "- QUANTITY PRECISION: Must be 3 decimal places (e.g., 0.001, 0.025, 0.150). Must be a multiple of 0.001.\n";
        $promptText .= "- PRICE PRECISION: All prices (entry, SL, TP) must be 1 decimal place (e.g., 67012.3, 68000.0). Must be a multiple of 0.1.\n";
        $promptText .= "- RISK MANAGEMENT (Testnet Target): For an 'OPEN_POSITION' suggestion, aim for a potential loss of approximately 100 USD if the Stop Loss is hit. The formula for PnL on a futures contract is roughly: `(exitPrice - entryPrice) * quantity` for LONG, or `(entryPrice - exitPrice) * quantity` for SHORT. For SL, this means `abs(entryPrice - stopLossPrice) * quantity` should be around `100 / leverage` (if you consider initial margin impact) or simply `100` if thinking about raw PnL before leverage. Given the available margin `{$dataForAI['current_margin_asset_balance']}`, calculate a quantity and SL distance that align with this. Example: if leverage is 10x, a $100 USD loss is a $10 change in underlying value for 1 unit. If quantity is 0.01 BTC, then (entry - SL) should be $10000 to risk $100. Adjust quantity and SL distance accordingly.\n";
        $promptText .= "- LOGICAL SL/TP: Stop Loss must protect against adverse movement, Take Profit must aim for a reasonable gain.\n";
        $promptText .= "- DATA ANALYSIS: Focus on `historical_klines`, `current_market_price`, `current_position_raw`, `active_pending_entry_order`, and `recent_trade_outcomes`.\n\n";

        $promptText .= "Example for opening a LONG {$this->tradingSymbol} position:\n";
        // Adjusted example to better reflect risk calculation: (entry - SL) * qty approx = 100 (if leverage is 1, for simplicity of example)
        // If entry = 60000, qty = 0.01, to risk $100, (60000 - SL) * 0.01 = 100 => 60000 - SL = 10000 => SL = 50000
        // This example below is still simplified for prompt brevity and might not be perfect for $100 risk with given numbers
        $exampleEntry = $dataForAI['current_market_price'] * 0.998 ?? 60000.0;
        $exampleQty = 0.010; // Let's use a fixed qty for example clarity for AI
        $exampleSlDistance = 100 / $exampleQty; // Simplified: This is the price diff to lose 100 USD with this qty
        $exampleSl = $exampleEntry - $exampleSlDistance;
        $exampleTp = $exampleEntry + ($exampleSlDistance * 1.5); // Example 1:1.5 R:R

        $promptText .= "{\"action\": \"OPEN_POSITION\", \"leverage\": 10, \"side\": \"BUY\", \"entryPrice\": ".sprintf('%.1f', $exampleEntry).", \"quantity\": ".$exampleQty.", \"stopLossPrice\": ".sprintf('%.1f', $exampleSl).", \"takeProfitPrice\": ".sprintf('%.1f', $exampleTp)."}\n\n";

        $promptText .= "Provide ONLY the JSON object as your response, without any surrounding text or markdown formatting (e.g., no \`\`\`json ... \`\`\`).";

        return json_encode(['contents' => [['parts' => [['text' => $promptText]]]], 'generationConfig' => ['temperature' => 0.7]]);
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
                        $this->logger->warning('Gemini API rate limit hit (429 Too Many Requests). Will retry later via periodic AI update.');
                        throw new \RuntimeException("HTTP status code 429 (Too Many Requests)", 429);
                    }
                    throw new \RuntimeException("Gemini API HTTP error: " . $response->getStatusCode() . " Body: " . substr($body, 0, 500));
                }
                return $body;
            },
            function (\Throwable $e) {
                if ($e->getCode() !== 429) {
                    $this->logger->error('Gemini AI request failed (Network/Client Error)', ['exception' => $e->getMessage()]);
                }
                throw $e;
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

            $aiTextResponse = $responseDecoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$aiTextResponse) {
                 throw new \InvalidArgumentException("Could not extract text from AI response. Full response: " . substr($rawResponse,0,500));
            }

            $paramsJson = trim($aiTextResponse);
            if (str_starts_with($paramsJson, '```json')) $paramsJson = substr($paramsJson, 7);
            if (str_starts_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 3);
            if (str_ends_with($paramsJson, '```')) $paramsJson = substr($paramsJson, 0, -3);
            $paramsJson = trim($paramsJson);

            $aiDecision = json_decode($paramsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Failed to decode JSON parameters from AI's text response.", [
                    'ai_text_response_preview' => substr($aiTextResponse, 0, 200), 'json_error' => json_last_error_msg()
                ]);
                throw new \InvalidArgumentException("Failed to decode JSON parameters from AI's text: " . json_last_error_msg() . " - Input: " . substr($aiTextResponse,0,100));
            }
            $this->executeAIDecision($aiDecision);

        } catch (\Throwable $e) {
            $this->logger->error('Error processing AI response or executing decision.', [
                'exception_class' => get_class($e), 'exception' => $e->getMessage(), 'raw_response_preview' => substr($rawResponse, 0, 500)
            ]);
        }
    }

    private function executeAIDecision(array $decision): void
    {
        $action = strtoupper($decision['action'] ?? 'DO_NOTHING');
        $this->logger->info("AI Decision Received", ['action' => $action, 'details' => $decision]);

        switch ($action) {
            case 'OPEN_POSITION':
                if ($this->currentPositionDetails || $this->activeEntryOrderId) {
                     $this->logger->warning("AI wants to OPEN_POSITION, but a position/entry order already exists. Holding.", [
                         'current_pos' => $this->currentPositionDetails, 'active_entry' => $this->activeEntryOrderId
                     ]);
                     break; 
                }
                $this->aiSuggestedLeverage = (int)($decision['leverage'] ?? $this->defaultLeverage);
                $this->aiSuggestedSide = strtoupper($decision['side'] ?? '');
                $this->aiSuggestedEntryPrice = (float)($decision['entryPrice'] ?? 0);
                $this->aiSuggestedQuantity = (float)($decision['quantity'] ?? 0);
                $this->aiSuggestedSlPrice = (float)($decision['stopLossPrice'] ?? 0);
                $this->aiSuggestedTpPrice = (float)($decision['takeProfitPrice'] ?? 0);

                $proceedWithOpen = true;

                // Log validation checks but do not break or modify values here
                if (!in_array($this->aiSuggestedSide, ['BUY', 'SELL']) || $this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedQuantity <= 0 || $this->aiSuggestedSlPrice <= 0 || $this->aiSuggestedTpPrice <= 0) {
                    $this->logger->error("AI OPEN_POSITION: Parameters appear invalid (zero/negative).", $decision);
                    // $proceedWithOpen = false; // Removed to only log
                }
                if (fmod(round($this->aiSuggestedQuantity,3), 0.001) != 0) { 
                    $this->logger->warning("AI OPEN_POSITION: Suggested quantity {$this->aiSuggestedQuantity} is not a multiple of 0.001.", $decision);
                    // No modification of $this->aiSuggestedQuantity
                }
                if (fmod(round($this->aiSuggestedEntryPrice,1), 0.1) != 0 || fmod(round($this->aiSuggestedSlPrice,1), 0.1) != 0 || fmod(round($this->aiSuggestedTpPrice,1), 0.1) != 0) {
                     $this->logger->warning("AI OPEN_POSITION: Suggested prices do not meet 0.1 step size requirement.", $decision);
                     // $proceedWithOpen = false; // Removed to only log
                }

                if ($this->aiSuggestedSide === 'BUY' && ($this->aiSuggestedSlPrice >= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice <= $this->aiSuggestedEntryPrice)) {
                     $this->logger->error("AI OPEN_POSITION (BUY): Illogical SL/TP vs Entry.", $decision);
                     // $proceedWithOpen = false; // Removed to only log
                }
                 if ($this->aiSuggestedSide === 'SELL' && ($this->aiSuggestedSlPrice <= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice >= $this->aiSuggestedEntryPrice)) {
                     $this->logger->error("AI OPEN_POSITION (SELL): Illogical SL/TP vs Entry.", $decision);
                     // $proceedWithOpen = false; // Removed to only log
                }
                
                // Always attempt to open if AI suggests it, after logging any validation concerns.
                // The `attemptOpenPosition` itself has a check for positive quantity/price before calling the API method.
                // The API method `placeFuturesLimitOrder` has its own strict checks and sprintf formatting.
                if ($this->aiSuggestedQuantity <= 0 || $this->aiSuggestedEntryPrice <=0) { // Minimal final check before attempting
                    $this->logger->error("AI OPEN_POSITION: Final check - quantity or entry price is zero/negative. Cannot proceed.", [
                        'qty' => $this->aiSuggestedQuantity, 'entry' => $this->aiSuggestedEntryPrice
                    ]);
                } else {
                    $this->attemptOpenPosition();
                }
                break;

            case 'CLOSE_POSITION':
                if (!$this->currentPositionDetails) {
                    $this->logger->info("AI wants to CLOSE_POSITION, but no position exists.");
                    break;
                }
                $this->attemptClosePositionByAI();
                break;

            case 'HOLD_POSITION':
                $this->logger->info("AI recommends HOLD_POSITION. No action taken.");
                break;

            case 'DO_NOTHING':
                $this->logger->info("AI recommends DO_NOTHING. No action taken.");
                break;

            default:
                $this->logger->warning("Unknown AI action received.", ['action' => $action]);
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
    amountPercentage: 10.0,
    orderCheckIntervalSeconds: 10, 
    maxScriptRuntimeSeconds: 86400,
    aiUpdateIntervalSeconds: 20, 
    useTestnet: $useTestnet,
    pendingEntryOrderCancelTimeoutSeconds: 30 
);

$bot->run();

?>