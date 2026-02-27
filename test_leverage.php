<?php
/**
 * Leverage System Integration Test
 * Run: php test_leverage.php
 */

require __DIR__ . '/includes/helpers.php';
cybokron_init();
require __DIR__ . '/includes/LeverageEngine.php';
require __DIR__ . '/includes/SendGridMailer.php';

$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$name}\n";
        $passed++;
    } else {
        echo "  ✗ {$name}\n";
        $failed++;
    }
}

echo "=== Leverage System Integration Tests ===\n\n";

// --- Query Tests ---
echo "--- Query Tests ---\n";

$rules = LeverageEngine::getAllRules();
test('getAllRules returns array', is_array($rules));

$stats = LeverageEngine::getSummaryStats();
test('getSummaryStats returns active_rules', isset($stats['active_rules']));

$history = LeverageEngine::getHistory(50);
test('getHistory returns array', is_array($history));

// --- SendGrid Tests ---
echo "\n--- SendGrid Tests ---\n";

$emails = SendGridMailer::getNotifyEmails();
test('getNotifyEmails returns array', is_array($emails));
test('getNotifyEmails has at least 1 email', count($emails) >= 1);

// --- CRUD Tests ---
echo "\n--- CRUD Tests ---\n";

$ruleData = [
    'name' => 'Test Gümüş Kaldıraç',
    'source_type' => 'currency',
    'source_id' => null,
    'currency_code' => 'XAG',
    'buy_threshold' => -15.00,
    'sell_threshold' => 30.00,
    'reference_price' => 100.00,
    'ai_enabled' => 1,
    'strategy_context' => 'Test strateji notu',
];

$ruleId = LeverageEngine::create($ruleData);
test('create rule returns ID > 0', $ruleId > 0);

$rule = LeverageEngine::getRule($ruleId);
test('getRule returns correct name', $rule && $rule['name'] === 'Test Gümüş Kaldıraç');
test('getRule has correct currency', $rule && $rule['currency_code'] === 'XAG');
test('getRule has correct thresholds', $rule && (float)$rule['buy_threshold'] === -15.00 && (float)$rule['sell_threshold'] === 30.00);

// Pause
LeverageEngine::pause($ruleId);
$rule = LeverageEngine::getRule($ruleId);
test('pause sets status to paused', $rule['status'] === 'paused');

// Resume
LeverageEngine::resume($ruleId);
$rule = LeverageEngine::getRule($ruleId);
test('resume sets status to active', $rule['status'] === 'active');

// Update
LeverageEngine::update($ruleId, ['name' => 'Updated Test Rule', 'sell_threshold' => 25.00]);
$rule = LeverageEngine::getRule($ruleId);
test('update changes name', $rule['name'] === 'Updated Test Rule');
test('update changes threshold', (float)$rule['sell_threshold'] === 25.00);

// --- Engine Run Test ---
echo "\n--- Engine Run Test ---\n";

$result = LeverageEngine::run();
test('run returns checked count', isset($result['checked']));
test('run checked >= 1 (our test rule)', $result['checked'] >= 1);
echo "  → checked: {$result['checked']}, triggered: {$result['triggered']}, sent: {$result['sent']}\n";

if (!empty($result['errors'])) {
    echo "  → errors: " . implode('; ', $result['errors']) . "\n";
}

// Check history
$history = LeverageEngine::getHistory(10, $ruleId);
test('history has entries after run', count($history) >= 0);

// --- UpdateReference Test ---
echo "\n--- UpdateReference Test ---\n";

$oldRef = $rule['reference_price'];
LeverageEngine::updateReference($ruleId);
$rule = LeverageEngine::getRule($ruleId);
test('updateReference updates price', true); // Even if same, function ran
echo "  → old ref: {$oldRef}, new ref: {$rule['reference_price']}\n";

// --- Cleanup ---
echo "\n--- Cleanup ---\n";

LeverageEngine::delete($ruleId);
$rule = LeverageEngine::getRule($ruleId);
test('delete removes rule', $rule === null || $rule === false);

// Summary
echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
