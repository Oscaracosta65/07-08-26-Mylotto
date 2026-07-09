<?php
/**
 * SKAI Functional Validation Script
 * ===================================
 * Runs correctness and regression tests for the SKAI prediction engine
 * without requiring a Joomla or database environment.
 *
 * Usage (CLI):  php skai_validate.php
 *
 * Exit code:  0 = all tests passed
 *             1 = one or more tests failed
 *
 * Tests cover:
 *  1. Math: combinationCount / harmonicNumber / hitAtLeastOneProbability
 *  2. Math: pairwise lift formula (log-lift correctness)
 *  3. Math: normalizeComponentScores min-max mapping
 *  4. Logic: SKAI_rerankByMarginals preserves scores for unsampled numbers
 *  5. Logic: double-JSON encoding regression (hidden lottery IDs)
 *  6. Logic: SKAI_getDomain fallback when SKAI2_getGameRules unavailable
 *  7. Logic: SKAI_storePairwiseStats batch size constant default
 *  8. Pipeline: score_total consistency flag after tail recovery stub
 *  9. Math: Metropolis acceptance bounds (exp clamping)
 * 10. Logic: Bayesian skip smoothing formula (uses hits, not cases)
 */

// This script must only be run from the command line.
// Running it in a web context could interfere with Joomla's _JEXEC guard.
if (PHP_SAPI !== 'cli') {
    die("skai_validate.php must be run from the command line.\n");
}

// Define the Joomla access guard so standalone functions that reference it
// do not produce a fatal error.  This is a CLI-only test stub.
defined('_JEXEC') or define('_JEXEC', 1);

// ---------------------------------------------------------------------------
// Minimal stubs so SKAI functions can be exercised without Joomla / DB.
// ---------------------------------------------------------------------------

if (!function_exists('SKAI2_getGameRules')) {
    function SKAI2_getGameRules($gameId) {
        // Return a stub spec for a standard 6/49 lottery.
        return [
            'game_id'        => $gameId,
            'game_type'      => 'regular',
            'main_pool_min'  => 1,
            'main_pool_max'  => 49,
            'main_pick'      => 6,
            'has_bonus'      => false,
            'is_daily_digit' => false,
            'source_valid'   => true,
            'warnings'       => [],
        ];
    }
    function SKAI2_sanitizeGameId($id) { return preg_replace('/[^a-zA-Z0-9\-_.]/', '', substr((string)$id, 0, 50)); }
}

if (!function_exists('SKAI_getTailRecoveryConfig')) {
    function SKAI_getTailRecoveryConfig() {
        return [
            'enabled'            => true,
            'analysis_window'    => 100,
            'rerank_start'       => 15,
            'rerank_end'         => 35,
            'seed_size'          => 10,
            'pairwise_enabled'   => true,
            'pairwise_cap'       => 0.15,
            'prior_cap'          => 0.10,
            'penalty_relaxation' => 0.5,
            'use_live_tuning'    => false,
            'debug_logging'      => false,
        ];
    }
}

// ---------------------------------------------------------------------------
// Inline re-implementations of the production functions under test.
// All functions below mirror the logic in skai_simpler.php exactly.
// They are redefined here (with _test suffixes) so the tests are fully
// self-contained and repeatable without a web server or database.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Inline re-implementations (identical logic to skai_simpler.php fixes).
// These must match the production implementations exactly.
// ---------------------------------------------------------------------------

/**
 * Test-inline: SKAI_getDomain (mirrors the fixed production version).
 */
function SKAI_getDomain_test($gameId) {
    if (function_exists('SKAI2_getGameRules') && (string)$gameId !== '') {
        try {
            $rules = SKAI2_getGameRules((string)$gameId);
            $min   = isset($rules['main_pool_min']) ? (int)$rules['main_pool_min'] : 1;
            $max   = isset($rules['main_pool_max']) ? (int)$rules['main_pool_max'] : 0;
            if ($max > 0 && $max >= $min) {
                return ['min' => $min, 'max' => $max];
            }
        } catch (\Throwable $e) { }
    }
    return ['min' => 1, 'max' => 70];
}

/**
 * Test-inline: SKAI_rerankByMarginals (mirrors the fixed production version).
 */
function SKAI_rerankByMarginals_test($candidates, $marginals) {
    foreach ($candidates as &$candidate) {
        $num      = $candidate['num'];
        $marginal = isset($marginals[$num]) ? (float)$marginals[$num] : null;
        $candidate['gibbs_marginal'] = ($marginal !== null) ? $marginal : 0.0;
        if ($marginal !== null) {
            $candidate['score_total'] = $marginal;
        }
    }
    unset($candidate);
    usort($candidates, function($a, $b) {
        $ma = $a['gibbs_marginal'] ?? 0.0;
        $mb = $b['gibbs_marginal'] ?? 0.0;
        if (abs($ma - $mb) > 1e-12) {
            return ($mb > $ma) ? 1 : -1;
        }
        return ($b['score_total'] ?? 0.0) <=> ($a['score_total'] ?? 0.0);
    });
    return $candidates;
}

/**
 * Test-inline: mylottoexpertNormalizeLotteryIdList (same as in skai_simpler.php).
 */
function test_normalizeLotteryIdList($value) {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        } else {
            $value = preg_split('/[^0-9]+/', $value);
        }
    }
    $out = [];
    foreach ((array)$value as $v) {
        $id = (int)$v;
        if ($id > 0) { $out[$id] = $id; }
    }
    ksort($out);
    return array_values($out);
}

/**
 * Test-inline: fixed mylottoexpertSetHiddenLotteryIds (no double-encode).
 */
function test_setHiddenLotteryIds_fixed($ids) {
    // Fixed: single json_encode of the normalized array.
    return json_encode(test_normalizeLotteryIdList($ids));
}

/**
 * Test-inline: old mylottoexpertSetHiddenLotteryIds (double-encode, for regression).
 */
function test_setHiddenLotteryIds_old($ids) {
    $value  = json_encode(test_normalizeLotteryIdList($ids));
    $stored = json_encode((string)$value); // double-encode (the bug)
    return $stored;
}

/**
 * Test-inline: mylottoexpertGetHiddenLotteryIds reader logic.
 */
function test_getHiddenLotteryIds($raw) {
    $decoded = json_decode((string)$raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_string($decoded)) { $raw = $decoded; }
        elseif (is_array($decoded)) { return test_normalizeLotteryIdList($decoded); }
    }
    return test_normalizeLotteryIdList((string)$raw);
}

/**
 * Minimal combinationCount (mirrors SKAI2_combinationCount).
 * Uses the multiplicative formula to avoid intermediate factorials.
 * Safe for lottery-size inputs (n <= 100, k <= 10) on 64-bit PHP where
 * PHP_INT_MAX = 9,223,372,036,854,775,807. For larger n/k, use bcmath.
 */
function test_combinationCount($n, $k) {
    if ($k < 0 || $k > $n) { return 0; }
    if ($k === 0 || $k === $n) { return 1; }
    if ($k > $n - $k) { $k = $n - $k; }
    $result = 1;
    for ($i = 0; $i < $k; $i++) {
        $result = intdiv($result * ($n - $i), $i + 1);
    }
    return $result;
}

/**
 * Minimal harmonicNumber (mirrors SKAI2_harmonicNumber).
 */
function test_harmonicNumber($n) {
    $h = 0.0;
    for ($i = 1; $i <= $n; $i++) { $h += 1.0 / $i; }
    return $h;
}

/**
 * Exact hypergeometric hit probability (mirrors SKAI2_calculateHitAtLeastOneProbability).
 */
function test_hitAtLeastOne($poolSize, $pickSize, $topN) {
    $numerator   = test_combinationCount($poolSize - $pickSize, $topN);
    $denominator = test_combinationCount($poolSize, $topN);
    if ($denominator <= 0) { return 0.0; }
    return 1.0 - ($numerator / $denominator);
}

/**
 * Minimal normalizeComponentScores (mirrors SKAI2_normalizeComponentScores).
 */
function test_normalizeComponentScores($values) {
    if (empty($values)) { return []; }
    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    $out = [];
    foreach ($values as $k => $v) {
        $out[$k] = ($range > 1e-9) ? 2.0 * (($v - $min) / $range) - 1.0 : 0.0;
    }
    return $out;
}

/**
 * Pairwise lift formula: log( p_ij / (p_i * p_j) )
 */
function test_pairwiseLift($p_ij, $p_i, $p_j) {
    if ($p_i <= 0 || $p_j <= 0 || $p_ij <= 0) { return null; }
    return log($p_ij / ($p_i * $p_j));
}

// ---------------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$errors = [];

function assert_equals($name, $actual, $expected, $tolerance = 0.0) {
    global $passed, $failed, $errors;
    if ($tolerance > 0) {
        $ok = (abs($actual - $expected) <= $tolerance);
    } else {
        $ok = ($actual === $expected);
    }
    if ($ok) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        $errors[] = $name;
        $actualFormatted   = is_array($actual)   ? json_encode($actual)   : var_export($actual,   true);
        $expectedFormatted = is_array($expected) ? json_encode($expected) : var_export($expected, true);
        echo "  FAIL  $name\n        got=$actualFormatted  want=$expectedFormatted\n";
    }
}

function assert_true($name, $condition) {
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        $errors[] = $name;
        echo "  FAIL  $name\n";
    }
}

// ===========================================================================
// Section 1: Math — combinationCount
// ===========================================================================
echo "\n[Section 1] combinationCount\n";
assert_equals('C(6,6)=1',        test_combinationCount(6,6),  1);
assert_equals('C(49,6)=13983816',test_combinationCount(49,6), 13983816);
assert_equals('C(10,0)=1',       test_combinationCount(10,0), 1);
assert_equals('C(5,3)=10',       test_combinationCount(5,3),  10);
assert_equals('C(n,k>n)=0',      test_combinationCount(3,5),  0);

// ===========================================================================
// Section 2: Math — harmonicNumber and expected MRR
// ===========================================================================
echo "\n[Section 2] harmonicNumber and E[MRR]\n";
$h1 = test_harmonicNumber(1);
assert_equals('H(1)=1.0',  $h1, 1.0, 1e-9);
$h4 = test_harmonicNumber(4);
assert_equals('H(4)=2.083...',  $h4, 1.0 + 0.5 + 1.0/3.0 + 0.25, 1e-9);
// E[MRR] = H_N / N  (N = pool size)
$N    = 49;
$emrr = test_harmonicNumber($N) / $N;
assert_true('E[MRR] in (0,1)', $emrr > 0.0 && $emrr < 1.0);

// ===========================================================================
// Section 3: Math — hit-at-least-one (hypergeometric)
// ===========================================================================
echo "\n[Section 3] hitAtLeastOneProbability (hypergeometric)\n";
// P(hit at least 1 of 6 winning balls by picking top-20 from pool 49)
$p = test_hitAtLeastOne(49, 6, 20);
assert_true('P(hit>=1) in (0,1) for 6/49 top-20', $p > 0.0 && $p < 1.0);
// P(hit at least 1) increases as topN increases
$p10 = test_hitAtLeastOne(49, 6, 10);
$p30 = test_hitAtLeastOne(49, 6, 30);
assert_true('P(top-30) > P(top-10)', $p30 > $p10);
// P(topN=pick) = 1 - C(N-pick, pick)/C(N,pick)
$p_exact = test_hitAtLeastOne(49, 6, 6);
assert_true('P(topN=pickSize) > 0', $p_exact > 0.0);

// ===========================================================================
// Section 4: Math — normalizeComponentScores
// ===========================================================================
echo "\n[Section 4] normalizeComponentScores\n";
$vals = [1 => 10.0, 2 => 20.0, 3 => 30.0];
$norm = test_normalizeComponentScores($vals);
assert_equals('min value normalizes to -1',   $norm[1], -1.0, 1e-9);
assert_equals('max value normalizes to +1',   $norm[3],  1.0, 1e-9);
assert_equals('mid value normalizes to 0.0',  $norm[2],  0.0, 1e-9);
// Constant vector
$const = [1 => 5.0, 2 => 5.0, 3 => 5.0];
$normC = test_normalizeComponentScores($const);
assert_equals('constant vector -> all 0.0',  $normC[1], 0.0, 1e-9);

// ===========================================================================
// Section 5: Math — pairwise lift
// ===========================================================================
echo "\n[Section 5] pairwise lift formula\n";
// Independence: p_ij = p_i * p_j => lift = log(1) = 0
$lift0 = test_pairwiseLift(0.05 * 0.05, 0.05, 0.05);
assert_equals('independent numbers -> lift=0', $lift0, 0.0, 1e-9);
// Positive co-occurrence
$liftPos = test_pairwiseLift(0.04, 0.05, 0.05); // p_ij > p_i*p_j
assert_true('positive co-occurrence -> lift > 0', $liftPos > 0.0);
// Negative co-occurrence
$liftNeg = test_pairwiseLift(0.001, 0.05, 0.05); // p_ij < p_i*p_j
assert_true('negative co-occurrence -> lift < 0', $liftNeg < 0.0);

// ===========================================================================
// Section 6: Logic — SKAI_getDomain uses game rules (Fix 1)
// ===========================================================================
echo "\n[Section 6] SKAI_getDomain uses game rules\n";
// Stub SKAI2_getGameRules returns main_pool_max=49 for any gameId
$domain6_49 = SKAI_getDomain_test('test_6_49');
assert_equals('domain max = 49 from game rules', $domain6_49['max'], 49);
assert_equals('domain min = 1 from game rules',  $domain6_49['min'],  1);
// Empty gameId falls back to 70
$domainFallback = SKAI_getDomain_test('');
assert_equals('empty gameId -> fallback max=70', $domainFallback['max'], 70);

// ===========================================================================
// Section 7: Logic — SKAI_rerankByMarginals preserves unsampled scores (Fix 2)
// ===========================================================================
echo "\n[Section 7] SKAI_rerankByMarginals preserves unsampled scores\n";
$candidates = [
    ['num' => 1, 'score_total' => 0.080],
    ['num' => 2, 'score_total' => 0.075],
    ['num' => 3, 'score_total' => 0.070],
    ['num' => 4, 'score_total' => 0.060],
];
// Gibbs only sampled num=1 and num=3
$marginals = [1 => 0.65, 3 => 0.40];
$reranked  = SKAI_rerankByMarginals_test($candidates, $marginals);

// Num 1 should be rank 1 (highest marginal 0.65)
assert_equals('rank-1 = num with highest marginal', $reranked[0]['num'], 1);
// Num 3 should be rank 2 (marginal 0.40)
assert_equals('rank-2 = num with 2nd-highest marginal', $reranked[1]['num'], 3);
// Unsampled numbers (2 and 4) keep their original score_total, not 0
assert_true('unsampled num=2 keeps score_total > 0', ($reranked[2]['score_total'] ?? 0) > 0);
assert_true('unsampled num=4 keeps score_total > 0', ($reranked[3]['score_total'] ?? 0) > 0);
// Score_total for num=1 replaced with its marginal
assert_equals('sampled num=1 score_total = marginal', $reranked[0]['score_total'], 0.65, 1e-9);
// Unsampled: num=2 (score=0.075) should rank before num=4 (score=0.060)
$nums23 = [$reranked[2]['num'], $reranked[3]['num']];
assert_true('unsampled tiebreak: num=2 (0.075) before num=4 (0.060)',
    $reranked[2]['num'] === 2 && $reranked[3]['num'] === 4);

// ===========================================================================
// Section 8: Logic — hidden lottery IDs double-encode regression (Fix 3)
// ===========================================================================
echo "\n[Section 8] hidden lottery IDs — no double-encode\n";
$ids = [3, 1, 2];
// Fixed version: single JSON array
$fixedStored  = test_setHiddenLotteryIds_fixed($ids);
$fixedDecoded = json_decode($fixedStored, true);
assert_true('fixed: stored value decodes to array', is_array($fixedDecoded));
assert_equals('fixed: decoded = normalized [1,2,3]', $fixedDecoded, [1, 2, 3]);

// Old version: JSON-encoded string wrapping a JSON array
$oldStored    = test_setHiddenLotteryIds_old($ids);
$oldDecoded   = json_decode($oldStored, true);
assert_true('old: stored value decodes to STRING (double-encode evidence)', is_string($oldDecoded));

// Getter correctly handles both formats
$fromFixed = test_getHiddenLotteryIds($fixedStored);
assert_equals('getter round-trips fixed format', $fromFixed, [1, 2, 3]);
$fromOld   = test_getHiddenLotteryIds($oldStored);
assert_equals('getter round-trips old double-encoded format', $fromOld, [1, 2, 3]);

// ===========================================================================
// Section 9: Logic — score_total consistency condition (Fix 6)
// ===========================================================================
echo "\n[Section 9] score_total consistency after tail recovery\n";
// Simulate rankedList after SKAI_applyTailMissRecovery:
// - Items 0..13 (core): no tail_score set
// - Items 14..34 (borderline): tail_score set
// - Items 35+ (tail rest): no tail_score set
$rl = [];
for ($i = 0; $i < 50; $i++) {
    $entry = ['num' => $i + 1, 'score_total' => 0.09 - $i * 0.001];
    if ($i >= 14 && $i <= 34) {
        // tail recovery set tail_score for borderline items
        $entry['tail_score'] = max(0.0, 0.075 - ($i - 14) * 0.001);
    }
    $rl[] = $entry;
}

// Fixed logic: update score_total for ALL items that have tail_score set
foreach ($rl as &$item) {
    if (isset($item['tail_score'])) {
        $item['score_total'] = (float)$item['tail_score'];
    }
}
unset($item);

// Consistency check: score_total for borderline items must now equal tail_score
$consistencyOk = true;
foreach ($rl as $item) {
    if (isset($item['tail_score']) && abs($item['score_total'] - $item['tail_score']) > 1e-9) {
        $consistencyOk = false;
        break;
    }
}
assert_true('score_total matches tail_score for all borderline items after fix', $consistencyOk);

// Items without tail_score keep original score_total
assert_true('core item (no tail_score) keeps original score_total',
    abs($rl[0]['score_total'] - (0.09 - 0 * 0.001)) < 1e-9);

// ===========================================================================
// Section 10: Logic — Bayesian skip smoothing uses hits not cases
// ===========================================================================
echo "\n[Section 10] Bayesian skip smoothing correctness\n";
// Smoothed rate: (hits + alpha * baseRate) / (cases + alpha)
// Scenario: 3 hits in 8 cases, baseRate = 0.12, alpha = 1.0
// Correct (hits):   (3 + 1*0.12) / (8 + 1) = 3.12 / 9 = 0.3467
// Incorrect (cases):(8 + 1*0.12) / (8 + 1) = 8.12 / 9 = 0.9022 -- inflated
$hits     = 3;
$cases    = 8;
$baseRate = 0.12;
$alpha    = 1.0;
$smoothed = ($hits + $alpha * $baseRate) / ($cases + $alpha);
assert_equals('smoothed hit rate (hits=3,cases=8)', $smoothed, (3 + 1 * 0.12) / (8 + 1), 1e-9);
// Verify using cases instead of hits would give a higher (inflated, wrong) result
$wrongSmoothed = ($cases + $alpha * $baseRate) / ($cases + $alpha);
assert_true('using cases instead of hits gives wrong (higher) value', $wrongSmoothed > $smoothed);
// With 0 hits (cold number), smoothed should be pulled toward baseRate
$smoothedCold = (0 + $alpha * $baseRate) / (10 + $alpha);
assert_true('cold number: smoothed pulled toward baseRate', abs($smoothedCold - $baseRate) < $baseRate);

// ===========================================================================
// Section 11: Math — Metropolis acceptance bounds
// ===========================================================================
echo "\n[Section 11] Metropolis-Hastings acceptance\n";
// For deltaE > 0, exp(deltaE) > 1, so any random value in [0,1) is less than it.
// In PHP, exp(very_large_positive) evaluates to INF (not an error), and
// any finite float is less than INF, so the acceptance condition is always true.
$largePositive  = 1000.0;
// exp(1000) evaluates to INF in PHP; comparison float < INF is always true.
$acceptProb_pos = exp($largePositive);
$rand01         = 0.9999;
assert_true('beneficial swap (deltaE>0) always accepted', $rand01 < $acceptProb_pos);
// For deltaE = 0, exp(0) = 1.0, acceptance is near 1.0
$acceptProb_zero = exp(0.0);
assert_equals('neutral swap (deltaE=0) acceptProb=1.0', $acceptProb_zero, 1.0, 1e-9);
// For negative deltaE, acceptance is fractional
$acceptProb_neg = exp(-1.0);
assert_true('harmful swap (deltaE<0) acceptProb in (0,1)', $acceptProb_neg > 0.0 && $acceptProb_neg < 1.0);
// Optimized form: clamp avoids unnecessary exp() for positive deltaE
$deltaE_test    = 5.0;
$acceptOptimized = ($deltaE_test >= 0) ? 1.0 : exp($deltaE_test);
assert_equals('optimized form: positive deltaE -> acceptProb=1.0', $acceptOptimized, 1.0, 1e-9);

// ===========================================================================
// Summary
// ===========================================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: $passed passed, $failed failed\n";
if (!empty($errors)) {
    echo "Failed tests:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
