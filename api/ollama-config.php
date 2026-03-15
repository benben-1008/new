<?php
/**
 * AI API設定ファイル
 * 
 * InfinityFreeで使用可能な無料AI APIの設定
 * 複数のAPIを設定すると、有効なAPIを順番に試行します
 */

return [
    // ============================================
    // AI API設定（有効なAPIを順番に試行）
    // ============================================
    // 後方互換性のため残していますが、優先順位は使用されません
    'api_priority' => ['openai'],
    
    // ============================================
    // Groq API（無効化）
    // ============================================
    'groq' => [
        'enabled' => false,
        'api_key' => '',
        'model' => 'llama-3.1-8b-instant',
        'base_url' => 'https://api.groq.com/openai/v1',
    ],
    
    // ============================================
    // OpenAI API（無料枠あり）
    // ============================================
    // 登録: https://platform.openai.com/
    // 無料枠: 初月$5クレジット
    'openai' => [
        'enabled' => true,
        'api_key' => '',
        'model' => 'gpt-3.5-turbo', // または 'gpt-4'
        'base_url' => 'https://api.openai.com/v1',
    ],
    
    // ============================================
    // Google Gemini API（無効化）
    // ============================================
    'gemini' => [
        'enabled' => false,
        'api_key' => '',
        'model' => 'gemini-1.5-flash',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ],
    
    // ============================================
    // Ollama API（無効化）
    // ============================================
    'ollama' => [
        'enabled' => false,
        'production_url' => '',
        'production_model' => 'llama3',
        'api_key' => '',
    ],
    
    // ============================================
    // Hugging Face API（無効化）
    // ============================================
    'huggingface' => [
        'enabled' => false,
    ],
    
    // ============================================
    // 共通設定
    // ============================================
    'timeout' => 120,
    'connect_timeout' => 15,
    'local_url' => 'http://localhost:11434', // ローカル開発用
];