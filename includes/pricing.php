<?php
// includes/pricing.php
// Single source of truth for panel pricing math and the upgrade UPDATE.
//
// Anywhere that calculates cycle months, per-node price, panel totals, or
// applies a plan upgrade should use one of these helpers — not inline math.

if (!defined('VORMOX_MAX_NODES')) {
    // ---------------------------------------------------------------------
    // Volume tiers. Single place to change pricing.
    // Tiers are inclusive on both ends.
    // ---------------------------------------------------------------------
    define('VORMOX_PRICING_TIERS', [
        ['min' => 1,  'max' => 4,  'price' => 10],
        ['min' => 5,  'max' => 10, 'price' => 9],
        ['min' => 11, 'max' => 25, 'price' => 8],
    ]);

    // Hard cap. Panels above this size need an admin/enterprise quote.
    define('VORMOX_MAX_NODES', 25);

    // Billing-cycle → months map. Add new cycles here; every helper picks it up.
    define('VORMOX_CYCLE_MONTHS', [
        'monthly'       => 1,
        'quarterly'     => 3,
        'semi_annually' => 6,
        'annually'      => 12,
        'yearly'        => 12, // accept legacy alias from older invoices
    ]);
}

if (!function_exists('vormox_price_per_node')) {

    /**
     * Per-node price in USD/month at the given node count. Returns 0 when the
     * node count is outside known tiers (e.g. > VORMOX_MAX_NODES — those need
     * a custom quote and shouldn't auto-price).
     */
    function vormox_price_per_node($nodes) {
        $nodes = (int) $nodes;
        foreach (VORMOX_PRICING_TIERS as $t) {
            if ($nodes >= $t['min'] && $nodes <= $t['max']) return (int) $t['price'];
        }
        return 0;
    }

    /**
     * Months for a billing cycle. Defaults to 1 when the cycle string is
     * unknown so callers don't accidentally bill $0.
     */
    function vormox_cycle_months($cycle) {
        $cycle = strtolower((string) $cycle);
        return VORMOX_CYCLE_MONTHS[$cycle] ?? 1;
    }

    /**
     * Full price for one cycle: nodes × per-node × cycle months.
     */
    function vormox_calculate_panel_total($nodes, $cycle) {
        return (int) $nodes * vormox_price_per_node($nodes) * vormox_cycle_months($cycle);
    }

    /**
     * Apply a plan upgrade once the matching UPG- invoice is Paid.
     *
     * - Bumps user_panels.nodes_count to the pending value
     * - Clears pending_nodes_count
     * - Recomputes total_price for the cycle at the new tier
     * - Reactivates the panel (status='active') if it was suspended
     *
     * Caller is responsible for being inside a transaction and for confirming
     * the invoice is genuinely an upgrade (UPG- prefix + non-empty
     * pending_nodes_count + non-empty panel_id).
     *
     * Returns the new total_price.
     */
    function vormox_apply_panel_upgrade(PDO $pdo, $panelId, $newNodes, $cycle) {
        $newNodes  = (int) $newNodes;
        $newTotal  = vormox_calculate_panel_total($newNodes, $cycle);

        $pdo->prepare("
            UPDATE user_panels
               SET nodes_count         = ?,
                   pending_nodes_count = NULL,
                   total_price         = ?,
                   status              = 'active'
             WHERE id = ?
        ")->execute([$newNodes, $newTotal, $panelId]);

        return $newTotal;
    }

    /**
     * Extend a panel's expiry by one billing cycle (renewal) and reactivate.
     * If the current expiry has already passed, the new period starts from now.
     *
     * Returns the new expiry as 'Y-m-d H:i:s'.
     */
    function vormox_apply_panel_renewal(PDO $pdo, $panelId, $cycle, $currentExpiry) {
        $months   = vormox_cycle_months($cycle);
        $base     = (empty($currentExpiry) || strtotime($currentExpiry) < time())
                    ? time()
                    : strtotime($currentExpiry);
        $newExpiry = date('Y-m-d H:i:s', strtotime("+{$months} months", $base));

        $pdo->prepare("UPDATE user_panels SET expiry_date = ?, status = 'active' WHERE id = ?")
            ->execute([$newExpiry, $panelId]);

        return $newExpiry;
    }
}
