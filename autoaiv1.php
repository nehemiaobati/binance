<?php // autov3.php

declare(strict_types=1); // Enable strict types for better code quality

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as SocketConnector;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// --- Configuration Loading ---
$apiKey = getenv('BINANCE_API_KEY') ?: 'NqgRju8D4Hqexr9ZBsbO0Ua4F2MiPzLshm7pCCBBGkbEgzj7yakorkadbPBX6UQF'; // No insecure fallbacks
$apiSecret = getenv('BINANCE_API_SECRET') ?: 'ue8NGUVZMTTNT8FcgT0bgSuCR6AfoFtEbmxfK7nIjHbePuWNW07CreuNAul5Yjeg'; // No insecure fallbacks
$geminiApiKey = getenv('GEMINI_API_KEY') ?: 'AIzaSyChCv_Ab9Sd0vORDG-VY6rMrhKpMThv_YA'; // Load Gemini Key

if (!$apiKey || !$apiSecret) {
    die("Error: Binance API Key or Secret not configured. Please set BINANCE_API_KEY and BINANCE_API_SECRET environment variables.\n");
}
if (!$geminiApiKey) {
    // Allow running without Gemini for testing basic functions, but log warning
    // In a real scenario, you might want to die() here if AI is essential.
    echo "Warning: GEMINI_API_KEY environment variable not set. AI features will be disabled.\n";
}

// --- Trading Bot Class ---

class AiTradingBot // Renamed class
{
    // --- Constants ---
    private const REST_API_BASE_URL = 'https://api.binance.com';
    private const WS_API_BASE_URL = 'wss://stream.binance.com:9443';
    private const API_RECV_WINDOW = 5000;

    // --- AI Configuration ---
    // WARNING: The API key should NOT be hardcoded here. Loaded from env vars.
    private const GEMINI_MODEL_ID = 'gemini-1.5-flash-latest'; // Or another suitable model like 'gemini-2.5-pro-preview-03-25' from your example
    private const GEMINI_API_ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/{MODEL_ID}:generateContent?key={API_KEY}'; // Correct endpoint structure
    private const AI_UPDATE_INTERVAL_SECONDS = 300; // How often to ask AI (e.g., 5 minutes) - Adjust based on cost/need/rate limits
    private const AI_KLINE_HISTORY_LIMIT = 50;   // How many recent klines to send to AI - Adjust based on model context window & cost
    private const AI_REQUEST_TIMEOUT = 20.0; // Timeout for AI API calls

    // --- Configuration Properties ---
    private string $apiKey;
    private string $apiSecret;
    private ?string $geminiApiKey; // Nullable if not set
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
    private ?WebSocket $wsConnection = null;

    // --- State Properties ---
    private ?float $initialBaseBalance = null;
    private ?float $initialQuoteBalance = null;
    private ?float $referencePrice = null; // This price will be updated by AI
    private ?float $lastKlinePrice = null; // Store the last price received from WS
    private ?string $activeOrderId = null;
    private ?int $orderPlaceTime = null;
    private bool $isPlacingOrder = false;
    private bool $isUpdatingAiPrice = false; // Flag to prevent concurrent AI updates

    public function __construct(
        string $apiKey,
        string $apiSecret,
        ?string $geminiApiKey, // Accept nullable Gemini key
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
        $this->geminiApiKey = $geminiApiKey; // Store potentially null key
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

        // Setup Logger
        $logFormat = "[%datetime%] [%level_name%] %message% %context% %extra%\n";
        $formatter = new LineFormatter($logFormat, 'Y-m-d H:i:s', true, true);
        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('AiTradingBot'); // Updated logger name
        $this->logger->pushHandler($streamHandler);

        $this->logger->info('AiTradingBot instance created.');
        if (!$this->geminiApiKey) {
            $this->logger->warning('Gemini API Key not provided. AI reference price updates will be disabled.');
        }
    }

    /**
     * Runs the trading bot's main logic.
     */
    public function run(): void
    {
        $this->logger->info('Starting initialization...');

        $this->getLatestKlineClosePrice($this->symbol, $this->klineInterval)
            ->then(function (float $initialPrice) {
                $this->referencePrice = $initialPrice; // Set the *first* reference price
                $this->lastKlinePrice = $initialPrice; // Initialize last kline price

                if ($this->referencePrice === null || $this->referencePrice <= 0) {
                    throw new \RuntimeException("Failed to fetch a valid initial reference price.");
                }

                $this->logger->info('Initial price fetched successfully', [
                    'initial_reference_price_' . $this->symbol => $this->referencePrice,
                ]);

                // Fetch initial balances (optional, could be removed if not strictly needed)
                return \React\Promise\all([
                    $this->getSpecificAssetBalance($this->convertBaseAsset),
                    $this->getSpecificAssetBalance($this->convertQuoteAsset),
                ]);
            })
            ->then(
                 function ($balances) {
                     $this->initialBaseBalance = (float)$balances[0];
                     $this->initialQuoteBalance = (float)$balances[1];
                     $this->logger->info('Initial balances fetched', [
                        'initial_' . $this->convertBaseAsset . '_balance' => $this->initialBaseBalance,
                        'initial_' . $this->convertQuoteAsset . '_balance' => $this->initialQuoteBalance,
                     ]);

                     // Initialization complete, start components
                     $this->connectWebSocket();
                     $this->setupTimers();

                     // Perform the first AI update shortly after start if AI is enabled
                     if ($this->geminiApiKey) {
                          $this->loop->addTimer(5, function() { // Wait 5s before first AI call
                              $this->scheduleAiReferencePriceUpdate();
                          });
                     }
                 },
                 function (\Throwable $e) {
                    $this->logger->error('Initialization failed', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    $this->stop();
                 }
            );


        $this->logger->info('Starting event loop...');
        $this->loop->run();
        $this->logger->info('Event loop finished.');
    }

    // ... (stop, connectWebSocket remain largely the same) ...
     /**
     * Stops the event loop and performs cleanup.
     */
    private function stop(): void
    {
        $this->logger->info('Stopping event loop...');
        // Cancel all timers? Loop::cancelTimer() - Requires storing timer objects. Simpler to just stop loop.
        if ($this->wsConnection && method_exists($this->wsConnection, 'close')) {
             $this->logger->debug('Closing WebSocket connection...');
             try {
                $this->wsConnection->close();
             } catch (\Exception $e) {
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
        // Ensure previous connection is closed if reconnecting logic were added
        if ($this->wsConnection) {
            try { $this->wsConnection->close(); } catch (\Exception $_) {}
            $this->wsConnection = null;
        }

        $wsUrl = self::WS_API_BASE_URL . '/ws/' . strtolower($this->symbol) . '@kline_' . $this->klineInterval;
        $this->logger->info('Connecting to WebSocket', ['url' => $wsUrl]);

        $wsConnector = new WsConnector($this->loop);
        $wsConnector($wsUrl)->then(
            function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->logger->info('WebSocket connected successfully.');

                $conn->on('message', function ($msg) {
                    $this->handleWsMessage((string)$msg);
                });

                $conn->on('error', function (\Throwable $e) {
                    $this->logger->error('WebSocket error', ['exception' => $e->getMessage()]);
                    // Consider reconnection logic here
                    $this->wsConnection = null; // Mark connection as dead
                    $this->logger->info('Attempting WebSocket reconnect in 10 seconds...');
                    $this->loop->addTimer(10, [$this, 'connectWebSocket']); // Simple retry
                });

                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->wsConnection = null; // Mark connection as dead
                    // Decide if you want to stop the bot or attempt reconnection
                    if ($this->loop->isRunning()) { // Avoid trying to reconnect if loop stopping
                        $this->logger->info('Attempting WebSocket reconnect in 10 seconds...');
                         $this->loop->addTimer(10, [$this, 'connectWebSocket']); // Simple retry
                    }
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception' => $e->getMessage()]);
                 if ($this->loop->isRunning()) {
                     $this->logger->info('Attempting WebSocket connection again in 15 seconds...');
                     $this->loop->addTimer(15, [$this, 'connectWebSocket']); // Simple retry on initial failure
                 }
            }
        );
    }

    /**
     * Sets up periodic timers.
     */
    private function setupTimers(): void
    {
        // Periodic Order Status Check
        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            if ($this->activeOrderId !== null && !$this->isPlacingOrder) {
                 $this->checkActiveOrderStatus();
            }
        });
        $this->logger->info('Order check timer started', ['interval_seconds' => $this->orderCheckIntervalSeconds]);

        // Periodic AI Reference Price Update (only if key exists)
        if ($this->geminiApiKey) {
            $this->loop->addPeriodicTimer(self::AI_UPDATE_INTERVAL_SECONDS, function () {
                $this->scheduleAiReferencePriceUpdate();
            });
            $this->logger->info('AI reference price update timer started', ['interval_seconds' => self::AI_UPDATE_INTERVAL_SECONDS]);
        } else {
             $this->logger->info('AI reference price update timer skipped (no API key).');
        }

        // Script Termination Timer
        $this->loop->addTimer($this->maxScriptRuntimeSeconds, function () {
            $this->logger->warning('Maximum script runtime reached. Stopping script.', ['max_runtime_seconds' => $this->maxScriptRuntimeSeconds]);
            if ($this->activeOrderId !== null) {
                $this->logger->warning('Attempting to cancel active order before stopping due to max runtime.', ['orderId' => $this->activeOrderId]);
                // Best effort cancellation - might not complete before stop()
                $this->cancelConvertLimitOrder($this->activeOrderId)->always([$this, 'stop']);
            } else {
                 $this->stop();
            }
        });
        $this->logger->info('Max runtime timer started', ['limit_seconds' => $this->maxScriptRuntimeSeconds]);
    }

    /**
     * Handles incoming WebSocket messages.
     */
    private function handleWsMessage(string $msg): void
    {
        // Basic message decoding and validation
        $data = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['e']) || $data['e'] !== 'kline' || !isset($data['k']['x']) || $data['k']['x'] !== true || !isset($data['k']['c'])) {
            // $this->logger->debug('Ignoring invalid/non-closed kline message.'); // Can be noisy
            return;
        }

        $current_price = (float)$data['k']['c'];
        $this->lastKlinePrice = $current_price; // Always store the latest price

        // Trigger checks only if no order is active/placing AND reference price is set
        if ($this->activeOrderId === null && !$this->isPlacingOrder && $this->referencePrice !== null) {
            $trigger_price_up = $this->referencePrice * (1 + $this->triggerMarginPercent / 100);
            $trigger_price_down = $this->referencePrice * (1 - $this->triggerMarginPercent / 100);

            $order_side = null;

            if ($current_price >= $trigger_price_up) {
                $order_side = $this->orderSideOnPriceRise;
                $this->logger->info('Price rise trigger met', [
                    'current_price' => $current_price, 'reference_price' => $this->referencePrice,
                    'trigger_price_up' => $trigger_price_up, 'order_side' => $order_side
                ]);
            } elseif ($current_price <= $trigger_price_down) {
                $order_side = $this->orderSideOnPriceDrop;
                 $this->logger->info('Price drop trigger met', [
                    'current_price' => $current_price, 'reference_price' => $this->referencePrice,
                    'trigger_price_down' => $trigger_price_down, 'order_side' => $order_side
                ]);
            }

            if ($order_side !== null) {
                $this->attemptPlaceOrder($order_side, $current_price);
            }
        }
    }

     // --- AI Update Logic ---

    /**
     * Schedules the process to update the reference price using AI.
     * Uses a flag to prevent concurrent updates.
     */
    private function scheduleAiReferencePriceUpdate(): void
    {
        if ($this->isUpdatingAiPrice) {
            $this->logger->debug('AI reference price update already in progress. Skipping scheduled run.');
            return;
        }
        if (!$this->geminiApiKey) {
             $this->logger->debug('Gemini API key not set. Skipping AI update.');
             return;
        }

        $this->isUpdatingAiPrice = true;
        $this->logger->info('Starting AI reference price update process...');

        $this->fetchHistoricalKlines(self::AI_KLINE_HISTORY_LIMIT)
            ->then(
                function (array $klines) {
                    if (empty($klines)) {
                        throw new \RuntimeException('Fetched historical klines data is empty.');
                    }
                    $formattedKlines = $this->formatKlinesForPrompt($klines);
                    return $this->getAiReferencePriceSuggestion($formattedKlines);
                }
            )
            ->then(
                function (?float $suggestedPrice) {
                    if ($suggestedPrice !== null && $suggestedPrice > 0) {
                        $oldPrice = $this->referencePrice;
                        $this->referencePrice = $suggestedPrice;
                        $this->logger->info('Successfully updated reference price based on AI suggestion.', [
                            'old_reference_price' => $oldPrice,
                            'new_reference_price' => $this->referencePrice,
                        ]);
                    } else {
                        // AI failed to provide a valid price, keep the old one.
                        $this->logger->warning('AI did not provide a valid reference price suggestion. Keeping previous price.', [
                            'current_reference_price' => $this->referencePrice
                        ]);
                    }
                }
            )
            ->catch(
                 function (\Throwable $e) {
                     $this->logger->error('Failed to update reference price from AI.', [
                         'exception' => $e->getMessage(),
                         // 'trace' => $e->getTraceAsString() // Optionally log full trace for debugging
                     ]);
                     // Keep the old reference price on failure
                 }
            )
            ->finally(
                 function () {
                     $this->isUpdatingAiPrice = false; // Release the lock
                     $this->logger->debug('AI reference price update process finished.');
                 }
             );
    }

    /**
     * Formats Kline data into a string suitable for the AI prompt.
     */
    private function formatKlinesForPrompt(array $klines): string
    {
        // Format: Timestamp(ms),Open,High,Low,Close,Volume\n...
        $output = "Timestamp(ms),Open,High,Low,Close,Volume\n";
        foreach ($klines as $kline) {
            // Indices: 0: Open time, 1: Open, 2: High, 3: Low, 4: Close, 5: Volume
             if (count($kline) >= 6) {
                 $output .= sprintf("%s,%s,%s,%s,%s,%s\n",
                    $kline[0], $kline[1], $kline[2], $kline[3], $kline[4], $kline[5]
                 );
            }
        }
        return trim($output);
    }

    /**
     * Fetches historical Kline data from Binance.
     */
    private function fetchHistoricalKlines(int $limit): PromiseInterface
    {
        $endpoint = '/api/v3/klines';
        $params = [
            'symbol' => strtoupper($this->symbol),
            'interval' => $this->klineInterval,
            'limit' => max(1, min(1000, $limit)), // Ensure limit is within Binance bounds
        ];
        $url = self::REST_API_BASE_URL . $endpoint . '?' . http_build_query($params);
        $logContext = ['symbol' => $this->symbol, 'interval' => $this->klineInterval, 'limit' => $params['limit']];

        $this->logger->debug('Fetching historical klines for AI', $logContext);

        // Public endpoint, no signature needed
        return $this->makeAsyncApiRequest('GET', $url, [])
            ->then(function ($data) use ($logContext) {
                if (!is_array($data)) {
                     $this->logger->error('Invalid response format for klines: not an array', $logContext + ['response_type' => gettype($data)]);
                     throw new \RuntimeException("Invalid response format for klines: not an array");
                }
                 // Optional: Add more validation if needed (e.g., check if sub-arrays exist)
                 $this->logger->debug('Successfully fetched historical klines', $logContext + ['count' => count($data)]);
                 return $data; // Return array of kline arrays
            })
            ->catch(function (\Throwable $e) use ($logContext) {
                 // Error already logged by makeAsyncApiRequest
                 $this->logger->error('Failure in fetchHistoricalKlines chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e; // Re-throw
             });
    }

    /**
     * Calls the Gemini API to get a reference price suggestion.
     */
    private function getAiReferencePriceSuggestion(string $klineDataFormatted): PromiseInterface
    {
        if (!$this->geminiApiKey) {
            return \React\Promise\resolve(null); // Resolve immediately if no key
        }

        $apiUrl = str_replace(['{MODEL_ID}', '{API_KEY}'], [self::GEMINI_MODEL_ID, $this->geminiApiKey], self::GEMINI_API_ENDPOINT_TEMPLATE);

        // --- Prompt Engineering ---
        // This is critical and likely needs tuning.
        $prompt = <<<PROMPT
Analyze the following recent Kline data for {$this->symbol} ({$this->klineInterval}). Provide a suggested reference price for short-term trading based on recent trends and potential support/resistance levels. The bot uses this reference price to set buy/sell triggers slightly below/above it.

Recent Kline Data (Timestamp(ms), Open, High, Low, Close, Volume):
{$klineDataFormatted}

Based ONLY on the data provided, what is a reasonable short-term reference price? Respond with ONLY the floating-point number representing the suggested price (e.g., 65432.10). Do not include currency symbols, explanations, or any other text.
PROMPT;

        $requestBody = json_encode([
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]]
                ]
            ],
            'generationConfig' => [
                // Optional: Add config like temperature, topP, maxOutputTokens if needed
                // 'temperature' => 0.7,
                 "responseMimeType" => "text/plain", // Request plain text
            ],
            // Optional: Safety Settings
            // 'safetySettings' => [ ... ]
        ]);

        if (json_last_error() !== JSON_ERROR_NONE) {
             $this->logger->error('Failed to encode JSON request for AI API', ['error' => json_last_error_msg()]);
             return \React\Promise\reject(new \RuntimeException('Failed to encode AI request body.'));
        }

        $headers = ['Content-Type' => 'application/json'];
        $logContext = ['model' => self::GEMINI_MODEL_ID, 'symbol' => $this->symbol];

        $this->logger->debug('Calling Gemini API for reference price suggestion...', $logContext);

        // Use a dedicated request method for AI API for clarity
        return $this->makeAiApiRequest('POST', $apiUrl, $headers, $requestBody)
            ->then(function (?string $aiResponseText) use ($logContext) {
                if ($aiResponseText === null || trim($aiResponseText) === '') {
                     $this->logger->warning('AI API returned empty response text.', $logContext);
                     return null;
                }

                 // Validate if the response is a number
                 $trimmedResponse = trim($aiResponseText);
                 if (is_numeric($trimmedResponse)) {
                     $suggestedPrice = (float)$trimmedResponse;
                     if ($suggestedPrice > 0) {
                        $this->logger->debug('AI suggested reference price', $logContext + ['suggested_price' => $suggestedPrice]);
                        return $suggestedPrice;
                     } else {
                         $this->logger->warning('AI returned non-positive numeric value.', $logContext + ['response' => $trimmedResponse]);
                         return null;
                     }
                 } else {
                    $this->logger->warning('AI response was not a valid number.', $logContext + ['response_preview' => substr($trimmedResponse, 0, 100)]);
                    return null; // Not a valid number
                 }
            })
            ->catch(function (\Throwable $e) use ($logContext) {
                 // Error logged by makeAiApiRequest
                 $this->logger->error('Failure in getAiReferencePriceSuggestion chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e; // Re-throw
             });
    }

     /**
     * Makes an asynchronous HTTP request specifically for the AI API.
     */
    private function makeAiApiRequest(string $method, string $url, array $headers = [], ?string $body = null): PromiseInterface
    {
        $options = [
            'follow_redirects' => false,
            'timeout' => self::AI_REQUEST_TIMEOUT // Use specific timeout for AI
        ];
        $logContext = ['method' => $method, 'url_host' => parse_url($url, PHP_URL_HOST)]; // Don't log full AI URL with key

        return $this->browser->request($method, $url, $headers, $body ?? '', $options)->then(
            function (ResponseInterface $response) use ($logContext) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $logContext['status'] = $statusCode;

                if ($statusCode >= 400) {
                    $this->logger->error('AI API HTTP Error Status Code Received', $logContext + ['response_body_preview' => substr($body, 0, 200)]);
                    // Try parsing body for Gemini error details
                    $errorData = json_decode($body, true);
                    $errorMessage = "AI API HTTP Error {$statusCode}";
                    if (isset($errorData['error']['message'])) {
                        $errorMessage .= ": " . $errorData['error']['message'];
                    }
                    throw new \RuntimeException($errorMessage);
                }

                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                     $this->logger->error('Failed to decode JSON response from AI API', $logContext + [
                        'json_error' => json_last_error_msg(),
                        'response_body_preview' => substr($body, 0, 200)
                    ]);
                    throw new \RuntimeException("Failed to decode AI API JSON response: " . json_last_error_msg());
                }

                 // Check for Gemini specific errors within the JSON structure (even on 200 OK)
                 if (isset($data['error'])) {
                     $errorMessage = "AI API returned an error: " . ($data['error']['message'] ?? 'Unknown Error');
                     $logContext['ai_error_code'] = $data['error']['code'] ?? 'N/A';
                     $logContext['ai_error_status'] = $data['error']['status'] ?? 'N/A';
                     $this->logger->error('AI API Error Response Payload', $logContext);
                     throw new \RuntimeException($errorMessage);
                 }

                // Extract the text response - Structure: candidates[0].content.parts[0].text
                // Add safety checks for the structure
                 if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    // $this->logger->debug('AI API Request Success', $logContext); // Optional debug log
                    return (string)$data['candidates'][0]['content']['parts'][0]['text'];
                } else {
                    // Check for finishReason if no text (e.g., SAFETY, RECITATION)
                    $finishReason = $data['candidates'][0]['finishReason'] ?? 'UNKNOWN_REASON';
                    if($finishReason !== 'STOP'){
                         $this->logger->warning('AI response finished with non-STOP reason, text might be missing.', $logContext + ['finishReason' => $finishReason, 'response_preview' => substr($body, 0, 200)]);
                         // Depending on the reason, you might want to throw or return null
                         return null; // Treat as no valid response if not STOP and no text
                    }
                     // If finishReason is STOP but text is missing, it's unexpected.
                    $this->logger->error('AI API response structure unexpected (missing text part)', $logContext + ['response_preview' => substr($body, 0, 200)]);
                    throw new \RuntimeException('Unexpected AI API response structure.');
                }
            },
            function (\Throwable $e) use ($logContext) {
                $this->logger->error('AI API Request Failed (Network/Timeout/etc.)', $logContext + [
                    'exception_type' => get_class($e),
                    'exception_message' => $e->getMessage()
                ]);
                throw new \RuntimeException("AI API Request failed: " . $e->getMessage(), 0, $e);
            }
        );
    }


    // --- Binance Order/Balance Methods (Largely unchanged from autov2.php) ---

     /**
     * Attempts to place a convert limit order after a trigger is met.
     */
    private function attemptPlaceOrder(string $orderSide, float $currentPrice): void
    {
        if ($this->isPlacingOrder) {
            $this->logger->debug('Attempted to place order while another placement was in progress.');
            return;
        }
         if ($this->activeOrderId !== null) {
             $this->logger->debug('Attempted to place order while another order is already active.');
            return;
         }
        $this->isPlacingOrder = true; // Set flag
        $this->logger->info('Order trigger met. Initiating order placement process...', ['side' => $orderSide, 'trigger_price' => $currentPrice, 'reference_price' => $this->referencePrice]);

        $relevant_asset = ($orderSide === 'BUY') ? $this->convertQuoteAsset : $this->convertBaseAsset;

        $this->getSpecificAssetBalance($relevant_asset)
        ->then(function (float $current_relevant_balance) use ($orderSide, $currentPrice, $relevant_asset) {
            $amount_to_use = $current_relevant_balance * ($this->amountPercentage / 100);

             // TODO: Add better minimum amount checks using exchangeInfo
             if ($amount_to_use <= 0) {
                 $this->logger->warning('Calculated amount to use is zero or negative. Cannot place order.', [
                     'asset' => $relevant_asset, 'balance' => $current_relevant_balance,
                     'percentage' => $this->amountPercentage, 'calculated_amount' => $amount_to_use,
                 ]);
                 throw new \DomainException('Calculated amount is zero or negative');
            }

            $limit_price = ($orderSide === 'BUY')
                ? $currentPrice * (1 - $this->orderPriceMarginPercent / 100)
                : $currentPrice * (1 + $this->orderPriceMarginPercent / 100);

            // Use consistent formatting (adjust precision based on exchangeInfo if possible)
            $precision = 8; // Example precision, fetch dynamically if possible
            $formatted_limit_price = number_format($limit_price, $precision, '.', '');
            $formatted_amount_to_use = number_format($amount_to_use, $precision, '.', '');

             $this->logger->info('Proceeding to place Convert Limit order', [
                'side' => $orderSide, 'amount_to_use' => $formatted_amount_to_use,
                'asset_spent' => $relevant_asset, 'limit_price' => $formatted_limit_price,
                'base_asset' => $this->convertBaseAsset, 'quote_asset' => $this->convertQuoteAsset,
             ]);

            return $this->placeConvertLimitOrder(
                $this->convertBaseAsset, $this->convertQuoteAsset, $orderSide,
                (float)$formatted_limit_price, (float)$formatted_amount_to_use
            );
        })
        ->then(function ($orderData) {
            if (isset($orderData['orderId'])) {
                $this->activeOrderId = (string)$orderData['orderId'];
                $this->orderPlaceTime = time();
                $this->logger->info('Convert Limit order placed successfully', [
                    'orderId' => $this->activeOrderId, 'place_time' => date('Y-m-d H:i:s', $this->orderPlaceTime)
                ]);
                // *** Reference price is NOT reset here, it's updated by the AI timer ***
            } else {
                 $this->logger->error('Order placement API call succeeded but response lacked orderId', ['response' => $orderData]);
                 // Don't set activeOrderId if missing
            }
        })
        ->catch(function (\Throwable $e) {
             $this->logger->error('Failed during order placement chain', ['exception' => $e->getMessage()]);
             // Don't clear active order state here if it wasn't set
        })
        ->finally(function () {
            $this->isPlacingOrder = false; // Clear placing flag regardless of outcome
        });
    }

    /**
     * Checks the status of the currently active order.
     */
    private function checkActiveOrderStatus(): void
    {
        if ($this->activeOrderId === null) return;

        $orderIdToCheck = $this->activeOrderId;
        $this->logger->debug('Checking status for active order', ['orderId' => $orderIdToCheck]);

        $this->getConvertOrderStatus($orderIdToCheck)
        ->then(function (array $orderStatusData) use ($orderIdToCheck) {
            $finishedStatuses = ['SUCCESS', 'FAILURE', 'EXPIRED', 'CANCELED'];
            $pendingStatuses = ['PENDING', 'PROCESS'];
            $currentStatus = $orderStatusData['orderStatus'] ?? 'UNKNOWN';

            if (in_array($currentStatus, $finishedStatuses)) {
                 $this->logger->info('Active order finished.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus]);
                 $this->clearActiveOrderState(); // Clear order state (doesn't touch reference price)

            } elseif (in_array($currentStatus, $pendingStatuses)) {
                 $this->logger->debug('Active order pending/processing.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus]);
                 $currentTime = time();
                 $elapsedTime = $currentTime - ($this->orderPlaceTime ?? $currentTime);

                 if ($elapsedTime >= $this->cancelAfterSeconds) {
                     $this->logger->warning('Cancellation timeout reached. Attempting cancellation.', ['orderId' => $orderIdToCheck]);
                     $this->cancelConvertLimitOrder($orderIdToCheck)
                         ->finally(function () { // Always clear state after cancel attempt
                              $this->clearActiveOrderState();
                         });
                 }
             } else {
                 $this->logger->warning('Unrecognized order status received.', ['orderId' => $orderIdToCheck, 'status' => $currentStatus]);
                 // Decide how to handle - maybe treat as pending for timeout?
             }
        })
        ->catch(function (\Throwable $e) use ($orderIdToCheck) {
             $errorMessage = $e->getMessage();
             // Check for "Order does not exist" type errors specifically
             if (str_contains($errorMessage, 'Order does not exist') || str_contains($errorMessage, '-2013') || str_contains($errorMessage, 'query order failed')) {
                  $this->logger->info('Order not found during status check (presumed finished). Clearing state.', ['orderId_checked' => $orderIdToCheck]);
                  $this->clearActiveOrderState();
             } else {
                 $this->logger->error('Failed to get order status', ['orderId_checked' => $orderIdToCheck, 'exception' => $errorMessage]);
                 // Do NOT clear state on general query errors, retry next time.
             }
        });
    }

    /**
     * Clears the state related ONLY to the active order. Does NOT reset reference price.
     */
    private function clearActiveOrderState(): void
    {
         $this->logger->debug('Clearing active order state variables', ['previous_order_id' => $this->activeOrderId]);
         $this->activeOrderId = null;
         $this->orderPlaceTime = null;
         // $this->isPlacingOrder should already be false unless cleared mid-placement attempt
         $this->isPlacingOrder = false;
    }


    // --- Core API Helpers (createSignedRequestData, makeAsyncApiRequest) ---
    // These remain the same as in autov2.php
    // ... (Copy createSignedRequestData and makeAsyncApiRequest from autov2.php here) ...
     /**
     * Creates signed request data including timestamp, recvWindow, and signature.
     */
    private function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $timestamp = round(microtime(true) * 1000);
        $params['timestamp'] = $timestamp;
        $params['recvWindow'] = self::API_RECV_WINDOW;

        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $queryString, $this->apiSecret);
        $params['signature'] = $signature; // Add signature AFTER hashing query string

        $url = self::REST_API_BASE_URL . $endpoint;
        $body = null;
        $headers = ['X-MBX-APIKEY' => $this->apiKey];

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986); // All params in URL for GET
        } else { // POST, PUT, DELETE
             // Ensure body is query string format for Binance
             $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
             // Set appropriate content type for form data
             $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return [
            'url' => $url,
            'headers' => $headers,
            'postData' => $body // Use 'postData' for consistency, though it's the body
        ];
    }

    /**
     * Makes an asynchronous HTTP request using ReactPHP Browser (for Binance API).
     */
    private function makeAsyncApiRequest(string $method, string $url, array $headers = [], ?string $body = null): PromiseInterface
    {
        $options = [
            'follow_redirects' => false,
            'timeout' => 10.0 // Standard timeout for Binance requests
        ];
         $logContext = ['method' => $method, 'url_endpoint' => parse_url($url, PHP_URL_PATH)];

        // Ensure body is passed correctly for non-GET requests
        $requestBody = ($method === 'GET') ? '' : ($body ?? '');

        return $this->browser->request($method, $url, $headers, $requestBody, $options)->then(
            function (ResponseInterface $response) use ($logContext) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $logContext['status'] = $statusCode;

                if ($statusCode >= 400) {
                    $this->logger->warning('Binance HTTP Error Status Code Received', $logContext + ['response_body_preview' => substr($body, 0, 200)]);
                    // Continue to parse for Binance error message
                }

                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('Failed to decode JSON response from Binance', $logContext + [
                        'json_error' => json_last_error_msg(), 'response_body_preview' => substr($body, 0, 200)
                    ]);
                    throw new \RuntimeException("Failed to decode Binance JSON response: " . json_last_error_msg());
                }

                 // Check for Binance API error structure ({ "code": ..., "msg": ... })
                 if (isset($data['code']) && $data['code'] != 0) {
                     $errorMessage = "Binance API error: " . ($data['msg'] ?? 'Unknown Error') . " (Code: " . $data['code'] . ")";
                     $logContext['api_code'] = $data['code'];
                     $logContext['api_msg'] = $data['msg'] ?? 'N/A';

                     $nonFatalCodes = [-2011, -2013]; // e.g., Unknown order sent, Order does not exist
                     if (in_array($data['code'], $nonFatalCodes)) {
                         $this->logger->info('Binance API Info: Known non-fatal error code received.', $logContext);
                         // Return the data, let caller handle interpretation
                         return $data;
                     }

                     $this->logger->error('Binance API Error Response', $logContext);
                     throw new \RuntimeException($errorMessage);
                 }

                 // Handle cases where HTTP status is >= 400 but no Binance error code was present
                 // (Should ideally not happen with Binance, but safeguard)
                 // Exception: cancelOrder success might not have 'code', check its specific structure
                 $isCancelSuccess = ($logContext['method'] === 'POST' && str_ends_with($logContext['url_endpoint'] ?? '', '/convert/limit/cancelOrder') && isset($data['status']) && $data['status'] === 'CANCELED');

                 if ($statusCode >= 400 && !$isCancelSuccess) {
                      $this->logger->error('Binance HTTP Error without specific API error code', $logContext + ['response_body_preview' => substr($body, 0, 200)]);
                      throw new \RuntimeException("HTTP Request to Binance failed with status {$statusCode} and unexpected API response structure.");
                 }

                 // $this->logger->debug('Binance API Request Success', $logContext);
                 return $data;
            },
            function (\Throwable $e) use ($logContext) {
                $this->logger->error('Binance API Request Failed (Network/Timeout/etc.)', $logContext + [
                    'exception_type' => get_class($e), 'exception_message' => $e->getMessage()
                ]);
                throw new \RuntimeException("Binance API Request failed: " . $e->getMessage(), 0, $e);
            }
        );
    }


    // --- Specific Binance API Call Wrappers ---
    // getSpecificAssetBalance, getLatestKlineClosePrice, placeConvertLimitOrder,
    // getConvertOrderStatus, cancelConvertLimitOrder remain the same as autov2.php
    // ... (Copy these 5 methods from autov2.php here) ...
      /**
     * Fetches the available ('free') balance for a single specified asset.
     */
    private function getSpecificAssetBalance(string $asset): PromiseInterface
    {
        $endpoint = '/sapi/v3/asset/getUserAsset';
        // POST required, even with no specific body params for filtering all assets
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
                    if (isset($assetInfo['asset'], $assetInfo['free']) && $assetInfo['asset'] === $asset) {
                        $balance = (float)$assetInfo['free'];
                        $this->logger->debug("Fetched free balance for asset", $logContext + ['balance' => $balance]);
                        return $balance;
                    }
                }
                $this->logger->info("Asset not found in getUserAsset response (balance likely 0).", $logContext);
                return 0.0; // Asset not found or zero balance
            })
            ->catch(function (\Throwable $e) use ($logContext) {
                 $this->logger->error('Failure in getSpecificAssetBalance chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e;
             });
    }

    /**
     * Fetches the close price of the most recent Kline (Public Endpoint).
     */
    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface
    {
        $endpoint = '/api/v3/klines';
        $params = [
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'limit' => 1,
        ];
        $url = self::REST_API_BASE_URL . $endpoint . '?' . http_build_query($params);
        $logContext = ['symbol' => $symbol, 'interval' => $interval];

        $this->logger->debug('Fetching latest kline price', $logContext);

        return $this->makeAsyncApiRequest('GET', $url, [])
            ->then(function ($data) use ($logContext) {
                if (!is_array($data) || empty($data) || !isset($data[0][4])) {
                     $this->logger->error('Invalid response format for klines', $logContext + ['response_preview' => json_encode($data[0] ?? null)]);
                     throw new \RuntimeException("Invalid response format for klines");
                }
                $closePrice = (float)$data[0][4]; // Index 4 is Close price
                 if ($closePrice <= 0) {
                     $this->logger->error('Invalid close price received from klines', $logContext + ['price' => $closePrice]);
                     throw new \RuntimeException("Invalid close price received: {$closePrice}");
                 }
                 $this->logger->debug('Fetched latest kline close price', $logContext + ['price' => $closePrice]);
                 return $closePrice;
            })
             ->catch(function (\Throwable $e) use ($logContext) {
                 $this->logger->error('Failure in getLatestKlineClosePrice chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e;
             });
    }

    /**
     * Places a Convert Limit order.
     */
    private function placeConvertLimitOrder(string $baseAsset, string $quoteAsset, string $side, float $limitPrice, float $amount, string $expiredType = '7_D'): PromiseInterface
    {
        $endpoint = '/sapi/v1/convert/limit/placeOrder';
        if ($limitPrice <= 0 || $amount <= 0) {
             return \React\Promise\reject(new \InvalidArgumentException("Invalid price or amount for placing order."));
        }
        if (!in_array($side, ['BUY', 'SELL'])) {
             return \React\Promise\reject(new \InvalidArgumentException("Invalid order side: {$side}"));
        }

        // Fetch precision dynamically from exchangeInfo if possible for production
        $precision = 8; // Hardcoded example
        $params = [
            'baseAsset' => $baseAsset,
            'quoteAsset' => $quoteAsset,
            'limitPrice' => number_format($limitPrice, $precision, '.', ''),
            'side' => $side,
            'expiredType' => $expiredType,
        ];
        if ($side === 'BUY') {
            $params['quoteAmount'] = number_format($amount, $precision, '.', '');
        } else { // SELL
            $params['baseAmount'] = number_format($amount, $precision, '.', '');
        }

        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        $logContext = [ /* Basic context */ 'side' => $side, 'base' => $baseAsset, 'quote' => $quoteAsset ];

        $this->logger->info('Calling placeConvertLimitOrder API...', $logContext + ['price' => $params['limitPrice'], 'amount_key' => ($side === 'BUY' ? 'quoteAmount' : 'baseAmount'), 'amount_value' => ($side === 'BUY' ? $params['quoteAmount'] : $params['baseAmount'])]);

        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function ($data) use ($logContext) {
                 if (!isset($data['orderId'])) {
                      $this->logger->error('Place order API response successful but missing orderId', $logContext + ['response' => $data]);
                      throw new \RuntimeException("Order placement response did not contain an orderId.");
                 }
                 // Success logged by attemptPlaceOrder
                 return $data;
            })
             ->catch(function (\Throwable $e) use ($logContext) {
                 $this->logger->error('Failure in placeConvertLimitOrder chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e;
             });
    }

    /**
     * Gets the status of a specific Convert order by its ID.
     */
    private function getConvertOrderStatus(string $orderId): PromiseInterface
    {
        $endpoint = '/sapi/v1/convert/orderStatus';
        $params = ['orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'GET');
        $logContext = ['orderId' => $orderId];

        $this->logger->debug('Calling getConvertOrderStatus API...', $logContext);

        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) use ($logContext) {
                 if (!isset($data['orderId'], $data['orderStatus'])) {
                      $this->logger->error('Invalid response format for getConvertOrderStatus', $logContext + ['response_preview' => json_encode($data)]);
                      throw new \RuntimeException("Invalid response format for getConvertOrderStatus");
                 }
                 $this->logger->debug('Get order status successful', $logContext + ['status' => $data['orderStatus']]);
                 return $data;
            })
            ->catch(function (\Throwable $e) use ($logContext) {
                 // Specific "not found" handling is done in checkActiveOrderStatus caller
                 $this->logger->error('Failure in getConvertOrderStatus chain', $logContext + ['exception' => $e->getMessage()]);
                 throw $e;
             });
    }


    /**
     * Cancels a specific Convert Limit order.
     */
    private function cancelConvertLimitOrder(string $orderId): PromiseInterface
    {
        $endpoint = '/sapi/v1/convert/limit/cancelOrder';
        $params = ['orderId' => $orderId];
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        $logContext = ['orderId' => $orderId];

        $this->logger->info('Calling cancelConvertLimitOrder API...', $logContext);

        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData'])
            ->then(function ($data) use ($logContext, $orderId) {
                 // Check for known non-fatal codes (already cancelled/filled)
                 if (isset($data['code']) && in_array($data['code'], [-2011, -2013])) {
                      $this->logger->info('Order cancellation confirmed non-existent or already processed via API code.', $logContext + ['api_code' => $data['code']]);
                 }
                 // Check for explicit CANCELED status from API response
                 elseif (isset($data['orderId'], $data['status']) && $data['orderId'] == $orderId && $data['status'] === 'CANCELED') {
                     $this->logger->info('Order cancellation successful via API response status CANCELED.', $logContext);
                 } else {
                     // No fatal error caught by makeAsyncApiRequest, but not the expected success/non-fatal response.
                     $this->logger->warning('Cancellation response structure unexpected or status not explicitly CANCELED, but no API error reported.', $logContext + ['response_preview' => json_encode($data)]);
                 }
                 return $data; // Return result regardless
            })
             ->catch(function (\Throwable $e) use ($logContext) {
                  $this->logger->error('Failure in cancelConvertLimitOrder chain', $logContext + ['exception' => $e->getMessage()]);
                  throw $e;
              });
    }

}


// --- Script Execution ---

$bot = new AiTradingBot( // Use the new class name
    apiKey: $apiKey,
    apiSecret: $apiSecret,
    geminiApiKey: $geminiApiKey, // Pass the Gemini key
    symbol: 'BTCUSDT',
    klineInterval: '1m',           // AI might perform better with slightly longer intervals (e.g., 1m, 5m) than 1s
    convertBaseAsset: 'BTC',
    convertQuoteAsset: 'USDT',
    orderSideOnPriceDrop: 'BUY',
    orderSideOnPriceRise: 'SELL',
    amountPercentage: 10.0,        // % of available balance
    triggerMarginPercent: 0.1,    // % deviation from AI reference price (e.g., 0.1% = 0.1) - Tune this carefully!
    orderPriceMarginPercent: 0.05, // % deviation from trigger price for limit order (e.g., 0.05% = 0.05)
    orderCheckIntervalSeconds: 10,  // Check active order status
    cancelAfterSeconds: 360,       // Auto-cancel after 15 minutes (900s)
    maxScriptRuntimeSeconds: 72000  // Max script runtime 2 hours (7200s)
);

$bot->run();

?>