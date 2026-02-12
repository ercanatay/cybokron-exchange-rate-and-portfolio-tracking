<?php
/**
 * Cybokron Exchange Rate & Portfolio Tracking
 * Configuration File
 * 
 * Copy this file to config.php and update with your settings.
 */

return [
    // Database Configuration
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'cybokron',
        'user'     => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // Application Settings
    'app' => [
        'name'     => 'Cybokron Exchange Rate Tracker',
        'url'      => 'http://localhost/cybokron',
        'timezone' => 'Europe/Istanbul',
        'version'  => trim(file_get_contents(__DIR__ . '/VERSION')),
    ],

    // GitHub Settings (for self-update)
    'github' => [
        'owner' => 'ercanatay',
        'repo'  => 'cybokron-exchange-rate-and-portfolio-tracking',
    ],

    // Registered Banks
    'banks' => [
        'dunya-katilim' => [
            'file'  => 'banks/DunyaKatilim.php',
            'class' => 'DunyaKatilim',
        ],
    ],

    // Scraper Settings
    'scraper' => [
        'user_agent' => 'Cybokron/1.0 Exchange Rate Tracker',
        'timeout'    => 30,
        'retry'      => 3,
        'retry_delay' => 2, // seconds
    ],

    // Rate Update Settings
    'update' => [
        'interval_minutes' => 15,
        'market_open'      => '09:00',
        'market_close'     => '18:00',
        'market_days'      => [1, 2, 3, 4, 5], // Mon-Fri
    ],
];
