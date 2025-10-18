<?php
/**
 * Transfer Report and Payment Management (UPDATED)
 *
 * - Hard balance checks for CASH & BANK before recording payments
 * - Clear error messages when funds are insufficient or bank selection invalid
 * - Uses idempotent journal entries to mirror cash/bank movements
 * - Keeps your DataTables UI and pair-wise “Settle” modals
 */

require_once 'includes/header.php';

// ---------- SAFE DEFAULTS / HELPERS UP FRONT ----------
$other_branch_id = null;

if (!function_exists('transfer_report_getBranchCash')) {
    function transfer_report_getBranchCash(int $branchId, $pdo)
    {
        // Opening cash
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(opening_amount),0) FROM cash_openings WHERE branch_id = ?");
        $stmt->execute([$branchId]);
        $cash = (float)$stmt->fetchColumn();

        // Cash sales
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(sp.amount),0)
             FROM sale_payments sp
             JOIN sales s ON s.id = sp.sale_id
             WHERE s.branch_id = ? AND sp.method = 'cash'"
        );
        $stmt->execute([$branchId]);
        $cash += (float)$stmt->fetchColumn();

        // Purchase payments (all treated as cash out in this helper)
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pp.amount),0)
             FROM purchase_payments pp
             JOIN purchases p ON p.id = pp.purchase_id
             WHERE p.branch_id = ?"
        );
        $stmt->execute([$branchId]);
        $cash -= (float)$stmt->fetchColumn();

        // Cash expenses
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE branch_id = ? AND payment_method = 'cash'");
        $stmt->execute([$branchId]);
        $cash -= (float)$stmt->fetchColumn();

        // Worker cash payments
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM worker_payments WHERE branch_id = ? AND method = 'cash'");
        $stmt->execute([$branchId]);
        $cash -= (float)$stmt->fetchColumn();

        // Journal entries to cash (+/-)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE branch_id = ? AND account_type = 'cash'");
        $stmt->execute([$branchId]);
        $cash += (float)$stmt->fetchColumn();

        return $cash;
    }
}

if (!function_exists('transfer_report_getBranchBank')) {
    function transfer_report_getBranchBank(int $branchId, $pdo)
    {
        $bank = 0.0;

        // Non-cash receipts (cheque only if deposited)
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(sp.amount),0)
             FROM sale_payments sp
             JOIN sales s ON s.id = sp.sale_id
             WHERE s.branch_id = ? AND (sp.method <> 'cash' AND (sp.method <> 'cheque' OR sp.deposit_date IS NOT NULL))"
        );
        $stmt->execute([$branchId]);
        $bank += (float)$stmt->fetchColumn();

        // Worker bank payments (outflow)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM worker_payments WHERE branch_id = ? AND method = 'bank'");
        $stmt->execute([$branchId]);
        $bank -= (float)$stmt->fetchColumn();

        // Journal entries to bank (+/-)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE branch_id = ? AND account_type = 'bank'");
        $stmt->execute([$branchId]);
        $bank += (float)$stmt->fetchColumn();

        return $bank;
    }
}

// ---------- ACCESS ----------
checkRole(['admin', 'manager']);

$message = '';
$message_type = 'info';

// ---------- CONTEXT ----------
$filter_branch_id = null;
if ($user['role_name'] === 'admin') {
    if (isset($_GET['branch_id']) && ctype_digit($_GET['branch_id'])) {
        $filter_branch_id = (int)$_GET['branch_id'];
    }
} else {
    $filter_branch_id = (int)($user['branch_id'] ?? 0);
}

$other_branch_id = null;
if ($filter_branch_id && isset($_GET['other_branch_id']) && ctype_digit($_GET['other_branch_id'])) {
    $tmpOther = (int)$_GET['other_branch_id'];
    if ($tmpOther > 0 && $tmpOther !== $filter_branch_id) {
        $other_branch_id = $tmpOther;
    }
}

// ---------- LEGACY PER-TRANSFER PAYMENT HANDLER (kept robust) ----------
if (isset($_POST['pay_transfer'])) {
    csrf_check();
    $transfer_id     = (int)($_POST['transfer_id'] ?? 0);
    $amount          = (float)($_POST['amount'] ?? 0);
    $paymentMethod   = isset($_POST['payment_method']) && in_array($_POST['payment_method'], ['cash','bank']) ? $_POST['payment_method'] : 'cash';
    $payer_branch_id = isset($_POST['payer_branch_id']) && ctype_digit((string)$_POST['payer_branch_id']) ? (int)$_POST['payer_branch_id'] : 0;

    // Optional bank accounts if method = bank
    $payer_bank_id    = ($paymentMethod === 'bank' && isset($_POST['payer_bank_id'])) ? (int)$_POST['payer_bank_id'] : 0;
    $receiver_bank_id = ($paymentMethod === 'bank' && isset($_POST['receiver_bank_id'])) ? (int)$_POST['receiver_bank_id'] : 0;

    if ($transfer_id > 0 && $amount > 0) {
        try {
            $stmt = $pdo->prepare("SELECT from_branch_id, to_branch_id FROM stock_transfers WHERE id = ?");
            $stmt->execute([$transfer_id]);
            $hdr = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$hdr) {
                throw new Exception('Transfer not found');
            }
            $fromId = (int)$hdr['from_branch_id'];
            $toId   = (int)$hdr['to_branch_id'];
            if ($payer_branch_id <= 0) {
                $payer_branch_id = $fromId;
            }
            $receiver_branch_id = ($payer_branch_id === $fromId) ? $toId : $fromId;

            // Cost and already paid
            $stmt = $pdo->prepare(
                "SELECT SUM(sti.quantity * sb.cost_price) AS total_value
                 FROM stock_transfer_items sti
                 JOIN stock_batches sb ON sb.id = sti.batch_id
                 JOIN stock_transfers t ON t.id = sti.transfer_id
                 WHERE sti.transfer_id = ? AND sb.branch_id = t.from_branch_id"
            );
            $stmt->execute([$transfer_id]);
            $totalValue = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transfer_payments WHERE transfer_id = ?");
            $stmt->execute([$transfer_id]);
            $paidSoFar = (float)$stmt->fetchColumn();

            $outstanding = $totalValue - $paidSoFar;
            if ($amount > $outstanding + 0.0001) {
                throw new Exception('Amount exceeds outstanding balance');
            }

            // --- HARD BALANCE CHECKS ---
            if ($paymentMethod === 'cash') {
                $cashNow = transfer_report_getBranchCash($payer_branch_id, $pdo);
                if ($amount > $cashNow + 0.0001) {
                    throw new Exception('Not enough CASH in hand for the payer branch.');
                }
            } else {
                // BANK: both accounts are required and must belong to the correct branches
                if ($payer_bank_id <= 0 || $receiver_bank_id <= 0) {
                    throw new Exception('Please select both payer and receiver BANK accounts.');
                }
                $stmtB = $pdo->prepare("SELECT id, branch_id, opening_balance FROM bank_accounts WHERE id = ?");
                $stmtB->execute([$payer_bank_id]);  $pAcc = $stmtB->fetch(PDO::FETCH_ASSOC);
                $stmtB->execute([$receiver_bank_id]); $rAcc = $stmtB->fetch(PDO::FETCH_ASSOC);

                if (!$pAcc || (int)$pAcc['branch_id'] !== $payer_branch_id) {
                    throw new Exception('Invalid payer bank account selection.');
                }
                if (!$rAcc || (int)$rAcc['branch_id'] !== $receiver_branch_id) {
                    throw new Exception('Invalid receiver bank account selection.');
                }
                $payerBal = (float)$pAcc['opening_balance'];
                if ($amount > $payerBal + 0.0001) {
                    throw new Exception('Not enough BANK balance in the selected payer account.');
                }
            }

            // --- WRITE CHANGES (TX) ---
            $pdo->beginTransaction();

            // Record payment
            $stmt = $pdo->prepare("INSERT INTO transfer_payments (transfer_id, amount, paid_by, paid_at) VALUES (?,?,?,NOW())");
            $stmt->execute([$transfer_id, $amount, $user['id']]);

            // Journal entries
            $accType = ($paymentMethod === 'bank') ? 'bank' : 'cash';
            $desc = 'Inter-branch settlement #' . $transfer_id;
            $today = date('Y-m-d');

            $stmtIns = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by)
                                      VALUES (?,?,?,?,?,?)");
            $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM journal_entries
                                      WHERE branch_id=? AND entry_date=? AND account_type=? AND amount=? AND description=?");

            // Payer out
            $stmtChk->execute([$payer_branch_id, $today, $accType, -$amount, $desc]);
            if ((int)$stmtChk->fetchColumn() === 0) {
                $stmtIns->execute([$payer_branch_id, $today, $accType, -$amount, $desc, $user['id']]);
            }

            // Receiver in
            $stmtChk->execute([$receiver_branch_id, $today, $accType, $amount, $desc]);
            if ((int)$stmtChk->fetchColumn() === 0) {
                $stmtIns->execute([$receiver_branch_id, $today, $accType, $amount, $desc, $user['id']]);
            }

            // Bank account balances if bank method
            if ($paymentMethod === 'bank') {
                $u = $pdo->prepare("UPDATE bank_accounts SET opening_balance = opening_balance - ? WHERE id = ?");
                $u->execute([$amount, $payer_bank_id]);
                $u = $pdo->prepare("UPDATE bank_accounts SET opening_balance = opening_balance + ? WHERE id = ?");
                $u->execute([$amount, $receiver_bank_id]);
            }

            $pdo->commit();
            $message = 'Payment recorded successfully!';
            $message_type = 'info';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = 'Error recording payment: ' . $e->getMessage();
            $message_type = 'danger';
        }
    } else {
        $message = 'Invalid transfer ID or amount.';
        $message_type = 'danger';
    }
}

// ---------- LOAD TRANSFERS (with optional branch pairing filter) ----------
$transfers = [];
try {
    $sql = "SELECT t.id, t.transferred_at, t.from_branch_id, t.to_branch_id,
                   fb.name AS from_branch, tb.name AS to_branch,
                   SUM(sti.quantity * sb.cost_price) AS total_cost,
                   COALESCE((SELECT SUM(tp.amount) FROM transfer_payments tp WHERE tp.transfer_id = t.id), 0) AS total_paid
            FROM stock_transfers t
            JOIN stock_transfer_items sti ON sti.transfer_id = t.id
            JOIN stock_batches sb ON sb.id = sti.batch_id
            JOIN branches fb ON fb.id = t.from_branch_id
            JOIN branches tb ON tb.id = t.to_branch_id";
    $where = [];
    $params = [];
    if ($filter_branch_id) {
        if ($other_branch_id) {
            $where[] = "((t.from_branch_id = ? AND t.to_branch_id = ?) OR (t.from_branch_id = ? AND t.to_branch_id = ?))";
            $params[] = $filter_branch_id;
            $params[] = $other_branch_id;
            $params[] = $other_branch_id;
            $params[] = $filter_branch_id;
        } else {
            $where[] = "(t.from_branch_id = ? OR t.to_branch_id = ?)";
            $params[] = $filter_branch_id;
            $params[] = $filter_branch_id;
        }
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " GROUP BY t.id ORDER BY t.transferred_at DESC, t.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll();
} catch (Throwable $e) {
    $transfers = [];
}

// Precompute outstanding
foreach ($transfers as &$tr) {
    $tr['total_cost'] = (float)$tr['total_cost'];
    $tr['total_paid'] = (float)$tr['total_paid'];
    $tr['outstanding'] = $tr['total_cost'] - $tr['total_paid'];
}
unset($tr);

// ---------- BULK “SETTLE WITH BRANCH” ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_pair']) && $filter_branch_id) {
    csrf_check();
    $pairOther    = isset($_POST['other_branch_id']) ? (int)$_POST['other_branch_id'] : 0;
    $payAmount    = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
    $payMethod    = isset($_POST['payment_method']) && in_array($_POST['payment_method'], ['cash','bank']) ? $_POST['payment_method'] : 'cash';
    $payerBankId    = ($payMethod === 'bank' && isset($_POST['payer_bank_id'])) ? (int)$_POST['payer_bank_id'] : 0;
    $receiverBankId = ($payMethod === 'bank' && isset($_POST['receiver_bank_id'])) ? (int)$_POST['receiver_bank_id'] : 0;

    if ($pairOther > 0 && $pairOther !== $filter_branch_id) {
        // permission
        $canSettlePair = ($user['role_name'] === 'admin') ||
                         ($user['role_name'] === 'manager' && (int)$user['branch_id'] === $filter_branch_id);
        if (!$canSettlePair) {
            $message = 'You do not have permission to settle between these branches.';
            $message_type = 'danger';
        } else {
            // compute net payable (our branch owes other)
            $branchOwesOther = 0.0;
            $otherOwesBranch = 0.0;
            foreach ($transfers as $tmpTr) {
                $out = $tmpTr['total_cost'] - $tmpTr['total_paid'];
                if ($out <= 0) continue;
                if ((int)$tmpTr['to_branch_id'] === $filter_branch_id && (int)$tmpTr['from_branch_id'] === $pairOther) {
                    $branchOwesOther += $out;
                }
                if ((int)$tmpTr['from_branch_id'] === $filter_branch_id && (int)$tmpTr['to_branch_id'] === $pairOther) {
                    $otherOwesBranch += $out;
                }
            }
            $netPayable = $branchOwesOther - $otherOwesBranch;

            if ($netPayable <= 0) {
                $message = 'No payable amount to settle between these branches.';
                $message_type = 'info';
            } else {
                // normalize pay amount
                if ($payAmount <= 0 || $payAmount > $netPayable) $payAmount = $netPayable;

                // --- HARD BALANCE CHECKS (BULK) ---
                if ($payMethod === 'cash') {
                    $available = transfer_report_getBranchCash($filter_branch_id, $pdo);
                    if ($payAmount > $available + 0.0001) {
                        $message = 'Not enough CASH in hand to settle this amount for the selected branch.';
                        $message_type = 'danger';
                    } else {
                        // proceed
                        try {
                            $pdo->beginTransaction();

                            // FIFO over transfers we owe (my branch is receiver)
                            $remaining = $payAmount;
                            $owingTransfers = [];
                            foreach ($transfers as $tmpTr) {
                                if ((int)$tmpTr['to_branch_id'] === $filter_branch_id &&
                                    (int)$tmpTr['from_branch_id'] === $pairOther) {
                                    $outAmt = $tmpTr['total_cost'] - $tmpTr['total_paid'];
                                    if ($outAmt > 0) $owingTransfers[] = $tmpTr;
                                }
                            }
                            usort($owingTransfers, function($a,$b){ return strtotime($a['transferred_at']) <=> strtotime($b['transferred_at']); });

                            $today = date('Y-m-d');
                            $stmtPay = $pdo->prepare("INSERT INTO transfer_payments (transfer_id, amount, paid_by, paid_at) VALUES (?,?,?,NOW())");
                            $stmtIns = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by)
                                                      VALUES (?,?,?,?,?,?)");
                            $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM journal_entries
                                                      WHERE branch_id=? AND entry_date=? AND account_type=? AND amount=? AND description=?");

                            foreach ($owingTransfers as $tmpTr) {
                                if ($remaining <= 0) break;
                                $outAmt = $tmpTr['total_cost'] - $tmpTr['total_paid'];
                                if ($outAmt <= 0) continue;
                                $toPay = min($outAmt, $remaining);
                                $transferId = (int)$tmpTr['id'];

                                $stmtPay->execute([$transferId, $toPay, $user['id']]);

                                $desc = 'Inter-branch settlement #' . $transferId;

                                // payer (filtered) cash out
                                $stmtChk->execute([$filter_branch_id, $today, 'cash', -$toPay, $desc]);
                                if ((int)$stmtChk->fetchColumn() === 0) {
                                    $stmtIns->execute([$filter_branch_id, $today, 'cash', -$toPay, $desc, $user['id']]);
                                }
                                // receiver (other) cash in
                                $stmtChk->execute([$pairOther, $today, 'cash', $toPay, $desc]);
                                if ((int)$stmtChk->fetchColumn() === 0) {
                                    $stmtIns->execute([$pairOther, $today, 'cash', $toPay, $desc, $user['id']]);
                                }

                                $remaining -= $toPay;
                            }

                            $pdo->commit();
                            $message = 'Settlement processed successfully!';
                            $message_type = 'info';
                            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (empty($_GET) ? '' : ('?' . http_build_query($_GET))));
                            exit;
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $message = 'Error processing settlement: ' . $e->getMessage();
                            $message_type = 'danger';
                        }
                    }
                } else {
                    // BANK: validate accounts & balances strictly
                    if ($payerBankId <= 0 || $receiverBankId <= 0) {
                        $message = 'Please select valid BANK accounts for both branches.';
                        $message_type = 'danger';
                    } else {
                        $stmtBank = $pdo->prepare("SELECT id, branch_id, opening_balance FROM bank_accounts WHERE id = ?");
                        $stmtBank->execute([$payerBankId]);    $pBank = $stmtBank->fetch(PDO::FETCH_ASSOC);
                        $stmtBank->execute([$receiverBankId]); $rBank = $stmtBank->fetch(PDO::FETCH_ASSOC);

                        if (!$pBank || (int)$pBank['branch_id'] !== $filter_branch_id) {
                            $message = 'Invalid payer bank account selection.';
                            $message_type = 'danger';
                        } elseif (!$rBank || (int)$rBank['branch_id'] !== $pairOther) {
                            $message = 'Invalid receiver bank account selection.';
                            $message_type = 'danger';
                        } else {
                            $available = (float)$pBank['opening_balance'];
                            if ($payAmount > $available + 0.0001) {
                                $message = 'Not enough BANK balance in the selected payer account.';
                                $message_type = 'danger';
                            } else {
                                // proceed
                                try {
                                    $pdo->beginTransaction();

                                    // FIFO over transfers we owe
                                    $remaining = $payAmount;
                                    $owingTransfers = [];
                                    foreach ($transfers as $tmpTr) {
                                        if ((int)$tmpTr['to_branch_id'] === $filter_branch_id &&
                                            (int)$tmpTr['from_branch_id'] === $pairOther) {
                                            $outAmt = $tmpTr['total_cost'] - $tmpTr['total_paid'];
                                            if ($outAmt > 0) $owingTransfers[] = $tmpTr;
                                        }
                                    }
                                    usort($owingTransfers, function($a,$b){ return strtotime($a['transferred_at']) <=> strtotime($b['transferred_at']); });

                                    $today = date('Y-m-d');
                                    $stmtPay = $pdo->prepare("INSERT INTO transfer_payments (transfer_id, amount, paid_by, paid_at) VALUES (?,?,?,NOW())");
                                    $stmtIns = $pdo->prepare("INSERT INTO journal_entries (branch_id, entry_date, account_type, amount, description, created_by)
                                                              VALUES (?,?,?,?,?,?)");
                                    $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM journal_entries
                                                              WHERE branch_id=? AND entry_date=? AND account_type=? AND amount=? AND description=?");

                                    foreach ($owingTransfers as $tmpTr) {
                                        if ($remaining <= 0) break;
                                        $outAmt = $tmpTr['total_cost'] - $tmpTr['total_paid'];
                                        if ($outAmt <= 0) continue;
                                        $toPay = min($outAmt, $remaining);
                                        $transferId = (int)$tmpTr['id'];

                                        $stmtPay->execute([$transferId, $toPay, $user['id']]);

                                        $desc = 'Inter-branch settlement #' . $transferId;

                                        // payer bank out
                                        $stmtChk->execute([$filter_branch_id, $today, 'bank', -$toPay, $desc]);
                                        if ((int)$stmtChk->fetchColumn() === 0) {
                                            $stmtIns->execute([$filter_branch_id, $today, 'bank', -$toPay, $desc, $user['id']]);
                                        }
                                        // receiver bank in
                                        $stmtChk->execute([$pairOther, $today, 'bank', $toPay, $desc]);
                                        if ((int)$stmtChk->fetchColumn() === 0) {
                                            $stmtIns->execute([$pairOther, $today, 'bank', $toPay, $desc, $user['id']]);
                                        }

                                        $remaining -= $toPay;
                                    }

                                    // Update bank ledgers
                                    $u = $pdo->prepare("UPDATE bank_accounts SET opening_balance = opening_balance - ? WHERE id = ?");
                                    $u->execute([$payAmount, $payerBankId]);
                                    $u = $pdo->prepare("UPDATE bank_accounts SET opening_balance = opening_balance + ? WHERE id = ?");
                                    $u->execute([$payAmount, $receiverBankId]);

                                    $pdo->commit();
                                    $message = 'Settlement processed successfully!';
                                    $message_type = 'info';
                                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (empty($_GET) ? '' : ('?' . http_build_query($_GET))));
                                    exit;
                                } catch (Throwable $e) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    $message = 'Error processing settlement: ' . $e->getMessage();
                                    $message_type = 'danger';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ---------- SUMMARY / BANK ACCOUNTS / BRANCHES ----------
$branchesForCash = [];
try {
    $branchesForCash = $pdo->query("SELECT id FROM branches WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $branchesForCash = [];
}
$totalCashAll = 0.0;
foreach ($branchesForCash as $bid) {
    $totalCashAll += transfer_report_getBranchCash((int)$bid, $pdo);
}

$payablesSum = 0.0;
$receivablesSum = 0.0;
$branchCash = null;

foreach ($transfers as $tmpTr) {
    $out = max(0, $tmpTr['total_cost'] - $tmpTr['total_paid']);
    if ($filter_branch_id) {
        if ((int)$tmpTr['from_branch_id'] === $filter_branch_id) $receivablesSum += $out;
        if ((int)$tmpTr['to_branch_id'] === $filter_branch_id)   $payablesSum    += $out;
    }
}
if ($filter_branch_id) {
    $branchCash = transfer_report_getBranchCash($filter_branch_id, $pdo);
    $userCanBulkPay = ($user['role_name'] === 'admin') ||
                      ($user['role_name'] === 'manager' && (int)$user['branch_id'] === $filter_branch_id);
}

$bankAccountsByBranch = [];
try {
    $stmt = $pdo->query("SELECT id, name, branch_id, opening_balance FROM bank_accounts");
    while ($acc = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bid = (int)$acc['branch_id'];
        $bankAccountsByBranch[$bid][] = [
            'id'              => (int)$acc['id'],
            'name'            => $acc['name'],
            'opening_balance' => (float)$acc['opening_balance'],
        ];
    }
} catch (Throwable $e) {
    $bankAccountsByBranch = [];
}

$allBranches = [];
try {
    $allBranches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $allBranches = [];
}

$branchNames = [];
foreach ($allBranches as $br) {
    $branchNames[(int)$br['id']] = $br['name'];
}

$filterBranchName = '';
if ($filter_branch_id) {
    if ($user['role_name'] === 'admin') {
        foreach ($allBranches as $br) {
            if ((int)$br['id'] === $filter_branch_id) { $filterBranchName = $br['name']; break; }
        }
        if ($filterBranchName === '') $filterBranchName = 'Branch #' . $filter_branch_id;
    } else {
        $filterBranchName = $user['branch_name'] ?? ('Branch #' . $filter_branch_id);
    }
}

// Pair-wise totals (for modal list)
$otherBranchTotals = [];
if ($filter_branch_id) {
    foreach ($transfers as $tmpTr) {
        $out = $tmpTr['total_cost'] - $tmpTr['total_paid'];
        if ($out <= 0) continue;
        if ((int)$tmpTr['from_branch_id'] === $filter_branch_id) {
            $other = (int)$tmpTr['to_branch_id'];
            if (!isset($otherBranchTotals[$other])) $otherBranchTotals[$other] = ['payable'=>0.0,'receivable'=>0.0];
            $otherBranchTotals[$other]['receivable'] += $out;
        }
        if ((int)$tmpTr['to_branch_id'] === $filter_branch_id) {
            $other = (int)$tmpTr['from_branch_id'];
            if (!isset($otherBranchTotals[$other])) $otherBranchTotals[$other] = ['payable'=>0.0,'receivable'=>0.0];
            $otherBranchTotals[$other]['payable'] += $out;
        }
    }
    foreach ($otherBranchTotals as $obid => $vals) {
        $otherBranchTotals[$obid]['net'] = $vals['payable'] - $vals['receivable']; // >0 you owe
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stock Transfer Payments</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="container">
  <h1 class="mb-4">Stock Transfer Payments</h1>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
      <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <?php
  // Settlement modals per “other branch” you owe (net > 0)
  if ($filter_branch_id && !empty($otherBranchTotals) && isset($userCanBulkPay) && $userCanBulkPay) {
      foreach ($otherBranchTotals as $obId => $totals) {
          $net = $totals['net'];
          if ($net > 0.0001) {
              $otherName = $branchNames[$obId] ?? ('Branch #' . $obId);
              ?>
              <div class="modal fade" id="settleModal<?php echo (int)$obId; ?>" tabindex="-1" aria-labelledby="settleModalLabel<?php echo (int)$obId; ?>" aria-hidden="true">
                <div class="modal-dialog">
                  <div class="modal-content">
                    <form method="post">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="other_branch_id" value="<?php echo (int)$obId; ?>">
                      <input type="hidden" name="settle_pair" value="1">
                      <div class="modal-header">
                        <h5 class="modal-title" id="settleModalLabel<?php echo (int)$obId; ?>">Settle with <?php echo htmlspecialchars($otherName); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <p>You owe <strong>Rs. <?php echo number_format($net, 2); ?></strong> to <?php echo htmlspecialchars($otherName); ?>.</p>
                        <div class="mb-3">
                          <label class="form-label">Payment Method</label>
                          <select name="payment_method" class="form-select payment-method-select">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank Transfer</option>
                          </select>
                        </div>

                        <div class="mb-3 payer-bank-group d-none">
                          <label class="form-label">Your Bank (<?php echo htmlspecialchars($filterBranchName); ?>)</label>
                          <select name="payer_bank_id" class="form-select">
                            <?php if (isset($bankAccountsByBranch[$filter_branch_id])): ?>
                              <?php foreach ($bankAccountsByBranch[$filter_branch_id] as $bk): ?>
                                <option value="<?php echo (int)$bk['id']; ?>">
                                  <?php echo htmlspecialchars($bk['name']); ?> (Balance: Rs. <?php echo number_format($bk['opening_balance'], 2); ?>)
                                </option>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <option value="">No bank accounts</option>
                            <?php endif; ?>
                          </select>
                        </div>

                        <div class="mb-3 receiver-bank-group d-none">
                          <label class="form-label">Receiver Bank (<?php echo htmlspecialchars($otherName); ?>)</label>
                          <select name="receiver_bank_id" class="form-select">
                            <?php if (isset($bankAccountsByBranch[$obId])): ?>
                              <?php foreach ($bankAccountsByBranch[$obId] as $bk): ?>
                                <option value="<?php echo (int)$bk['id']; ?>">
                                  <?php echo htmlspecialchars($bk['name']); ?> (Balance: Rs. <?php echo number_format($bk['opening_balance'], 2); ?>)
                                </option>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <option value="">No bank accounts</option>
                            <?php endif; ?>
                          </select>
                        </div>

                        <div class="mb-3">
                          <label class="form-label">Amount to Pay (max <?php echo number_format($net, 2); ?>)</label>
                          <input type="number" name="amount" class="form-control"
                                 step="0.01" min="0.01"
                                 max="<?php echo number_format($net, 2, '.', ''); ?>"
                                 value="<?php echo number_format($net, 2, '.', ''); ?>" required>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Record Payment</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <?php
          }
      }
  }
  ?>

  <?php if ($user['role_name'] === 'admin'): ?>
    <form method="get" class="mb-3 row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Filter by Branch</label>
        <select name="branch_id" class="form-select" onchange="this.form.submit()">
          <option value="">All Branches</option>
          <?php foreach ($allBranches as $br): ?>
            <option value="<?php echo (int)$br['id']; ?>" <?php echo ($filter_branch_id && $filter_branch_id == $br['id'] ? 'selected' : ''); ?>>
              <?php echo htmlspecialchars($br['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($filter_branch_id && !empty($otherBranchTotals)): ?>
    <div class="mb-4">
      <h5>Outstanding with Other Branches</h5>
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead class="table-light">
            <tr>
              <th>Other Branch</th>
              <th>Net Difference</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($otherBranchTotals as $obId => $totals): ?>
            <?php $otherName = $branchNames[$obId] ?? ('Branch #' . $obId); $net = $totals['net']; ?>
            <tr>
              <td><?php echo htmlspecialchars($otherName); ?></td>
              <td>
                <?php if ($net > 0.0001): ?>
                  <span class="text-danger">You owe Rs. <?php echo number_format($net, 2); ?></span>
                <?php elseif ($net < -0.0001): ?>
                  <span class="text-success">You are owed Rs. <?php echo number_format(abs($net), 2); ?></span>
                <?php else: ?>
                  <span class="text-muted">Settled</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($net > 0.0001 && isset($userCanBulkPay) && $userCanBulkPay): ?>
                  <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#settleModal<?php echo (int)$obId; ?>">Settle</button>
                <?php else: ?>
                  <span class="text-muted">--</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-end mb-2">
    <div class="me-2">
      <label for="statusFilter" class="form-label mb-0">Status:</label>
      <select id="statusFilter" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="payable">Payable</option>
        <option value="receivable">Receivable</option>
        <option value="pending">Pending</option>
        <option value="settled">Settled</option>
      </select>
    </div>
  </div>

  <div class="table-responsive">
    <table id="transferTable" class="table table-bordered table-striped">
      <thead>
        <tr>
          <th>ID</th>
          <th>Date</th>
          <th>From Branch</th>
          <th>To Branch</th>
          <th>Total Cost</th>
          <th>Total Paid</th>
          <th>Outstanding</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($transfers as $tr): ?>
        <?php
          $rowOutVal = max(0, $tr['total_cost'] - $tr['total_paid']);
          $rowStatus = 'Settled';
          if ($rowOutVal > 0.01) {
              if ($filter_branch_id) {
                  if ((int)$tr['to_branch_id'] === $filter_branch_id)      $rowStatus = 'Payable';
                  elseif ((int)$tr['from_branch_id'] === $filter_branch_id) $rowStatus = 'Receivable';
                  else                                                      $rowStatus = 'Pending';
              } else {
                  $rowStatus = 'Pending';
              }
          }
        ?>
        <?php if ($rowOutVal > 0.01): ?>
          <tr data-status="<?php echo strtolower($rowStatus); ?>">
            <td><?php echo $tr['id']; ?></td>
            <td><?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($tr['transferred_at']))); ?></td>
            <td><?php echo htmlspecialchars($tr['from_branch']); ?></td>
            <td><?php echo htmlspecialchars($tr['to_branch']); ?></td>
            <td><?php echo number_format($tr['total_cost'], 2); ?></td>
            <td><?php echo number_format($tr['total_paid'], 2); ?></td>
            <td><?php echo number_format($rowOutVal, 2); ?></td>
            <td><?php echo htmlspecialchars($rowStatus); ?></td>
            <td>
              <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo (int)$tr['id']; ?>">View</button>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
// View modals per transfer
foreach ($transfers as $viewTr) {
    $tid = (int)$viewTr['id'];
    $itemsStmt = $pdo->prepare(
        "SELECT sti.quantity, sb.batch_no, sb.cost_price, p.name AS product_name
         FROM stock_transfer_items sti
         JOIN stock_batches sb ON sb.id = sti.batch_id
         JOIN products p ON p.id = sb.product_id
         WHERE sti.transfer_id = ?"
    );
    $itemsStmt->execute([$tid]);
    $items = $itemsStmt->fetchAll();
    ?>
    <div class="modal fade" id="viewModal<?php echo $tid; ?>" tabindex="-1" aria-labelledby="viewModalLabel<?php echo $tid; ?>" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="viewModalLabel<?php echo $tid; ?>">Transfer #<?php echo $tid; ?> Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if (!empty($items)) { ?>
              <div class="table-responsive">
                <table class="table table-sm table-bordered">
                  <thead class="table-light">
                    <tr>
                      <th>Product</th>
                      <th>Batch</th>
                      <th>Quantity</th>
                      <th>Cost Price</th>
                      <th>Total Cost</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($items as $it) { ?>
                      <tr>
                        <td><?php echo htmlspecialchars($it['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($it['batch_no']); ?></td>
                        <td><?php echo (int)$it['quantity']; ?></td>
                        <td><?php echo number_format($it['cost_price'], 2); ?></td>
                        <td><?php echo number_format($it['quantity'] * $it['cost_price'], 2); ?></td>
                      </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            <?php } else { ?>
              <p>No items found for this transfer.</p>
            <?php } ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>
<?php } ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function() {
  var table = $('#transferTable').DataTable();
  $('#statusFilter').on('change', function() {
    var val = $(this).val();
    table.column(7).search(val).draw();
  });

  // Toggle bank selectors when payment method changes inside each modal
  document.querySelectorAll('.modal').forEach(function(modal){
    var pmSelect = modal.querySelector('select[name="payment_method"]');
    if (!pmSelect) return;
    var payerGroup = modal.querySelector('.payer-bank-group');
    var receiverGroup = modal.querySelector('.receiver-bank-group');
    function toggle() {
      if (pmSelect.value === 'bank') {
        payerGroup && payerGroup.classList.remove('d-none');
        receiverGroup && receiverGroup.classList.remove('d-none');
      } else {
        payerGroup && payerGroup.classList.add('d-none');
        receiverGroup && receiverGroup.classList.add('d-none');
      }
    }
    pmSelect.addEventListener('change', toggle);
    toggle();
  });

  // Prevent double submit
  const btns = document.querySelectorAll('button[type="submit"].btn-primary');
  btns.forEach(btn => {
    btn.addEventListener('click', function(e){
      if (btn.dataset.locked === '1') { e.preventDefault(); return false; }
      btn.dataset.locked = '1';
      const oldText = btn.innerText;
      btn.innerText = 'Processing...';
      setTimeout(()=>{ btn.dataset.locked='0'; btn.innerText = oldText; }, 5000);
    });
  });
});
</script>
</body>
</html>
