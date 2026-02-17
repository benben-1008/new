<?php
// エラー出力を抑制（JSON出力を汚染しないように）
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 出力バッファリングを開始（予期しない出力を防ぐ）
ob_start();

// HTTPヘッダーの設定（セキュリティとパフォーマンス）
// JSON APIエンドポイントとして正しいContent-Typeを設定
header('Content-Type: application/json; charset=utf-8');
// CORS設定（CORBエラーを防ぐため）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
// セキュリティヘッダー（X-Content-Type-Optionsは必須）
header('X-Content-Type-Options: nosniff');
// Cache-Control: APIエンドポイントなのでキャッシュしない
header('Cache-Control: no-store');
// Pragmaヘッダーは削除（非推奨のため）

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// リクエストボディを取得
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit;
}

$userMessage = trim($input['message'] ?? '');
$history = $input['history'] ?? [];
$useOllama = isset($input['useOllama']) ? $input['useOllama'] : true;

// 安全チェック
if ($userMessage === '' || mb_strlen($userMessage) > 3000) {
    echo json_encode(['response' => 'メッセージサイズが不適切です'], JSON_UNESCAPED_UNICODE);
    exit;
}

// デバッグログを初期化（最初に実行）
if (!isset($GLOBALS['debug_logs'])) {
    $GLOBALS['debug_logs'] = [];
}

// Ollamaが利用可能かチェック
$ollamaAvailable = checkOllamaAvailability();

$response = generateAIResponse($userMessage, $useOllama, $ollamaAvailable, $history);

// 使用されたAPIを取得
$usedApi = $GLOBALS['used_ai_api'] ?? null;

// デバッグログを収集（開発者ツールで確認できるように）
$debugLogs = $GLOBALS['debug_logs'] ?? [];

// generateAIResponseから返された配列からAPI情報を取得
if (is_array($response) && isset($response['api'])) {
    $usedApi = $response['api'];
    $response = $response['response'];
} elseif ($usedApi === null && $ollamaAvailable && $useOllama) {
    // 設定を確認して、どのAPIが使用されるべきか判断（優先順位なし）
    $config = getAIConfig();
    // 有効なAPIを順番に確認（優先順位なし）
    if (($config['openai']['enabled'] ?? false) && !empty($config['openai']['api_key'])) {
        $usedApi = 'openai';
    } elseif (($config['gemini']['enabled'] ?? false) && !empty($config['gemini']['api_key'])) {
        $usedApi = 'gemini';
    } elseif (($config['groq']['enabled'] ?? false) && !empty($config['groq']['api_key'])) {
        $usedApi = 'groq';
    } elseif (($config['huggingface']['enabled'] ?? true)) {
        $usedApi = 'huggingface';
    }
}

// デバッグ情報（本番環境でも有効）
$debugInfo = [
    'ollamaAvailable' => $ollamaAvailable,
    'useOllama' => $useOllama,
    'isProduction' => isProductionEnvironment(),
    'messageLength' => mb_strlen($userMessage),
    'historyCount' => count($history),
    'responseLength' => mb_strlen($response),
    'usedApi' => $usedApi,
    'geminiEnabled' => getAIConfig()['gemini']['enabled'] ?? false,
    'geminiApiKeySet' => !empty(getAIConfig()['gemini']['api_key'] ?? ''),
    'openaiEnabled' => getAIConfig()['openai']['enabled'] ?? false,
    'openaiApiKeySet' => !empty(getAIConfig()['openai']['api_key'] ?? ''),
    'debugLogs' => $debugLogs, // 開発者ツールで確認できるログ
];

// 出力バッファをクリア（予期しない出力を削除）
ob_clean();

// レスポンスデータを安全に処理（JSONエンコードできない文字を削除）
$safeResponse = $response;
if (is_string($response)) {
    // 不正なUTF-8文字を削除
    $safeResponse = mb_convert_encoding($response, 'UTF-8', 'UTF-8');
    // NULLバイトを削除
    $safeResponse = str_replace("\0", '', $safeResponse);
    // 制御文字を削除（改行とタブは除く）
    $safeResponse = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $safeResponse);
}

// デバッグ情報も安全に処理
$safeDebugInfo = [];
foreach ($debugInfo as $key => $value) {
    if (is_string($value)) {
        $safeDebugInfo[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    } elseif (is_array($value)) {
        // 配列の場合は再帰的に処理
        $safeDebugInfo[$key] = array_map(function($item) {
            if (is_string($item)) {
                return mb_convert_encoding($item, 'UTF-8', 'UTF-8');
            }
            return $item;
        }, $value);
    } else {
        $safeDebugInfo[$key] = $value;
    }
}

// JSONエンコード（エラーハンドリング付き）
$jsonResponse = @json_encode([
    'response' => $safeResponse,
    'ollamaUsed' => $ollamaAvailable && $useOllama,
    'ollamaAvailable' => $ollamaAvailable,
    'apiType' => $usedApi ?? ($ollamaAvailable && $useOllama ? 'CloudAI' : 'Basic'),
    'usedApi' => $usedApi,
    'debug' => $safeDebugInfo
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);

// JSONエンコードエラーをチェック
if ($jsonResponse === false) {
    $jsonError = json_last_error_msg();
    $jsonErrorCode = json_last_error();
    
    // エラー詳細をログに記録
    addDebugLog("JSONエンコードエラー: $jsonError (コード: $jsonErrorCode)");
    addDebugLog("レスポンス長: " . mb_strlen($response));
    addDebugLog("レスポンス型: " . gettype($response));
    
    ob_clean();
    
    // 最小限の安全なレスポンスを返す
    $errorResponse = [
        'response' => '❌ JSONエンコードエラーが発生しました: ' . $jsonError,
        'error' => $jsonError,
        'errorCode' => $jsonErrorCode,
        'debug' => [
            'jsonError' => $jsonError,
            'jsonErrorCode' => $jsonErrorCode,
            'responseType' => gettype($response),
            'responseLength' => mb_strlen($response),
            'responsePreview' => mb_substr($safeResponse, 0, 200),
            'debugLogs' => $debugLogs
        ]
    ];
    
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    exit;
}

echo $jsonResponse;

// Ollamaの可用性をチェック
function checkOllamaAvailability() {
    // 本番環境ではクラウドOllamaサービスを使用
    if (isProductionEnvironment()) {
        return checkCloudOllamaAvailability();
    }
    
    // ローカル環境ではlocalhostのOllamaをチェック
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:11434/api/tags');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // エラーが発生した場合は利用不可
    if ($error) {
        error_log("Ollama connection error: " . $error);
        return false;
    }
    
    // 200番台のレスポンスコードなら利用可能
    if ($httpCode >= 200 && $httpCode < 300) {
        // llama3モデルが存在するか確認
        $data = json_decode($response, true);
        if (isset($data['models'])) {
            foreach ($data['models'] as $model) {
                $modelName = $model['name'] ?? '';
                if (strpos($modelName, 'llama3') !== false) {
                    return true;
                }
            }
            error_log("llama3モデルが見つかりません。'ollama pull llama3' を実行してください。");
        }
        return true; // モデルチェックが失敗しても、Ollama自体は利用可能
    }
    
    return false;
}

// AI API設定を読み込む
function getAIConfig() {
    static $config = null;
    if ($config === null) {
        $configPath = __DIR__ . '/ollama-config.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
        } else {
            $config = [
                'api_priority' => ['huggingface'],
                'groq' => ['enabled' => false, 'api_key' => '', 'model' => 'llama-3.1-8b-instant'],
                'openai' => ['enabled' => false, 'api_key' => '', 'model' => 'gpt-3.5-turbo'],
                'gemini' => ['enabled' => false, 'api_key' => '', 'model' => 'gemini-pro'],
                'ollama' => ['enabled' => false, 'production_url' => '', 'production_model' => 'llama3'],
                'huggingface' => ['enabled' => true],
                'timeout' => 120,
                'connect_timeout' => 15,
                'local_url' => 'http://localhost:11434',
            ];
        }
    }
    return $config;
}

// クラウドAIサービスの可用性をチェック
function checkCloudOllamaAvailability() {
    $config = getAIConfig();
    
    // 有効なAPIがあるかチェック（優先順位なし）
    if (($config['openai']['enabled'] ?? false) && !empty($config['openai']['api_key'])) {
        return true;
    } elseif (($config['gemini']['enabled'] ?? false) && !empty($config['gemini']['api_key'])) {
        return true;
    } elseif (($config['groq']['enabled'] ?? false) && !empty($config['groq']['api_key'])) {
        return true;
    } elseif (($config['huggingface']['enabled'] ?? true)) {
        return true;
    } elseif (($config['ollama']['enabled'] ?? false) && !empty($config['ollama']['production_url'])) {
        return true;
    }
    
    // デフォルトでHugging Face APIを使用
    return true;
}

// 本番環境かどうかを判定
function isProductionEnvironment() {
    // InfinityFreeやその他の本番環境の判定
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalhost = strpos($host, 'localhost') !== false || 
                   strpos($host, '127.0.0.1') !== false ||
                   strpos($host, '::1') !== false;
    
    return !$isLocalhost;
}

// Ollama APIを呼び出し
function callOllamaAPI($userMessage, $history = []) {
    // アレルギー情報を読み込む
    $dataDir = __DIR__ . '/../data';
    $allergiesData = readJsonSafe($dataDir . '/allergies.json');
    $allergies = $allergiesData['allergies'] ?? [];
    
    // アレルギー情報をテキスト形式に変換
    $allergyInfoText = '';
    if (!empty($allergies)) {
        $allergyInfoText = "\n\n【アレルギー情報】\n";
        foreach ($allergies as $item) {
            $allergenList = implode('、', $item['allergens']);
            $allergyInfoText .= "- {$item['menu']}：{$allergenList}\n";
        }
        $allergyInfoText .= "\n※アレルギーに関する質問には、この情報を基に正確に回答してください。";
    }
    
    // より自然な会話を生成するシステムプロンプト
    $systemPrompt = <<<EOD
あなたは親切で会話的、論理的に説明できる学校食堂のAIアシスタントです。ChatGPTやCopilotのような自然で流暢な会話を心がけてください。

主な役割：
- メニュー、営業時間、予約について質問に答える
- アレルギー情報について質問に答える（アレルギー情報は以下を参照）
- 学習のお手伝いとして数学、理科、英語などの教育関連の質問にも親切に答える
- 一般的な質問や雑談にも自然に対応する
{$allergyInfoText}

回答のスタイル：
- 自然で流暢な会話を心がける（ChatGPTやCopilotのような感じで）
- 明確で、例を入れつつ、過剰に長くしすぎない
- ユーザーの発言意図を汲み取り、文脈を理解して自然な会話を続ける
- 固定された回答ではなく、会話の流れに応じて柔軟に応答する
- 宿題の完全な答えを提供するのではなく、学習のヒントや解説を提供する
- 親切で丁寧、かつ自然な口調で対応する
- 必要に応じて「何か他に手伝えることはありますか？」で締める（毎回ではない）
- 同じ質問でも、会話の文脈に応じて異なる表現で答える
- ユーザーの質問に対して、単に情報を列挙するのではなく、会話として自然に返答する

重要なポイント：
- 固定された回答テンプレートを使わない
- 会話の文脈を理解して応答する
- 自然な会話の流れを保つ
- 毎回同じような応答にならないよう、バリエーションを持たせる
- ユーザーの質問の意図を深く理解し、それに応じた適切な応答をする
EOD;
    
    if (isProductionEnvironment()) {
        // 本番環境ではクラウドOllamaサービスを使用
        $result = callCloudOllamaAPI($userMessage, $systemPrompt, $history);
        if (is_array($result) && isset($result['response'])) {
            return $result;
        }
        return $result;
    } else {
        // ローカル環境ではlocalhostのOllamaを使用
        return callLocalOllamaAPI($userMessage, $systemPrompt, $history);
    }
}

// 利用可能なOllamaモデルを取得
function getAvailableOllamaModel() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:11434/api/tags');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['models'])) {
            // 優先順位: llama3 > llama2 > その他
            $preferredModels = ['llama3', 'llama2', 'llama', 'mistral', 'phi'];
            foreach ($preferredModels as $preferred) {
                foreach ($data['models'] as $model) {
                    $modelName = $model['name'] ?? '';
                    if (strpos($modelName, $preferred) !== false) {
                        return $modelName;
                    }
                }
            }
            // 利用可能な最初のモデルを返す
            if (!empty($data['models'])) {
                return $data['models'][0]['name'];
            }
        }
    }
    
    // デフォルトはllama3（インストールされていない場合はエラーになる）
    return 'llama3';
}

// ローカルOllama APIを呼び出し
function callLocalOllamaAPI($userMessage, $systemPrompt, $history = []) {
    // 利用可能なモデルを自動検出
    $model = getAvailableOllamaModel();
    
    // メッセージ配列を構築
    $messages = [];
    
    // システムプロンプトを追加
    $messages[] = [
        'role' => 'system',
        'content' => $systemPrompt
    ];
    
    // 直近の履歴（6ターンまで）を追加
    foreach (array_slice($history, -6) as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }
    }
    
    // 現在のユーザーメッセージを追加
    $messages[] = [
        'role' => 'user',
        'content' => $userMessage
    ];
    
    $requestBody = [
        'model' => $model, // 利用可能なモデルを自動使用
        'messages' => $messages,
        'stream' => false,
        'options' => [
            'temperature' => 0.8,  // より自然な応答のため温度を上げる
            'top_p' => 0.9,        // 多様性を確保
            'repeat_penalty' => 1.1 // 繰り返しを防ぐ
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:11434/api/chat');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // タイムアウトを延長（2分）
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // エラーログを記録
    if ($error) {
        error_log("Local Ollama API error: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['message']['content'])) {
            $content = trim($data['message']['content']);
            if ($content !== '') {
                return $content;
            }
        }
        // レスポンスの構造が異なる場合の処理
        if (isset($data['response'])) {
            $content = trim($data['response']);
            if ($content !== '') {
                return $content;
            }
        }
        error_log("Ollama response structure: " . json_encode($data));
    }
    
    error_log("Local Ollama API failed with HTTP code: " . $httpCode);
    error_log("Response: " . substr($response, 0, 500));
    if ($httpCode === 404) {
        $availableModel = getAvailableOllamaModel();
        error_log("Ollamaモデル '{$model}' が見つかりません。利用可能なモデル: {$availableModel}");
        error_log("モデルをインストールしてください: ollama pull {$model} または ollama pull {$availableModel}");
    }
    return false;
}

// クラウドAI APIを呼び出し（有効なAPIを順番に試行）
function callCloudOllamaAPI($userMessage, $systemPrompt, $history = []) {
    $config = getAIConfig();
    $timeout = $config['timeout'] ?? 120;
    $connectTimeout = $config['connect_timeout'] ?? 15;
    
    // メッセージ配列を構築
    $messages = [];
    $hasSystemMessage = false;
    
    // 履歴からシステムメッセージを探す
    foreach ($history as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
            if ($msg['role'] === 'system') {
                $messages[] = ['role' => 'system', 'content' => $msg['content']];
                $hasSystemMessage = true;
                break;
            }
        }
    }
    
    // システムメッセージがない場合は追加
    if (!$hasSystemMessage) {
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    
    // 履歴からユーザーとアシスタントのメッセージを追加
    foreach (array_slice($history, -6) as $msg) {
        if (isset($msg['role']) && isset($msg['content']) && $msg['role'] !== 'system') {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];
    
    // 有効なAPIを順番に試行（優先順位なし）
    $usedApi = null;
    $apisToTry = ['openai', 'gemini', 'groq', 'huggingface', 'ollama'];
    foreach ($apisToTry as $apiName) {
        error_log("=== 試行中: $apiName API ===");
        
        if ($apiName === 'groq' && ($config['groq']['enabled'] ?? false) && !empty($config['groq']['api_key'])) {
            error_log("Groq API: 有効化されており、APIキーが設定されています");
            $response = callGroqAPI($userMessage, $messages, $config['groq'], $timeout, $connectTimeout);
            if ($response !== false) {
                $usedApi = 'groq';
                error_log("=== 成功: Groq API が使用されました ===");
                return ['response' => $response, 'api' => $usedApi];
            }
            error_log("Groq API: 失敗");
        } else {
            if ($apiName === 'groq') {
                error_log("Groq API: スキップ - enabled=" . ($config['groq']['enabled'] ?? 'false') . ", api_key=" . (!empty($config['groq']['api_key']) ? '設定済み' : '未設定'));
            }
        }
        
        if ($apiName === 'openai' && ($config['openai']['enabled'] ?? false) && !empty($config['openai']['api_key'])) {
            error_log("OpenAI API: 有効化されており、APIキーが設定されています");
            $response = callOpenAIAPI($userMessage, $messages, $config['openai'], $timeout, $connectTimeout);
            if ($response !== false) {
                $usedApi = 'openai';
                error_log("=== 成功: OpenAI API が使用されました ===");
                return ['response' => $response, 'api' => $usedApi];
            }
            error_log("OpenAI API: 失敗");
        } else {
            if ($apiName === 'openai') {
                error_log("OpenAI API: スキップ - enabled=" . ($config['openai']['enabled'] ?? 'false') . ", api_key=" . (!empty($config['openai']['api_key']) ? '設定済み' : '未設定'));
            }
        }
        
        if ($apiName === 'gemini' && ($config['gemini']['enabled'] ?? false) && !empty($config['gemini']['api_key'])) {
            error_log("Gemini API: 有効化されており、APIキーが設定されています");
            error_log("Gemini API設定: enabled=" . ($config['gemini']['enabled'] ? 'true' : 'false') . ", model=" . ($config['gemini']['model'] ?? 'N/A'));
            $response = callGeminiAPI($userMessage, $systemPrompt, $history, $config['gemini'], $timeout, $connectTimeout);
            if ($response !== false) {
                $usedApi = 'gemini';
                error_log("=== 成功: Gemini API が使用されました ===");
                return ['response' => $response, 'api' => $usedApi];
            }
            error_log("Gemini API: 失敗");
        } else {
            if ($apiName === 'gemini') {
                error_log("Gemini API: スキップ - enabled=" . ($config['gemini']['enabled'] ?? 'false') . ", api_key=" . (!empty($config['gemini']['api_key']) ? '設定済み' : '未設定'));
            }
        }
        
        if ($apiName === 'ollama' && ($config['ollama']['enabled'] ?? false) && !empty($config['ollama']['production_url'])) {
            error_log("Ollama API: 有効化されており、URLが設定されています");
            $response = callCloudOllamaInstanceAPI($userMessage, $messages, $config['ollama'], $timeout, $connectTimeout);
            if ($response !== false) {
                $usedApi = 'ollama';
                error_log("=== 成功: Ollama API が使用されました ===");
                return ['response' => $response, 'api' => $usedApi];
            }
            error_log("Ollama API: 失敗");
        } else {
            if ($apiName === 'ollama') {
                error_log("Ollama API: スキップ - enabled=" . ($config['ollama']['enabled'] ?? 'false') . ", production_url=" . (!empty($config['ollama']['production_url']) ? '設定済み' : '未設定'));
            }
        }
        
        if ($apiName === 'huggingface' && ($config['huggingface']['enabled'] ?? true)) {
            error_log("Hugging Face API: 試行中");
            $fullPrompt = buildPromptWithHistory($userMessage, $systemPrompt, $history);
            $response = callHuggingFaceAPIWithPrompt($fullPrompt);
            if ($response !== false && trim($response) !== '') {
                $usedApi = 'huggingface';
                error_log("=== 成功: Hugging Face API が使用されました ===");
                return ['response' => $response, 'api' => $usedApi];
            }
            error_log("Hugging Face API: 失敗");
        } else {
            if ($apiName === 'huggingface') {
                error_log("Hugging Face API: スキップ - enabled=" . ($config['huggingface']['enabled'] ?? 'true'));
            }
        }
    }
    
    error_log("=== すべてのAI API試行が失敗しました ===");
    return false;
}

// Groq APIを呼び出し
function callGroqAPI($userMessage, $messages, $apiConfig, $timeout, $connectTimeout) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiConfig['base_url'] . '/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $apiConfig['model'],
        'messages' => $messages,
        'temperature' => 0.8,
        'max_tokens' => 2000,
    ], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiConfig['api_key']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Groq API error: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            error_log("Groq API success");
            return trim($data['choices'][0]['message']['content']);
        }
    }
    
    error_log("Groq API failed: HTTP $httpCode");
    return false;
}

// デバッグログを追加する関数
function addDebugLog($message) {
    if (!isset($GLOBALS['debug_logs'])) {
        $GLOBALS['debug_logs'] = [];
    }
    $timestamp = date('H:i:s');
    $GLOBALS['debug_logs'][] = "[$timestamp] $message";
    // ログが多すぎないように制限（最新50件）
    if (count($GLOBALS['debug_logs']) > 50) {
        $GLOBALS['debug_logs'] = array_slice($GLOBALS['debug_logs'], -50);
    }
}

// OpenAI APIを呼び出し
function callOpenAIAPI($userMessage, $messages, $apiConfig, $timeout, $connectTimeout) {
    addDebugLog("=== OpenAI API 呼び出し開始 ===");
    addDebugLog("モデル: " . ($apiConfig['model'] ?? 'N/A'));
    addDebugLog("Base URL: " . ($apiConfig['base_url'] ?? 'N/A'));
    addDebugLog("APIキー: " . (empty($apiConfig['api_key']) ? '未設定' : substr($apiConfig['api_key'], 0, 10) . '...'));
    
    error_log("=== OpenAI API 呼び出し開始 ===");
    error_log("モデル: " . ($apiConfig['model'] ?? 'N/A'));
    error_log("Base URL: " . ($apiConfig['base_url'] ?? 'N/A'));
    error_log("APIキー: " . (empty($apiConfig['api_key']) ? '未設定' : substr($apiConfig['api_key'], 0, 10) . '...'));
    
    $ch = curl_init();
    $url = $apiConfig['base_url'] . '/chat/completions';
    addDebugLog("リクエストURL: " . $url);
    error_log("リクエストURL: " . $url);
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    $requestBody = [
        'model' => $apiConfig['model'],
        'messages' => $messages,
        'temperature' => 0.8,
        'max_tokens' => 3000,
    ];
    $requestBodyJson = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
    addDebugLog("リクエストボディ: " . substr($requestBodyJson, 0, 200) . "...");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBodyJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiConfig['api_key']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    addDebugLog("OpenAI API リクエスト送信中...");
    error_log("OpenAI API リクエスト送信中...");
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $responseTime = round(($endTime - $startTime) * 1000, 2);
    addDebugLog("レスポンス時間: {$responseTime}ms");
    addDebugLog("HTTPステータスコード: $httpCode");
    error_log("レスポンス時間: {$responseTime}ms");
    error_log("HTTPステータスコード: $httpCode");
    
    if ($error) {
        addDebugLog("=== OpenAI API エラー ===");
        addDebugLog("CURLエラー: " . $error);
        error_log("=== OpenAI API エラー ===");
        error_log("CURLエラー: " . $error);
        return false;
    }
    
    // レスポンスの詳細をログに記録
    $responsePreview = substr($response, 0, 500);
    addDebugLog("レスポンス（最初の500文字）: " . $responsePreview);
    error_log("OpenAI API レスポンス（最初の500文字）: " . $responsePreview);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        
        // JSONデコードのエラーをチェック
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            addDebugLog("=== OpenAI API JSON解析エラー ===");
            addDebugLog("JSONエラー: " . $jsonError);
            addDebugLog("レスポンス本文: " . substr($response, 0, 1000));
            error_log("=== OpenAI API JSON解析エラー ===");
            error_log("JSONエラー: " . $jsonError);
            error_log("レスポンス本文: " . substr($response, 0, 1000));
            return false;
        }
        
        // レスポンス構造を詳細にログ（簡略版をデバッグログに）
        $responseStructure = json_encode($data, JSON_UNESCAPED_UNICODE);
        addDebugLog("レスポンス構造（簡略）: " . substr($responseStructure, 0, 500) . "...");
        error_log("OpenAI API レスポンス構造: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        if (isset($data['choices'][0]['message']['content'])) {
            $responseText = trim($data['choices'][0]['message']['content']);
            if (empty($responseText)) {
                addDebugLog("=== OpenAI API 警告: レスポンスが空です ===");
                addDebugLog("レスポンス構造: " . substr($responseStructure, 0, 300));
                error_log("=== OpenAI API 警告: レスポンスが空です ===");
                error_log("レスポンス構造: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return false;
            }
            addDebugLog("=== OpenAI API 成功 ===");
            addDebugLog("レスポンス長: " . mb_strlen($responseText) . " 文字");
            addDebugLog("レスポンス（最初の100文字）: " . mb_substr($responseText, 0, 100));
            error_log("=== OpenAI API 成功 ===");
            error_log("レスポンス長: " . mb_strlen($responseText) . " 文字");
            error_log("レスポンス（最初の100文字）: " . mb_substr($responseText, 0, 100));
            return $responseText;
        } else {
            addDebugLog("=== OpenAI API レスポンス解析エラー ===");
            addDebugLog("期待される構造: data['choices'][0]['message']['content']");
            addDebugLog("実際の構造: " . substr($responseStructure, 0, 500));
            error_log("=== OpenAI API レスポンス解析エラー ===");
            error_log("期待される構造: data['choices'][0]['message']['content']");
            error_log("実際の構造: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
            // 代替のレスポンス構造をチェック
            if (isset($data['choices'][0]['text'])) {
                addDebugLog("代替構造を発見: data['choices'][0]['text']");
                $responseText = trim($data['choices'][0]['text']);
                return $responseText;
            }
            
            // エラー情報がある場合
            if (isset($data['error'])) {
                $errorInfo = json_encode($data['error'], JSON_UNESCAPED_UNICODE);
                addDebugLog("エラー情報: " . $errorInfo);
                error_log("エラー情報: " . $errorInfo);
            }
        }
    } else {
        addDebugLog("=== OpenAI API HTTP エラー ===");
        addDebugLog("HTTPステータス: $httpCode");
        error_log("=== OpenAI API HTTP エラー ===");
        error_log("HTTPステータス: $httpCode");
        $errorData = json_decode($response, true);
        if ($errorData) {
            $errorResponse = json_encode($errorData, JSON_UNESCAPED_UNICODE);
            addDebugLog("エラーレスポンス: " . substr($errorResponse, 0, 500));
            error_log("エラーレスポンス: " . json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if (isset($errorData['error']['message'])) {
                $errorMessage = $errorData['error']['message'];
                addDebugLog("エラーメッセージ: " . $errorMessage);
                error_log("エラーメッセージ: " . $errorMessage);
                
                // クォータ超過エラーの場合、特別な処理
                if (isset($errorData['error']['type']) && $errorData['error']['type'] === 'insufficient_quota') {
                    addDebugLog("⚠️ OpenAI API クォータ超過: 他のAPIにフォールバックします");
                    error_log("⚠️ OpenAI API クォータ超過エラー: 他のAPIにフォールバックします");
                }
            }
            if (isset($errorData['error']['type'])) {
                addDebugLog("エラータイプ: " . $errorData['error']['type']);
                error_log("エラータイプ: " . $errorData['error']['type']);
            }
        } else {
            addDebugLog("レスポンス本文: " . substr($response, 0, 500));
            error_log("レスポンス本文: " . substr($response, 0, 1000));
        }
    }
    
    return false;
}

// Gemini APIを呼び出し
function callGeminiAPI($userMessage, $systemPrompt, $history, $apiConfig, $timeout, $connectTimeout) {
    error_log("=== Gemini API 呼び出し開始 ===");
    error_log("モデル: " . ($apiConfig['model'] ?? 'N/A'));
    error_log("Base URL: " . ($apiConfig['base_url'] ?? 'N/A'));
    error_log("APIキー: " . (empty($apiConfig['api_key']) ? '未設定' : substr($apiConfig['api_key'], 0, 10) . '...'));
    
    // Gemini APIは会話履歴を含めたプロンプトを構築
    $prompt = $systemPrompt . "\n\n";
    foreach (array_slice($history, -6) as $msg) {
        $role = $msg['role'] ?? 'user';
        $content = $msg['content'] ?? '';
        $prompt .= ($role === 'user' ? "ユーザー: " : "アシスタント: ") . $content . "\n";
    }
    $prompt .= "ユーザー: " . $userMessage . "\nアシスタント:";
    
    error_log("プロンプト長: " . mb_strlen($prompt) . " 文字");
    
    $ch = curl_init();
    // Gemini APIのURL形式を修正（v1betaではなくv1を使用、または正しいエンドポイントを使用）
    // モデル名が正しいか確認: gemini-1.5-flash または gemini-pro
    $model = $apiConfig['model'] ?? 'gemini-pro';
    // v1betaエンドポイントを使用（404エラーの場合はv1を試す）
    $baseUrl = $apiConfig['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta';
    $url = $baseUrl . '/models/' . $model . ':generateContent?key=' . $apiConfig['api_key'];
    error_log("リクエストURL: " . str_replace($apiConfig['api_key'], '***', $url));
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    $requestBody = [
        'contents' => [['parts' => [['text' => $prompt]]]]
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    error_log("Gemini API リクエスト送信中...");
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    error_log("レスポンス時間: " . round(($endTime - $startTime) * 1000, 2) . "ms");
    error_log("HTTPステータスコード: $httpCode");
    
    if ($error) {
        error_log("=== Gemini API エラー ===");
        error_log("CURLエラー: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $responseText = trim($data['candidates'][0]['content']['parts'][0]['text']);
            error_log("=== Gemini API 成功 ===");
            error_log("レスポンス長: " . mb_strlen($responseText) . " 文字");
            error_log("レスポンス（最初の100文字）: " . mb_substr($responseText, 0, 100));
            return $responseText;
        } else {
            error_log("=== Gemini API レスポンス解析エラー ===");
            error_log("レスポンス構造: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if (isset($data['error'])) {
                error_log("エラー詳細: " . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
            }
        }
    } else {
        error_log("=== Gemini API HTTP エラー ===");
        error_log("HTTPステータス: $httpCode");
        $errorData = json_decode($response, true);
        if ($errorData) {
            error_log("エラーレスポンス: " . json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            error_log("レスポンス本文: " . substr($response, 0, 500));
        }
    }
    
    return false;
}

// クラウドOllamaインスタンスAPIを呼び出し
function callCloudOllamaInstanceAPI($userMessage, $messages, $apiConfig, $timeout, $connectTimeout) {
    $requestBody = [
        'model' => $apiConfig['production_model'] ?? 'llama3',
        'messages' => $messages,
        'stream' => false,
        'options' => ['temperature' => 0.8, 'top_p' => 0.9, 'repeat_penalty' => 1.1]
    ];
    
    $ch = curl_init();
    $url = rtrim($apiConfig['production_url'], '/') . '/api/chat';
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
    
    $headers = ['Content-Type: application/json'];
    if (!empty($apiConfig['api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $apiConfig['api_key'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Cloud Ollama API error: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['message']['content'])) {
            error_log("Cloud Ollama API success");
            return trim($data['message']['content']);
        }
        if (isset($data['response'])) {
            error_log("Cloud Ollama API success");
            return trim($data['response']);
        }
    }
    
    error_log("Cloud Ollama API failed: HTTP $httpCode");
    return false;
}

// 会話履歴を含めたプロンプトを構築
function buildPromptWithHistory($userMessage, $systemPrompt, $history = []) {
    $prompt = $systemPrompt . "\n\n";
    
    // 会話履歴を追加（直近6ターンまで）
    if (!empty($history)) {
        $prompt .= "会話履歴:\n";
        foreach (array_slice($history, -6) as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            if ($role === 'user') {
                $prompt .= "ユーザー: " . $content . "\n";
            } else {
                $prompt .= "アシスタント: " . $content . "\n";
            }
        }
        $prompt .= "\n";
    }
    
    $prompt .= "現在の質問: " . $userMessage . "\n回答:";
    
    return $prompt;
}

// Hugging Face APIを呼び出し
function callHuggingFaceAPI($userMessage, $systemPrompt) {
    $prompt = $systemPrompt . "\n\n[重要] 食堂に関係しない場合は簡潔に一般的な助言に留め、根拠のない断定を避ける。\n\n質問: " . $userMessage . "\n回答:";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api-inference.huggingface.co/models/microsoft/DialoGPT-medium');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'inputs' => $prompt,
        'parameters' => [
            'max_length' => 180,
            'temperature' => 0.2,
            'do_sample' => false,
            'pad_token_id' => 50256
        ]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (compatible; AI-Assistant/1.0)'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Hugging Face API error: " . $error);
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data[0]['generated_text'])) {
            $generatedText = $data[0]['generated_text'];
            // プロンプト部分を除去して回答のみを抽出
            $answer = str_replace($prompt, '', $generatedText);
            return trim($answer) ?: '申し訳ございません。適切な回答を生成できませんでした。';
        }
    }
    
    error_log("Hugging Face API failed with HTTP code: " . $httpCode);
    return false;
}

// Hugging Face APIを呼び出し（プロンプト版、会話履歴対応）
function callHuggingFaceAPIWithPrompt($fullPrompt) {
    // より良いモデルを試行（会話に適したモデル、複数の選択肢）
    $models = [
        'microsoft/DialoGPT-medium',  // 会話用モデル
        'gpt2',  // フォールバック
        'distilgpt2',  // 軽量モデル
        'facebook/blenderbot-400M-distill',  // チャットボット用
    ];
    
    foreach ($models as $model) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api-inference.huggingface.co/models/' . $model);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'inputs' => $fullPrompt,
            'parameters' => [
                'max_length' => 200,  // より長い応答を許可
                'temperature' => 0.7,  // より自然な応答
                'do_sample' => true,
                'top_p' => 0.9,
                'repetition_penalty' => 1.2
            ]
        ], JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (compatible; AI-Assistant/1.0)'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // タイムアウトを延長
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Hugging Face API error ($model): " . $error);
            continue; // 次のモデルを試行
        }
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            // エラーレスポンスのチェック
            if (isset($data['error'])) {
                error_log("Hugging Face API error ($model): " . $data['error']);
                continue;
            }
            
            if (isset($data[0]['generated_text'])) {
                $generatedText = $data[0]['generated_text'];
                
                // プロンプト部分を除去して回答のみを抽出（より正確に）
                $answer = $generatedText;
                
                // プロンプトが含まれている場合、それを削除
                if (strpos($generatedText, $fullPrompt) === 0) {
                    // プロンプトが先頭にある場合
                    $answer = substr($generatedText, strlen($fullPrompt));
                } else {
                    // プロンプトが途中にある場合、プロンプト以降を抽出
                    $promptPos = strpos($generatedText, $fullPrompt);
                    if ($promptPos !== false) {
                        $answer = substr($generatedText, $promptPos + strlen($fullPrompt));
                    }
                }
                
                // プロンプトが見つからない場合、生成されたテキスト全体を使用
                // ただし、プロンプトが長すぎる場合は別の方法で抽出
                if (mb_strlen($answer) < 10 && mb_strlen($generatedText) > mb_strlen($fullPrompt) + 10) {
                    // プロンプトの最後の部分（「回答:」など）を探す
                    $promptEndMarkers = ['回答:', '回答：', 'Answer:', 'アシスタント:', 'アシスタント：'];
                    foreach ($promptEndMarkers as $marker) {
                        $markerPos = mb_strpos($generatedText, $marker);
                        if ($markerPos !== false) {
                            $answer = mb_substr($generatedText, $markerPos + mb_strlen($marker));
                            break;
                        }
                    }
                }
                
                $answer = trim($answer);
                
                // レスポンスが不完全な場合（「ます。」だけなど）をチェック
                if (mb_strlen($answer) <= 3 && preg_match('/^[。、！？]+$/', $answer)) {
                    error_log("Hugging Face API incomplete response ($model): レスポンスが不完全です（句読点のみ）");
                    continue; // 次のモデルを試行
                }
                
                if ($answer !== '' && mb_strlen($answer) >= 10) { // 最低10文字以上
                    error_log("Hugging Face API success ($model): " . mb_substr($answer, 0, 50));
                    return $answer;
                } else {
                    error_log("Hugging Face API insufficient response ($model): レスポンス長=" . mb_strlen($answer) . "文字");
                    error_log("生成されたテキスト（最初の200文字）: " . mb_substr($generatedText, 0, 200));
                }
            } else {
                error_log("Hugging Face API unexpected response structure ($model): " . substr(json_encode($data), 0, 200));
            }
        } else if ($httpCode === 503) {
            // モデルがロード中の場合
            error_log("Hugging Face API model loading ($model), trying next model...");
            continue;
        } else {
            error_log("Hugging Face API failed with HTTP code: $httpCode ($model)");
            if ($response) {
                error_log("Response: " . substr($response, 0, 200));
            }
        }
    }
    
    return false;
}

function generateAIResponse($userMessage, $useOllama = true, $ollamaAvailable = false, $history = []) {
    // まず食堂データを確認（最優先）
    $cafeteriaAnswer = answerFromCafeteriaData($userMessage);
    if ($cafeteriaAnswer !== null) {
        return $cafeteriaAnswer;
    }
    
    // Ollamaを最優先で使用（useOllamaがtrueの場合）
    if ($useOllama && $ollamaAvailable) {
        $ollamaResponse = callOllamaAPI($userMessage, $history);
        
        // 配列形式のレスポンス（API名を含む）を処理
        if (is_array($ollamaResponse) && isset($ollamaResponse['response'])) {
            $responseText = $ollamaResponse['response'];
            $usedApi = $ollamaResponse['api'] ?? 'unknown';
            if (trim($responseText) !== '' && mb_strlen($responseText) > 10) {
                // グローバル変数に使用されたAPIを保存（デバッグ用）
                $GLOBALS['used_ai_api'] = $usedApi;
                return $responseText;
            }
        } elseif ($ollamaResponse !== false && trim($ollamaResponse) !== '' && mb_strlen($ollamaResponse) > 10) {
            return $ollamaResponse;
        }
        
        // エラーログに記録
        error_log("Ollama API call failed for message: " . substr($userMessage, 0, 100));
        
        // ローカル環境でOllamaが失敗した場合、簡易プロンプトで再試行
        if (!isProductionEnvironment()) {
            $simpleResponse = callOllamaAPISimple($userMessage);
            if ($simpleResponse !== false && trim($simpleResponse) !== '' && mb_strlen($simpleResponse) > 10) {
                return $simpleResponse;
            }
        }
    }
    
    // デフォルト応答を削除：AIが失敗した場合は明確なエラーメッセージを返す
    // これにより、AIが実際に動作しているかどうかを確認できる
    
    if (!$ollamaAvailable || !$useOllama) {
        return "❌ **AI APIが利用できません**\n\n" .
               "設定状況:\n" .
               "- AI API使用: " . ($useOllama ? "✅ 有効" : "❌ 無効") . "\n" .
               "- AI API利用可能: " . ($ollamaAvailable ? "✅ はい" : "❌ いいえ") . "\n\n" .
               "**デバッグ情報**: AI APIが正しく設定されていないか、接続に失敗しています。\n" .
               "設定ファイル（ollama-config.php）を確認してください。";
    }
    
    // AI API呼び出しが失敗した場合 - 詳細なエラー情報を収集
    $config = getAIConfig();
    $apisToCheck = ['openai', 'gemini', 'groq', 'huggingface', 'ollama'];
    $enabledApis = [];
    $testResults = [];
    $apiErrors = []; // 各APIのエラー情報を収集
    
    // デバッグログからOpenAIのクォータ超過エラーを確認
    $openaiQuotaError = false;
    if (!empty($debugLogs)) {
        foreach ($debugLogs as $log) {
            if (strpos($log, 'insufficient_quota') !== false || strpos($log, 'クォータ超過') !== false) {
                $openaiQuotaError = true;
                break;
            }
        }
    }
    
    // 各APIの接続テストを実行
    foreach ($apisToCheck as $apiName) {
        $isEnabled = false;
        $hasApiKey = false;
        $testResult = "未テスト";
        
        if ($apiName === 'gemini') {
            $isEnabled = $config['gemini']['enabled'] ?? false;
            $hasApiKey = !empty($config['gemini']['api_key'] ?? '');
            if ($isEnabled && $hasApiKey) {
                // 簡単な接続テスト
                $testCh = curl_init();
                curl_setopt($testCh, CURLOPT_URL, 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $config['gemini']['api_key']);
                curl_setopt($testCh, CURLOPT_POST, true);
                curl_setopt($testCh, CURLOPT_POSTFIELDS, json_encode(['contents' => [['parts' => [['text' => 'test']]]]]));
                curl_setopt($testCh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($testCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($testCh, CURLOPT_TIMEOUT, 5);
                curl_setopt($testCh, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($testCh, CURLOPT_SSL_VERIFYPEER, false);
                $testResponse = @curl_exec($testCh);
                $testHttpCode = curl_getinfo($testCh, CURLINFO_HTTP_CODE);
                $testError = curl_error($testCh);
                curl_close($testCh);
                
                if ($testError) {
                    $testResult = "❌ 接続エラー: " . substr($testError, 0, 50);
                } elseif ($testHttpCode === 0) {
                    $testResult = "❌ 接続タイムアウト（InfinityFreeの制限の可能性）";
                } elseif ($testHttpCode >= 200 && $testHttpCode < 300) {
                    $testResult = "✅ 接続成功";
                } else {
                    $testResult = "⚠️ HTTP $testHttpCode";
                }
            }
            $enabledApis[] = "Gemini (有効: " . ($isEnabled ? "はい" : "いいえ") . ", APIキー: " . ($hasApiKey ? "設定済み" : "未設定") . ", テスト: $testResult)";
        } elseif ($apiName === 'openai') {
            $isEnabled = $config['openai']['enabled'] ?? false;
            $hasApiKey = !empty($config['openai']['api_key'] ?? '');
            if ($isEnabled && $hasApiKey) {
                // OpenAI接続テスト
                $testCh = curl_init();
                curl_setopt($testCh, CURLOPT_URL, 'https://api.openai.com/v1/models');
                curl_setopt($testCh, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $config['openai']['api_key']]);
                curl_setopt($testCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($testCh, CURLOPT_TIMEOUT, 5);
                curl_setopt($testCh, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($testCh, CURLOPT_SSL_VERIFYPEER, false);
                $testResponse = @curl_exec($testCh);
                $testHttpCode = curl_getinfo($testCh, CURLINFO_HTTP_CODE);
                $testError = curl_error($testCh);
                curl_close($testCh);
                
                if ($testError) {
                    $testResult = "❌ 接続エラー: " . substr($testError, 0, 50);
                } elseif ($testHttpCode === 0) {
                    $testResult = "❌ 接続タイムアウト（InfinityFreeの制限の可能性）";
                } elseif ($testHttpCode >= 200 && $testHttpCode < 300) {
                    $testResult = "✅ 接続成功";
                } else {
                    $testResult = "⚠️ HTTP $testHttpCode";
                }
            }
            $enabledApis[] = "OpenAI (有効: " . ($isEnabled ? "はい" : "いいえ") . ", APIキー: " . ($hasApiKey ? "設定済み" : "未設定") . ", テスト: $testResult)";
        } elseif ($apiName === 'groq' && ($config['groq']['enabled'] ?? false) && !empty($config['groq']['api_key'])) {
            $enabledApis[] = "Groq (有効)";
        } elseif ($apiName === 'huggingface' && ($config['huggingface']['enabled'] ?? true)) {
            $enabledApis[] = "Hugging Face (有効)";
        }
    }
    
    $apisList = !empty($enabledApis) ? implode("\n- ", $enabledApis) : "なし";
    
    // cURLが利用可能かチェック
    $curlAvailable = function_exists('curl_init') ? "✅ 利用可能" : "❌ 利用不可";
    
    // OpenAIクォータ超過エラーの場合、特別なメッセージを表示
    if ($openaiQuotaError) {
        return "❌ **OpenAI APIのクォータが超過しました**\n\n" .
               "**エラー詳細**:\n" .
               "- OpenAI APIの無料クレジットが使い切られました\n" .
               "- または、APIキーにクレジットが残っていません\n\n" .
               "**試行されたAPI**:\n- " . $apisList . "\n\n" .
               "**解決策**:\n" .
               "1. OpenAI Platform（https://platform.openai.com/）でクレジットを追加する\n" .
               "2. 新しいAPIキーを取得する\n" .
               "3. 他のAPI（Gemini、Hugging Face）が自動的に試行されます\n\n" .
               "**現在の状況**:\n" .
               "- OpenAI API: ❌ クォータ超過のため使用不可\n" .
               "- 他のAPI（Gemini、Hugging Face）が自動的に試行されます\n" .
               "- デバッグログで詳細を確認してください";
    }
    
    return "❌ **AI API呼び出しが失敗しました**\n\n" .
           "**システム情報**:\n" .
           "- cURL機能: $curlAvailable\n" .
           "- ホスト: " . ($_SERVER['HTTP_HOST'] ?? '不明') . "\n" .
           "- 本番環境: " . (isProductionEnvironment() ? "はい" : "いいえ") . "\n\n" .
           "**試行されたAPI**:\n- " . $apisList . "\n\n" .
           "**考えられる原因**:\n" .
           "1. ⚠️ **InfinityFreeのセキュリティ設定により外部APIへの接続がブロックされている可能性が高いです**\n" .
           "   - InfinityFreeの無料プランでは外部APIへの接続が制限されている場合があります\n" .
           "   - cURLによる外部接続が許可されていない可能性があります\n\n" .
           "2. APIキーが無効または期限切れ\n" .
           "3. ネットワーク接続の問題\n" .
           "4. APIサービスの一時的な障害\n\n" .
           "**解決策**:\n" .
           "1. InfinityFreeの有料プランにアップグレードする（外部API接続が許可される可能性）\n" .
           "2. 別のホスティングサービス（例: 000webhost、Freehostia）を試す\n" .
           "3. サーバーのエラーログを確認する（InfinityFreeのコントロールパネルから）\n" .
           "4. ブラウザの開発者ツール（F12）のコンソールで詳細なエラーを確認する\n\n" .
           "**デバッグ情報**:\n" .
           "- 上記の「テスト」結果を確認してください\n" .
           "- 「接続タイムアウト」や「接続エラー」が表示されている場合、InfinityFreeの制限が原因の可能性が高いです";
}

// インテリジェントなフォールバック応答を生成（削除済み）
// デフォルト応答を削除したため、この関数は使用されません
// AIが実際に動作しているかどうかを確認するため、デフォルト応答は返しません
function generateIntelligentFallback($userMessage, $history = []) {
    // この関数は呼び出されません（generateAIResponseで呼び出しを削除済み）
    // 念のため、エラーを返すようにしています
    return null;
}

// 会話の文脈を分析
function analyzeConversationContext($history, $currentMessage) {
    $context = [
        'hasMenuContext' => false,
        'hasReservationContext' => false,
        'hasTimeContext' => false,
        'messageCount' => count($history)
    ];
    
    $allMessages = array_merge($history, [['role' => 'user', 'content' => $currentMessage]]);
    
    foreach ($allMessages as $msg) {
        $content = mb_strtolower($msg['content'] ?? '');
        if (mb_strpos($content, 'メニュー') !== false || mb_strpos($content, '料理') !== false) {
            $context['hasMenuContext'] = true;
        }
        if (mb_strpos($content, '予約') !== false) {
            $context['hasReservationContext'] = true;
        }
        if (mb_strpos($content, '時間') !== false || mb_strpos($content, '営業') !== false) {
            $context['hasTimeContext'] = true;
        }
    }
    
    return $context;
}

// 簡易版Ollama API呼び出し（システムプロンプトなし）
function callOllamaAPISimple($userMessage) {
    if (isProductionEnvironment()) {
        return false; // 本番環境では簡易版は使用しない
    }
    
    // 利用可能なモデルを自動検出
    $model = getAvailableOllamaModel();
    
    $requestBody = [
        'model' => $model, // 利用可能なモデルを自動使用
        'messages' => [
            [
                'role' => 'user',
                'content' => $userMessage
            ]
        ],
        'stream' => false
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:11434/api/chat');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return false;
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['message']['content'])) {
            return trim($data['message']['content']);
        }
    }
    
    return false;
}

// サーバー上のデータ(JSON)から回答を合成
function answerFromCafeteriaData($userMessage) {
    $msg = mb_strtolower($userMessage);

    $dataDir = __DIR__ . '/../data';
    $today = (new DateTime())->format('Y-m-d');

    // 休業日
    $holidays = readJsonSafe($dataDir . '/holidays.json');
    $todayHoliday = null;
    foreach ($holidays as $h) {
        if (($h['date'] ?? '') === $today) { $todayHoliday = $h; break; }
    }
    
    // 今日が土日かチェック
    $todayObj = new DateTime($today);
    $dayOfWeek = (int)$todayObj->format('w'); // 0=日曜日, 6=土曜日
    $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
    
    // 土日の場合、休業日として扱う
    if ($isWeekend && !$todayHoliday) {
        $todayHoliday = [
            'date' => $today,
            'reason' => ($dayOfWeek == 0 ? '日曜日' : '土曜日')
        ];
    }

    // 定食
    $dailyMenus = readJsonSafe($dataDir . '/daily-menu.json');
    $todayMenu = null;
    foreach ($dailyMenus as $m) {
        if (($m['date'] ?? '') === $today) { $todayMenu = $m; break; }
    }

    // 予約時間
    $reservationTimes = readJsonSafe($dataDir . '/reservation-times.json');

    // 予約人数（全件）: 過去データをクリアしていない場合も合算
    $reservations = readJsonSafe($dataDir . '/reservations.json');
    $totalCount = is_array($reservations) ? count($reservations) : 0;

    // 来客数予測を取得（ai-advice.phpから）
    $predictionData = null;
    $predictionNumber = null;
    $congestion = 'データ不足';
    $congestionColor = '#6c757d';
    
    // ai-advice.phpから予測データを取得
    $adviceApiPath = __DIR__ . '/ai-advice.php';
    if (file_exists($adviceApiPath)) {
        // 来客数データを読み込む
        $attendanceData = [];
        $attendanceDataWithDays = [];
        $attendanceJsonPath = $dataDir . '/attendance-data.json';
        if (file_exists($attendanceJsonPath)) {
            $attendanceContent = file_get_contents($attendanceJsonPath);
            $attendanceJson = json_decode($attendanceContent, true);
            if (is_array($attendanceJson)) {
                $attendanceData = $attendanceJson['attendance'] ?? [];
                $attendanceDataWithDays = $attendanceJson['attendanceWithDays'] ?? [];
            }
        }
        
        // 予測数を計算
        if (!empty($attendanceData)) {
            $todayDayOfWeek = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w')];
            
            // 曜日別統計を計算
            if (!empty($attendanceDataWithDays)) {
                $dayStats = [];
                foreach (['日', '月', '火', '水', '木', '金', '土'] as $day) {
                    $dayData = [];
                    foreach ($attendanceDataWithDays as $item) {
                        if (isset($item['day']) && $item['day'] === $day) {
                            $dayData[] = $item['attendance'];
                        }
                    }
                    if (!empty($dayData)) {
                        $dayStats[$day] = round(array_sum($dayData) / count($dayData));
                    }
                }
                
                if (isset($dayStats[$todayDayOfWeek])) {
                    $predictionNumber = $dayStats[$todayDayOfWeek];
                }
            }
            
            // 曜日別統計がない場合は全体平均を使用
            if ($predictionNumber === null) {
                $predictionNumber = round(array_sum($attendanceData) / count($attendanceData));
            }
            
            // 混雑度を判定
            if ($predictionNumber >= 45) {
                $congestion = '非常に混雑しそう';
                $congestionColor = '#dc3545';
            } else if ($predictionNumber >= 30) {
                $congestion = '混雑しそう';
                $congestionColor = '#fd7e14';
            } else if ($predictionNumber >= 15) {
                $congestion = 'やや混雑しそう';
                $congestionColor = '#ffc107';
            } else {
                $congestion = '空いていそう';
                $congestionColor = '#28a745';
            }
        }
    }
    
    // 予約人数ベースの混雑予測（フォールバック）
    if ($predictionNumber === null) {
        $congestion = '空いています';
        if ($totalCount >= 30) $congestion = '非常に混雑';
        else if ($totalCount >= 15) $congestion = 'やや混雑';
    }

    // アレルギー情報
    $allergiesData = readJsonSafe($dataDir . '/allergies.json');
    $allergies = $allergiesData['allergies'] ?? [];

    // ルール: 質問に応じて決定的返答（より自然な会話形式で）
    if (mb_strpos($msg, '定食') !== false || mb_strpos($msg, 'メニュー') !== false) {
        $menuFood = $todayMenu['food'] ?? '未設定';
        $statusText = $todayHoliday ? ('休業（理由: ' . ($todayHoliday['reason'] ?? '不明') . '）') : '営業予定';
        
        $responses = [
            "本日の定食は「{$menuFood}」です。\n\n営業状況は{$statusText}です。",
            "今日の定食は「{$menuFood}」となっています。\n\n営業状況は{$statusText}です。",
            "本日の定食メニューは「{$menuFood}」です。\n\n営業状況は{$statusText}です。"
        ];
        return $responses[array_rand($responses)];
    }

    if (mb_strpos($msg, '休業') !== false || mb_strpos($msg, '営業') !== false) {
        if ($todayHoliday) {
            $reason = $todayHoliday['reason'] ?? '不明';
            $responses = [
                "本日は🚫 休業となっております。\n\n理由: {$reason}",
                "申し訳ございませんが、本日は🚫 休業です。\n\n理由: {$reason}",
                "本日は🚫 休業となっています。\n\n理由: {$reason}"
            ];
            return $responses[array_rand($responses)];
        } else {
            $responses = [
                "本日は✅ 営業予定です。",
                "本日は✅ 営業しています。",
                "本日は✅ 営業予定となっています。"
            ];
            return $responses[array_rand($responses)];
        }
    }

    if (mb_strpos($msg, '予約時間') !== false || mb_strpos($msg, 'いつ予約') !== false || mb_strpos($msg, '予約可能') !== false) {
        if (!empty($reservationTimes) && ($reservationTimes['enabled'] ?? false)) {
            // 後方互換性: 古い形式を新しい形式に変換
            $timeSlots = [];
            if (isset($reservationTimes['timeSlots']) && is_array($reservationTimes['timeSlots'])) {
                $timeSlots = $reservationTimes['timeSlots'];
            } elseif (isset($reservationTimes['startTime']) && isset($reservationTimes['endTime'])) {
                $timeSlots = [
                    ['startTime' => $reservationTimes['startTime'], 'endTime' => $reservationTimes['endTime']]
                ];
            }
            
            if (!empty($timeSlots)) {
                $timeStrings = array_map(function($slot) {
                    return "{$slot['startTime']}〜{$slot['endTime']}";
                }, $timeSlots);
                $timeList = implode('、', $timeStrings);
                $message = $reservationTimes['message'] ?? '';
                $messageText = $message ? "\n\n補足: {$message}" : '';
                
                $responses = [
                    "予約可能時間は以下の通りです：\n\n{$timeList}{$messageText}",
                    "予約は以下の時間帯で受け付けています：\n\n{$timeList}{$messageText}",
                    "予約可能時間：\n\n{$timeList}{$messageText}"
                ];
                return $responses[array_rand($responses)];
            }
        }
        $responses = [
            "予約時間の制限は現在ありません。いつでも予約可能です。",
            "予約はいつでも可能です。時間制限はありません。",
            "予約時間の制限はありません。いつでも予約できます。"
        ];
        return $responses[array_rand($responses)];
    }

    if (mb_strpos($msg, '予約') !== false || mb_strpos($msg, '混雑') !== false || mb_strpos($msg, '人数') !== false) {
        $responses = [
            "現在の予約人数は{$totalCount}人です。\n\n混雑予測: {$congestion}",
            "予約人数は{$totalCount}人となっています。\n\n混雑予測: {$congestion}",
            "現在{$totalCount}人の予約があります。\n\n混雑予測: {$congestion}"
        ];
        return $responses[array_rand($responses)];
    }

    // アレルギー関連の質問
    if (mb_strpos($msg, 'アレルギー') !== false || mb_strpos($msg, 'アレルゲン') !== false) {
        if (empty($allergies)) {
            $responses = [
                "申し訳ございませんが、現在アレルギー情報が登録されていません。\n\n詳しくはスタッフにお問い合わせください。",
                "アレルギー情報は現在登録されていません。\n\n詳細については、スタッフまでお気軽にお問い合わせください。"
            ];
            return $responses[array_rand($responses)];
        }

        // 「メニュー名＋アレルギーは？」形式：メッセージからメニュー名らしい語を抽出し、そのメニューだけのアレルギーを返す
        $menuKeyword = null;
        if (preg_match('/^(.+?)の?アレルギー/u', $msg, $m) && mb_strlen(trim($m[1])) >= 1) {
            $menuKeyword = preg_replace('/の$/u', '', trim($m[1]));
        } elseif (preg_match('/(.+?)の?アレルギー(は|について)/u', $msg, $m) && mb_strlen(trim($m[1])) >= 1) {
            $menuKeyword = preg_replace('/の$/u', '', trim($m[1]));
        }
        if ($menuKeyword !== null && $menuKeyword !== '' && mb_strpos($menuKeyword, 'アレルギー') === false) {
            $matchedMenus = [];
            foreach ($allergies as $item) {
                if (mb_strpos($item['menu'], $menuKeyword) !== false) {
                    $matchedMenus[] = $item;
                }
            }
            // 一致なしで「〇〇のカレー」のように「の」が含まれる場合は、最後の部分（カレー）で再検索
            if (count($matchedMenus) === 0 && mb_strpos($menuKeyword, 'の') !== false) {
                $parts = explode('の', $menuKeyword);
                $lastPart = trim(end($parts));
                if ($lastPart !== '') {
                    foreach ($allergies as $item) {
                        if (mb_strpos($item['menu'], $lastPart) !== false) {
                            $matchedMenus[] = $item;
                        }
                    }
                    if (count($matchedMenus) > 0) {
                        $menuKeyword = $lastPart;
                    }
                }
            }
            if (count($matchedMenus) === 1) {
                $foundMenu = $matchedMenus[0];
                $allergenList = implode('、', $foundMenu['allergens']);
                $responses = [
                    "{$foundMenu['menu']}には以下のアレルギー物質が含まれています：\n\n{$allergenList}\n\n⚠️ アレルギーをお持ちの方は、予約時や来店時に必ずスタッフにお申し出ください。",
                    "{$foundMenu['menu']}のアレルギー物質は以下の通りです：\n\n{$allergenList}\n\n⚠️ アレルギーをお持ちの方は、必ずスタッフにご相談ください。"
                ];
                return $responses[array_rand($responses)];
            }
            if (count($matchedMenus) > 1) {
                $lines = [];
                foreach ($matchedMenus as $item) {
                    $allergenList = implode('、', $item['allergens']);
                    $lines[] = "・{$item['menu']}：{$allergenList}";
                }
                $block = implode("\n", $lines);
                $responses = [
                    "「{$menuKeyword}」に関連するメニューのアレルギー情報は以下の通りです：\n\n{$block}\n\n⚠️ アレルギーをお持ちの方は、予約時や来店時に必ずスタッフにお申し出ください。",
                    "{$menuKeyword}に関連するメニューとアレルギー物質は以下の通りです：\n\n{$block}\n\n⚠️ アレルギーをお持ちの方は、必ずスタッフにご相談ください。"
                ];
                return $responses[array_rand($responses)];
            }
        }

        // 特定のメニューを聞かれているかチェック（メッセージにメニュー名がそのまま含まれる場合）
        $foundMenu = null;
        foreach ($allergies as $item) {
            if (mb_strpos($msg, $item['menu']) !== false) {
                $foundMenu = $item;
                break;
            }
        }

        if ($foundMenu) {
            $allergenList = implode('、', $foundMenu['allergens']);
            $responses = [
                "{$foundMenu['menu']}には以下のアレルギー物質が含まれています：\n\n{$allergenList}\n\n⚠️ アレルギーをお持ちの方は、予約時や来店時に必ずスタッフにお申し出ください。",
                "{$foundMenu['menu']}のアレルギー物質は以下の通りです：\n\n{$allergenList}\n\n⚠️ アレルギーをお持ちの方は、必ずスタッフにご相談ください。"
            ];
            return $responses[array_rand($responses)];
        }

        // 特定のアレルゲンを聞かれているかチェック
        $commonAllergens = ['小麦', '大豆', '乳', '卵', 'そば', 'エビ', 'カニ', '落花生'];
        $foundAllergen = null;
        foreach ($commonAllergens as $allergen) {
            if (mb_strpos($msg, $allergen) !== false) {
                $foundAllergen = $allergen;
                break;
            }
        }

        if ($foundAllergen) {
            $menusWithAllergen = [];
            foreach ($allergies as $item) {
                if (in_array($foundAllergen, $item['allergens'])) {
                    $menusWithAllergen[] = $item['menu'];
                }
            }

            if (!empty($menusWithAllergen)) {
                $menuList = implode('、', $menusWithAllergen);
                $responses = [
                    "{$foundAllergen}を含むメニューは以下の通りです：\n\n{$menuList}\n\n⚠️ アレルギーをお持ちの方は、予約時や来店時に必ずスタッフにお申し出ください。",
                    "{$foundAllergen}が含まれているメニューは：\n\n{$menuList}\n\n⚠️ アレルギーをお持ちの方は、必ずスタッフにご相談ください。"
                ];
                return $responses[array_rand($responses)];
            } else {
                $responses = [
                    "{$foundAllergen}を含むメニューは現在ありません。\n\nただし、調理環境により混入の可能性がありますので、アレルギーをお持ちの方は必ずスタッフにご相談ください。",
                    "現在のメニューには{$foundAllergen}は含まれていません。\n\nただし、アレルギーをお持ちの方は、念のためスタッフにお問い合わせください。"
                ];
                return $responses[array_rand($responses)];
            }
        }

        // 全体的なアレルギー情報を返す
        $allergyInfo = "アレルギー情報一覧：\n\n";
        foreach ($allergies as $item) {
            $allergenList = implode('、', $item['allergens']);
            $allergyInfo .= "・{$item['menu']}：{$allergenList}\n";
        }
        $allergyInfo .= "\n⚠️ アレルギーをお持ちの方は、予約時や来店時に必ずスタッフにお申し出ください。\n\n詳細は<a href=\"allergy.html\">アレルギー情報ページ</a>でもご確認いただけます。";

        $responses = [
            $allergyInfo,
            "以下が各メニューのアレルギー情報です：\n\n" . $allergyInfo
        ];
        return $responses[array_rand($responses)];
    }

    // パンか食堂かの推奨
    if (mb_strpos($msg, 'パン') !== false && mb_strpos($msg, '食堂') !== false || 
        mb_strpos($msg, '売店') !== false || mb_strpos($msg, 'どっち') !== false ||
        mb_strpos($msg, 'どちら') !== false || mb_strpos($msg, 'おすすめ') !== false && 
        (mb_strpos($msg, 'パン') !== false || mb_strpos($msg, '食堂') !== false)) {
        
        // 来客数予測を取得
        $recommendation = '';
        if ($predictionNumber !== null) {
            if ($predictionNumber >= 45) {
                $recommendation = "今日の予測来客数は約{$predictionNumber}人です。\n\n混雑が予測されるため、🍞 **売店のパン**をおすすめします。\n\n食堂は混雑している可能性が高いので、売店のパンの方がスムーズに購入できると思います。";
            } else {
                $recommendation = "今日の予測来客数は約{$predictionNumber}人です。\n\n比較的空いている予測のため、🍽️ **食堂**をおすすめします。\n\nゆっくりと食事を楽しめると思います。";
            }
        } else {
            $recommendation = "来客数予測データが不足しているため、正確な推奨ができません。\n\n一般的には、混雑時は🍞 **売店のパン**、空いている時は🍽️ **食堂**がおすすめです。";
        }
        
        $responses = [
            $recommendation,
            $recommendation
        ];
        return $responses[array_rand($responses)];
    }

    return null; // データ駆動の対象外
}

function readJsonSafe($path) {
    if (!file_exists($path)) return [];
    $txt = @file_get_contents($path);
    if ($txt === false || $txt === '') return [];
    $json = json_decode($txt, true);
    return is_array($json) ? $json : [];
}
?>
