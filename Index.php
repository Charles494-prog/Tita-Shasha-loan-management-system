<?php
// Ragnarr Agrivet Trading - Loan Management. XAMPP / localhost. Start Apache + MySQL, open http://localhost/Tita_Loan_Management_Website/
// The database and tables are created automatically on first run.
const DB_HOST = '127.0.0.1', DB_USER = 'root', DB_PASS = '', DB_NAME = 'loan_management';
date_default_timezone_set('Asia/Manila');
ini_set('display_errors','0');

function db() {
    static $p;
    if (!$p) {
        $p = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $p->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4");
        $p->exec("USE " . DB_NAME);
        $p->exec("CREATE TABLE IF NOT EXISTS clients (id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $p->exec("CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY,
            tid VARCHAR(100) NOT NULL UNIQUE, client_id INT NOT NULL, amt DECIMAL(12,2) NOT NULL,
            trx_date DATE NOT NULL, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id))");
        // My Loans: money the owner owes. Paid amount is never stored, it is the sum of my_loan_payments.
        $p->exec("CREATE TABLE IF NOT EXISTS my_loans (id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL, amt DECIMAL(12,2) NOT NULL, loan_date DATE NULL, reason TEXT NULL,
            encoded_at DATETIME NOT NULL, settled_at DATETIME NULL, INDEX (name))");
        $p->exec("CREATE TABLE IF NOT EXISTS my_loan_payments (id INT AUTO_INCREMENT PRIMARY KEY,
            loan_id INT NOT NULL, amt DECIMAL(12,2) NOT NULL, paid_at DATETIME NOT NULL,
            FOREIGN KEY (loan_id) REFERENCES my_loans(id) ON DELETE CASCADE)");
        // Receipt numbers: the loan's receipt (required for new loans) and the collection receipt of each settlement.
        if (!$p->query("SHOW COLUMNS FROM my_loans LIKE 'receipt_no'")->fetch()) $p->exec("ALTER TABLE my_loans ADD COLUMN receipt_no VARCHAR(100) NULL");
        if (!$p->query("SHOW COLUMNS FROM my_loan_payments LIKE 'collection_receipt'")->fetch()) $p->exec("ALTER TABLE my_loan_payments ADD COLUMN collection_receipt VARCHAR(100) NULL");
        $col = $p->query("SHOW COLUMNS FROM transactions LIKE 'trx_date'")->fetch();
        if ($col && stripos($col['Type'], 'datetime') !== false) $p->exec("ALTER TABLE transactions MODIFY trx_date DATE NOT NULL");
    }
    return $p;
}
// Balances are never stored: they are always the sum of transactions (loan = negative, payment = positive),
// so editing or deleting a transaction automatically corrects the balance.
function balance($cid) {
    $s = db()->prepare("SELECT COALESCE(SUM(amt),0) FROM transactions WHERE client_id=?");
    $s->execute([$cid]);
    return round((float)$s->fetchColumn(), 2);
}
function php($x) { return ($x < 0 ? '-' : '') . '₱' . number_format(abs($x), 2); }
// A payment may never push the balance above zero, at any point in the history.
function check($cid) {
    $s = db()->prepare("SELECT tid,amt FROM transactions WHERE client_id=? ORDER BY trx_date,id");
    $s->execute([$cid]);
    $b = 0;
    foreach ($s as $t) {
        $b = round($b + $t['amt'], 2);
        if ($b > 0.004) throw new Exception("That would leave the balance above zero at transaction #{$t['tid']}. Payments can't be more than the loan owed.");
    }
}
function normName($s) {
    $s = trim($s);
    $s = str_replace([' _', '_ '], '_', $s);
    $s = preg_replace('/ {2,}/', ' ', $s);
    return preg_replace_callback("/(^|[ \-'_.])(\p{L})/u", fn($m) => $m[1] . mb_strtoupper($m[2]), mb_strtolower($s));
}
function mkDate($d) {
    if (!preg_match('/^(\d{4})-(\d\d)-(\d\d)$/', (string)$d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))
        throw new Exception("That date doesn't exist. Check the date.");
    return $d;
}
function readTrx($in, $editId = 0) {
    $tid = trim($in['tid'] ?? '');
    if ($tid === '') throw new Exception('Transaction ID is required.');
    $s = db()->prepare("SELECT id FROM transactions WHERE tid=? AND id<>?");
    $s->execute([$tid, $editId]);
    if ($s->fetch()) throw new Exception("Transaction ID '$tid' already exists. Enter a different one.");
    if (!in_array($in['type'] ?? '', ['loan', 'pay'])) throw new Exception('Choose Loan or Payment.');
    $a = trim((string)($in['amount'] ?? ''));
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $a) || (float)$a <= 0) throw new Exception('Amount must be greater than 0, with up to 2 decimal places.');
    $a = round((float)$a, 2);
    return [$tid, $in['type'] === 'loan' ? -$a : $a, mkDate($in['date'] ?? ''), trim($in['notes'] ?? '')];
}

function myAmt($a) {
    $a = str_replace(',', '', trim((string)$a));
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $a) || (float)$a <= 0) throw new Exception('Amount must be greater than 0, with up to 2 decimal places.');
    return (int)round((float)$a * 100);   // work in centavos to avoid rounding errors
}
// Unpaid loans (optionally one loan / one name), oldest first: date borrowed, else time encoded. Locked for update.
function myOpen($where, $args) {
    $s = db()->prepare("SELECT l.id, ROUND(l.amt*100) - COALESCE((SELECT ROUND(SUM(p.amt)*100) FROM my_loan_payments p WHERE p.loan_id=l.id),0) lft
        FROM my_loans l WHERE l.settled_at IS NULL AND $where ORDER BY COALESCE(CAST(l.loan_date AS DATETIME), l.encoded_at), l.id FOR UPDATE");
    $s->execute($args);
    return $s->fetchAll();
}
function myCr($in) {   // collection receipt number, required when settling
    $cr = preg_replace('/\s+/', ' ', trim((string)($in['cr'] ?? '')));
    if ($cr === '') throw new Exception('Enter the collection receipt number.');
    if (mb_strlen($cr) > 100) throw new Exception('Collection receipt number is too long (100 characters max).');
    return $cr;
}
function myPay($id, $cents, $left, $now, $cr) {
    db()->prepare("INSERT INTO my_loan_payments(loan_id,amt,paid_at,collection_receipt) VALUES(?,?,?,?)")->execute([$id, $cents / 100, $now, $cr]);
    if ($cents >= $left) db()->prepare("UPDATE my_loans SET settled_at=? WHERE id=?")->execute([$now, $id]);
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        $pdo = db();
        switch ($_GET['api']) {
            case 'clients':
                $out = $pdo->query("SELECT c.id,c.name,COALESCE(SUM(t.amt),0) bal,COUNT(t.id) n FROM clients c
                    LEFT JOIN transactions t ON t.client_id=c.id GROUP BY c.id ORDER BY c.name")->fetchAll();
                foreach ($out as &$c) { $c['id'] = (int)$c['id']; $c['bal'] = (float)$c['bal']; $c['n'] = (int)$c['n']; }
                echo json_encode(['ok' => true, 'clients' => $out]);
                break;
            case 'addClient':
                $n = normName($in['name'] ?? '');
                if ($n === '') throw new Exception("Enter the client's full name.");
                if (preg_match('/(^|[\s._])(alias|aka|a k a)([\s._]|$)/i', $n)) throw new Exception("Enter the client's real full name, not an alias.");
                $base = explode('_', $n)[0];
                if (strpos(trim($base), ' ') === false || !preg_match('/^[A-Za-z .\'\-_]+$/', $n))
                    throw new Exception('Enter the full name (first and last name). Only letters, spaces, hyphens, apostrophes, periods and underscores are allowed.');
                $s = $pdo->prepare("SELECT 1 FROM clients WHERE name=?");
                $s->execute([$n]);
                if ($s->fetch()) throw new Exception("A client named '$n' already exists. Add a detail to tell them apart, like {$n}_Marikina.");
                $pdo->prepare("INSERT INTO clients(name) VALUES(?)")->execute([$n]);
                echo json_encode(['ok' => true, 'name' => $n]);
                break;
            case 'dashboard':
                $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN b<0 THEN -b END),0) owed, COALESCE(SUM(b<0),0) owing
                    FROM (SELECT c.id, COALESCE(SUM(t.amt),0) b FROM clients c LEFT JOIN transactions t ON t.client_id=c.id GROUP BY c.id) x")->fetch();
                $tot = $pdo->query("SELECT COALESCE(SUM(CASE WHEN amt<0 THEN -amt END),0) loaned, COALESCE(SUM(CASE WHEN amt>0 THEN amt END),0) paid, COUNT(*) n FROM transactions")->fetch();
                $cp = $pdo->query("SELECT id,name,loaned,paid FROM (
                        SELECT c.id,c.name, COALESCE(SUM(CASE WHEN t.amt<0 THEN -t.amt END),0) loaned, COALESCE(SUM(CASE WHEN t.amt>0 THEN t.amt END),0) paid
                        FROM clients c JOIN transactions t ON t.client_id=c.id GROUP BY c.id,c.name) x
                    ORDER BY (loaned-paid) DESC, name LIMIT 8")->fetchAll();
                foreach ($cp as &$c) { $c['id'] = (int)$c['id']; $c['loaned'] = (float)$c['loaned']; $c['paid'] = (float)$c['paid']; }
                unset($c);
                $rec = $pdo->query("SELECT t.tid,c.id client_id,c.name,t.amt,t.trx_date FROM transactions t JOIN clients c ON c.id=t.client_id
                    ORDER BY t.trx_date DESC,t.id DESC LIMIT 8")->fetchAll();
                $st = $pdo->query("SELECT COALESCE(SUM(n=0),0) fresh, COALESCE(SUM(n>0 AND b<-0.004),0) owing, COALESCE(SUM(n>0 AND b>=-0.004),0) settled
                    FROM (SELECT c.id, COUNT(t.id) n, COALESCE(SUM(t.amt),0) b FROM clients c LEFT JOIN transactions t ON t.client_id=c.id GROUP BY c.id) x")->fetch();
                // last 12 months: loaned, paid, and the total still owed at each month end
                $mr = $pdo->query("SELECT DATE_FORMAT(trx_date,'%Y-%m') ym, COALESCE(SUM(CASE WHEN amt<0 THEN -amt END),0) l, COALESCE(SUM(CASE WHEN amt>0 THEN amt END),0) p
                    FROM transactions GROUP BY ym ORDER BY ym")->fetchAll();
                $by = []; foreach ($mr as $m) $by[$m['ym']] = $m;
                $d0 = (new DateTime('first day of this month'))->modify('-11 months'); $sk = $d0->format('Y-m'); $cum = 0;
                foreach ($mr as $m) if ($m['ym'] < $sk) $cum += $m['l'] - $m['p'];
                $months = [];
                for ($i = 0; $i < 12; $i++, $d0->modify('+1 month')) {
                    $k = $d0->format('Y-m'); $l = (float)($by[$k]['l'] ?? 0); $p = (float)($by[$k]['p'] ?? 0); $cum += $l - $p;
                    $months[] = ['ym' => $k, 'loaned' => round($l, 2), 'paid' => round($p, 2), 'owed' => round($cum, 2)];
                }
                echo json_encode(['ok' => true, 'clients' => (int)$r['n'], 'owing' => (int)$r['owing'], 'owed' => (float)$r['owed'],
                    'loaned' => (float)$tot['loaned'], 'paid' => (float)$tot['paid'], 'trx' => (int)$tot['n'], 'cp' => $cp, 'recent' => $rec,
                    'status' => ['owing' => (int)$st['owing'], 'settled' => (int)$st['settled'], 'fresh' => (int)$st['fresh']], 'months' => $months]);
                break;
            case 'history':
                $cid = (int)($in['client_id'] ?? 0);               // 0 = all clients
                $from = $in['from'] ?? ''; $to = $in['to'] ?? '';
                $desc = ($in['order'] ?? 'asc') === 'desc';
                foreach ([$from, $to] as $x) if ($x !== '' && !preg_match('/^\d{4}-\d\d-\d\d$/', $x)) throw new Exception('Invalid date range.');
                if ($from !== '' && $to !== '' && $from > $to) throw new Exception('The "from" date is after the "to" date.');
                $name = 'All clients';
                if ($cid) {
                    $s = $pdo->prepare("SELECT name FROM clients WHERE id=?");
                    $s->execute([$cid]);
                    $name = $s->fetchColumn();
                    if ($name === false) throw new Exception('Client not found.');
                }
                // running balances are computed over each client's FULL history, then the range filter is applied
                $s = $pdo->query("SELECT t.id,t.tid,t.client_id,c.name,t.amt,t.trx_date,t.notes FROM transactions t
                    JOIN clients c ON c.id=t.client_id ORDER BY t.trx_date,t.id");
                $run = []; $rows = []; $loan = 0; $pay = 0; $tloan = 0; $tpay = 0;
                foreach ($s as $t) {
                    $k = (int)$t['client_id'];
                    $before = $run[$k] ?? 0;
                    $run[$k] = round($before + $t['amt'], 2);
                    if ($cid && $k !== $cid) continue;
                    if ($t['amt'] > 0) $tpay += $t['amt']; else $tloan -= $t['amt'];
                    $d = substr($t['trx_date'], 0, 10);
                    if (($from !== '' && $d < $from) || ($to !== '' && $d > $to)) continue;
                    if ($t['amt'] > 0) $pay += $t['amt']; else $loan -= $t['amt'];
                    $rows[] = ['id' => (int)$t['id'], 'tid' => $t['tid'], 'client_id' => $k, 'client' => $t['name'], 'amt' => (float)$t['amt'],
                               'date' => $d, 'notes' => $t['notes'], 'before' => $before, 'after' => $run[$k]];
                }
                if ($desc) $rows = array_reverse($rows);
                echo json_encode(['ok' => true, 'name' => $name, 'rows' => $rows, 'bal' => round($cid ? ($run[$cid] ?? 0) : array_sum($run), 2),
                                  'asof' => date('Y-m-d'), 'loan' => round($loan, 2), 'pay' => round($pay, 2), 'tloan' => round($tloan, 2), 'tpay' => round($tpay, 2)]);
                break;
            case 'addTrx':
            case 'updateTrx':
                $edit = $_GET['api'] === 'updateTrx';
                $id = $edit ? (int)$in['id'] : 0;
                $pdo->beginTransaction();
                if ($edit) {
                    $s = $pdo->prepare("SELECT client_id FROM transactions WHERE id=?");
                    $s->execute([$id]);
                    $cid = $s->fetchColumn();
                    if (!$cid) throw new Exception('Transaction not found.');
                } else {
                    $cid = (int)($in['client_id'] ?? 0);
                    $s = $pdo->prepare("SELECT 1 FROM clients WHERE id=?");
                    $s->execute([$cid]);
                    if (!$s->fetch()) throw new Exception('Select a client.');
                }
                [$tid, $amt, $dt, $notes] = readTrx($in, $id);
                if (!$edit && $amt > 0) {
                    $owed = -balance($cid);
                    if ($amt > $owed + 0.004) {
                        $who = $pdo->prepare("SELECT name FROM clients WHERE id=?"); $who->execute([$cid]);
                        throw new Exception("Cannot save: " . $who->fetchColumn() . " only owes " . php($owed) . ". A payment of " . php($amt) . " is more than what is owed.");
                    }
                }
                if ($edit) $pdo->prepare("UPDATE transactions SET tid=?,amt=?,trx_date=?,notes=? WHERE id=?")->execute([$tid, $amt, $dt, $notes, $id]);
                else $pdo->prepare("INSERT INTO transactions(tid,client_id,amt,trx_date,notes) VALUES(?,?,?,?,?)")->execute([$tid, $cid, $amt, $dt, $notes]);
                check($cid);
                $pdo->commit();
                echo json_encode(['ok' => true, 'bal' => balance($cid)]);
                break;
            case 'deleteTrx':
                $id = (int)$in['id'];
                $pdo->beginTransaction();
                $s = $pdo->prepare("SELECT client_id FROM transactions WHERE id=?");
                $s->execute([$id]);
                $cid = $s->fetchColumn();
                if (!$cid) throw new Exception('Transaction not found.');
                $pdo->prepare("DELETE FROM transactions WHERE id=?")->execute([$id]);
                try { check($cid); } catch (Exception $e) { throw new Exception("Can't delete this transaction. " . $e->getMessage()); }
                $pdo->commit();
                echo json_encode(['ok' => true, 'bal' => balance($cid)]);
                break;
            case 'restoreTrx':   // undo a delete: re-insert the row with its original id
                $id = (int)($in['id'] ?? 0); $cid = (int)($in['client_id'] ?? 0);
                $pdo->beginTransaction();
                $s = $pdo->prepare("SELECT 1 FROM clients WHERE id=?"); $s->execute([$cid]);
                if (!$s->fetch()) throw new Exception('Client not found.');
                $s = $pdo->prepare("SELECT 1 FROM transactions WHERE id=?"); $s->execute([$id]);
                if ($s->fetch()) throw new Exception('This transaction was already restored.');
                [$tid, $amt, $dt, $notes] = readTrx($in, $id);
                $pdo->prepare("INSERT INTO transactions(id,tid,client_id,amt,trx_date,notes) VALUES(?,?,?,?,?,?)")->execute([$id, $tid, $cid, $amt, $dt, $notes]);
                check($cid);
                $pdo->commit();
                echo json_encode(['ok' => true, 'bal' => balance($cid)]);
                break;
            case 'myLoans':
                $out = $pdo->query("SELECT l.id,l.name,l.amt,l.loan_date,l.reason,l.encoded_at,l.settled_at,l.receipt_no,
                    COALESCE((SELECT SUM(p.amt) FROM my_loan_payments p WHERE p.loan_id=l.id),0) paid,
                    (SELECT GROUP_CONCAT(DISTINCT p.collection_receipt ORDER BY p.id SEPARATOR ', ') FROM my_loan_payments p WHERE p.loan_id=l.id) crs FROM my_loans l")->fetchAll();
                $f = fn($d) => date('F j, Y: g:iA', strtotime($d));
                $out = array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name'], 'amt' => (float)$l['amt'], 'paid' => (float)$l['paid'],
                    'date' => $l['loan_date'] ?? '', 'reason' => $l['reason'] ?? '', 'receipt' => $l['receipt_no'] ?? '', 'crs' => $l['crs'] ?? '',
                    'at' => date('Y-m-d\TH:i:s', strtotime($l['encoded_at'])), 'encoded' => $f($l['encoded_at']),
                    'settledAt' => $l['settled_at'] ? date('Y-m-d\TH:i:s', strtotime($l['settled_at'])) : '',
                    'settled' => $l['settled_at'] ? $f($l['settled_at']) : ''], $out);
                echo json_encode(['ok' => true, 'loans' => $out]);
                break;
            case 'addMyLoan':
                $n = preg_replace('/\s+/', ' ', trim($in['name'] ?? ''));
                if ($n === '') throw new Exception('Enter who you owe.');
                if (mb_strlen($n) > 150) throw new Exception('Name is too long (150 characters max).');
                $s = $pdo->prepare("SELECT name FROM my_loans WHERE name=? LIMIT 1");   // reuse the existing spelling
                $s->execute([$n]);
                $n = $s->fetchColumn() ?: $n;
                $c = myAmt($in['amount'] ?? '');
                $d = trim($in['date'] ?? '');
                $d = $d === '' ? null : mkDate($d);
                $r = trim($in['reason'] ?? '');
                $rn = preg_replace('/\s+/', ' ', trim((string)($in['receipt'] ?? '')));
                if ($rn === '') throw new Exception('Enter the receipt number.');
                if (mb_strlen($rn) > 100) throw new Exception('Receipt number is too long (100 characters max).');
                $s = $pdo->prepare("SELECT name FROM my_loans WHERE receipt_no=? LIMIT 1");
                $s->execute([$rn]);
                if ($o = $s->fetchColumn()) throw new Exception("Receipt number $rn is already used (loan from $o). Enter a different one.");
                $pdo->prepare("INSERT INTO my_loans(name,amt,loan_date,reason,encoded_at,receipt_no) VALUES(?,?,?,?,?,?)")
                    ->execute([$n, $c / 100, $d, $r === '' ? null : $r, date('Y-m-d H:i:s'), $rn]);
                echo json_encode(['ok' => true]);
                break;
            case 'settleMyLoan':
                $cr = myCr($in);
                $pdo->beginTransaction();
                $l = myOpen('l.id=?', [(int)($in['id'] ?? 0)]);
                if (!$l) throw new Exception('This loan was not found or is already settled.');
                myPay($l[0]['id'], (int)$l[0]['lft'], (int)$l[0]['lft'], date('Y-m-d H:i:s'), $cr);
                $pdo->commit();
                echo json_encode(['ok' => true]);
                break;
            case 'customSettle':   // pays the oldest unpaid loans of one person first
                $n = preg_replace('/\s+/', ' ', trim($in['name'] ?? ''));
                $c = myAmt($in['amount'] ?? '');
                $cr = myCr($in);
                $pdo->beginTransaction();
                $open = myOpen('l.name=?', [$n]);
                $owed = array_sum(array_column($open, 'lft'));
                if (!$owed) throw new Exception($n === '' ? 'Enter who you paid.' : "You have no unpaid loans to $n.");
                if ($c > $owed) throw new Exception("That's more than you owe $n (" . php($owed / 100) . ").");
                $now = date('Y-m-d H:i:s');
                foreach ($open as $l) {
                    if ($c <= 0) break;
                    $p = min($c, (int)$l['lft']);
                    myPay($l['id'], $p, (int)$l['lft'], $now, $cr);
                    $c -= $p;
                }
                $pdo->commit();
                echo json_encode(['ok' => true]);
                break;
            case 'deleteMyLoan':
                $pdo->prepare("DELETE FROM my_loans WHERE id=?")->execute([(int)($in['id'] ?? 0)]);
                echo json_encode(['ok' => true]);
                break;
            default:
                throw new Exception('Unknown action.');
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ragnarr Agrivet Trading - Loan Management</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root{--primary:#1e6b45;--primary-h:#17583a;--primary-t:#e6f2ec;--bg:#f5f6f3;--surface:#fff;--ink:#1d2420;--ink2:#444d47;--mute:#66706a;--line:#e2e6e1;--line2:#c9d0ca;
--bad:#b3261e;--bad-t:#fdecea;--warn:#8a5300;--warn-t:#fff3dc;--s1:4px;--s2:8px;--s3:12px;--s4:16px;--s5:24px;--s6:32px;--r:8px;--shadow:0 1px 2px rgba(20,30,25,.06)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 "Segoe UI",system-ui,-apple-system,Helvetica,Arial,sans-serif;font-variant-numeric:tabular-nums}
h1,h2,h3,p{margin:0}
.wrap{max-width:1160px;margin:0 auto;padding:0 var(--s5)}
body.wide .wrap{max-width:none}
.i{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
/* header */
.top{background:var(--surface);border-bottom:1px solid var(--line)}
.top-in{display:flex;align-items:center;justify-content:space-between;gap:var(--s4);padding-top:var(--s4);padding-bottom:var(--s4);flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:var(--s4)}
.mark{position:relative;overflow:hidden;flex:none;width:68px;height:68px;border-radius:50%;background:var(--primary);color:#fff;display:grid;place-items:center;border:3px solid var(--primary-t);box-shadow:0 0 0 1px var(--line2)}.mark .i{width:32px;height:32px}.mark img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:50% 20%}
.brand h1{font-size:22px;line-height:1.2;font-weight:700;letter-spacing:-.01em}
.brand p{color:var(--mute);font-size:13.5px;line-height:1.4}.brand p b{color:var(--ink2);font-weight:600}
.app-tag{font-size:13px;color:var(--mute);border:1px solid var(--line);border-radius:99px;padding:4px 12px}
.tabs{position:sticky;top:0;z-index:20;background:var(--surface);border-bottom:1px solid var(--line)}
.tabs-in{display:flex;gap:var(--s2);overflow-x:auto}
.tab{display:flex;align-items:center;gap:var(--s2);border:0;background:none;color:var(--mute);font:600 15px inherit;font-family:inherit;padding:var(--s3) var(--s4);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-1px;white-space:nowrap}
.tab:hover{color:var(--ink)}.tab.on{color:var(--primary);border-bottom-color:var(--primary)}
main{padding:var(--s5) 0 64px}
.panel{display:none}.panel.on{display:block}
.ph{display:flex;justify-content:space-between;align-items:flex-end;gap:var(--s4);flex-wrap:wrap;margin-bottom:var(--s5)}
.ph h2{font-size:24px;line-height:1.25;letter-spacing:-.01em}.ph p{color:var(--mute);margin-top:var(--s1)}
.acts{display:flex;gap:var(--s2);flex-wrap:wrap}
/* controls */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:var(--s2);border:1px solid var(--line2);background:var(--surface);color:var(--ink);padding:9px 16px;border-radius:var(--r);font:600 14.5px inherit;font-family:inherit;cursor:pointer}
.btn:hover{background:var(--bg)}.btn.pri{background:var(--primary);border-color:var(--primary);color:#fff}.btn.pri:hover{background:var(--primary-h)}
.btn.bad{background:var(--bad);border-color:var(--bad);color:#fff}.btn.bad:hover{background:#971f18}.btn:disabled{opacity:.6;cursor:default}
.ib{width:36px;height:36px;display:inline-grid;place-items:center;border:0;background:none;color:var(--mute);border-radius:var(--r);cursor:pointer}
.ib:hover{background:var(--primary-t);color:var(--primary)}.ib.danger:hover{background:var(--bad-t);color:var(--bad)}
:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
label,.lbl{display:block;font-weight:600;font-size:14px;margin-bottom:6px}.lbl em,label em{font-weight:400;font-style:normal;color:var(--mute);margin-left:6px}
input,select,textarea{width:100%;padding:10px 12px;border:1px solid var(--line2);border-radius:var(--r);font:inherit;background:var(--surface);color:var(--ink)}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-t)}
select:disabled{background:var(--bg);color:var(--ink2)}textarea{min-height:80px;resize:vertical}
.fld{margin-bottom:var(--s4)}.g2{display:grid;grid-template-columns:1fr 1fr;gap:var(--s4)}.hint{color:var(--mute);font-size:13.5px;margin-top:6px}
.adorn{display:flex;align-items:stretch}.adorn span{display:grid;place-items:center;padding:0 14px;border:1px solid var(--line2);border-right:0;border-radius:var(--r) 0 0 var(--r);background:var(--bg);color:var(--ink2);font-weight:600}
.adorn input{border-radius:0 var(--r) var(--r) 0}
.seg{display:grid;grid-template-columns:1fr 1fr;gap:var(--s3);margin-bottom:var(--s4)}
.seg label{margin:0;border:1px solid var(--line2);border-radius:var(--r);padding:var(--s3) var(--s4);cursor:pointer;display:flex;gap:var(--s3);align-items:flex-start;font-weight:600}
.seg label small{display:block;font-weight:400;color:var(--mute);font-size:13px}.seg input{width:auto;margin-top:4px;accent-color:var(--primary)}
.seg label:has(input:checked){border-color:var(--primary);background:var(--primary-t)}
.alert{display:flex;gap:var(--s3);align-items:flex-start;background:var(--bad-t);color:var(--bad);border:1px solid #f1b8b3;border-radius:var(--r);padding:var(--s3) var(--s4);margin-bottom:var(--s4);font-weight:500}
.alert[hidden]{display:none}.alert .i{margin-top:2px}
.preview{display:flex;align-items:center;gap:var(--s4);background:var(--bg);border-radius:var(--r);padding:var(--s3) var(--s4);flex-wrap:wrap}
.preview small{display:block;color:var(--mute);font-size:13px}.preview b{font-size:17px}.preview .arr{color:var(--mute)}.preview .warn{flex-basis:100%;color:var(--bad);font-size:13.5px;font-weight:600}
/* surfaces */
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow)}
.card-h{padding:var(--s4) var(--s5);border-bottom:1px solid var(--line)}.card-h h3{font-size:17px}.card-h p{color:var(--mute);font-size:13.5px}
.card-b{padding:var(--s5)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:var(--s4);margin-bottom:var(--s5)}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:var(--s4) var(--s5)}
.kpi small{display:block;color:var(--mute);font-size:13.5px}.kpi b{display:block;font-size:24px;line-height:1.3;margin-top:2px;letter-spacing:-.01em}
.kpi.main{background:var(--primary);border-color:var(--primary);color:#fff}.kpi.main small{color:#d3e8dc}
.two{display:grid;grid-template-columns:1fr 1fr;gap:var(--s5)}
.li{display:flex;justify-content:space-between;align-items:center;gap:var(--s4);padding:var(--s3) var(--s5);border-bottom:1px solid var(--line);cursor:pointer}
.li:last-child{border-bottom:0}.li:hover{background:var(--bg)}.li small{display:block;color:var(--mute);font-size:13px}
.tools{display:flex;gap:var(--s3);padding:var(--s4) var(--s5);border-bottom:1px solid var(--line);align-items:center}
.sbox{position:relative;flex:1;max-width:360px}.sbox .i{position:absolute;left:12px;top:11px;color:var(--mute);width:18px;height:18px}.sbox input{padding-left:38px}
.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:var(--s4);padding:var(--s5)}
.filters-f{display:flex;gap:var(--s2);flex-wrap:wrap;align-items:center;padding:0 var(--s5) var(--s5)}.filters-f span{color:var(--mute);font-size:13.5px;margin-right:var(--s1)}
.chip{border:1px solid var(--line2);background:var(--surface);color:var(--ink2);padding:5px 14px;border-radius:99px;cursor:pointer;font:500 13.5px inherit;font-family:inherit}.chip:hover{background:var(--bg)}
.exp-only{display:none;padding:var(--s5) var(--s5) 0}.exp-only b{font-size:20px;display:block}.exp-only span{display:block;color:var(--mute);font-size:13.5px}
.stmt-h{padding:var(--s5) var(--s5) var(--s4)}.stmt-h h3{font-size:18px}.stmt-h p{color:var(--mute);font-size:13.5px;margin-top:2px}
#exp .kpis{padding:0 var(--s5);margin-bottom:var(--s5)}
/* tables */
.tw{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:14.5px}
th,td{padding:var(--s4) var(--s5);text-align:left;border-top:1px solid var(--line);vertical-align:middle}
th{background:var(--bg);color:var(--mute);font-weight:600;font-size:13.5px;white-space:nowrap;padding-top:var(--s3);padding-bottom:var(--s3)}
tbody tr:hover td{background:#fafbf9}
.ledger{min-width:1080px}.ledger td,.ledger th{padding-left:var(--s4);padding-right:var(--s4)}.ledger th:first-child,.ledger td:first-child{padding-left:var(--s5)}.cl{white-space:nowrap}.op{display:inline-block;width:22px;text-align:left;color:var(--mute);font-weight:700}.num{text-align:right;white-space:nowrap}.nw{white-space:nowrap}.act{white-space:nowrap;text-align:right;padding-top:var(--s2);padding-bottom:var(--s2)}
.notes{min-width:180px;color:var(--ink2)}.tid{font-weight:600;white-space:nowrap}
.tag{display:inline-block;padding:2px 10px;border-radius:99px;font-size:13px;font-weight:600}.tag.l{background:var(--warn-t);color:var(--warn)}.tag.p{background:var(--primary-t);color:var(--primary)}
.pos{color:var(--primary);font-weight:600}.neg{color:var(--bad);font-weight:600}.zero,.mute{color:var(--mute)}
.who{display:flex;align-items:center;gap:var(--s3);font-weight:600}.av{width:34px;height:34px;border-radius:50%;background:var(--primary-t);color:var(--primary);display:grid;place-items:center;font-weight:700;flex:none}
.empty{display:grid;justify-items:center;gap:var(--s2);padding:var(--s6) var(--s5);color:var(--mute);text-align:center}.empty .i{width:32px;height:32px}
/* dialogs */
dialog{border:0;padding:0;border-radius:12px;width:calc(100% - 32px);max-width:540px;background:var(--surface);color:var(--ink);box-shadow:0 20px 48px rgba(10,20,15,.28)}
dialog.sm{max-width:440px}dialog::backdrop{background:rgba(20,30,25,.5)}
.dlg-h{display:flex;justify-content:space-between;gap:var(--s4);padding:var(--s5) var(--s5) var(--s3)}.dlg-h h3{font-size:20px}.dlg-h p{color:var(--mute);font-size:14px;margin-top:2px}
.dlg-b{padding:var(--s3) var(--s5) var(--s5);overflow:auto;max-height:calc(100dvh - 200px)}
.dlg-f{display:flex;justify-content:flex-end;gap:var(--s3);padding:var(--s4) var(--s5);border-top:1px solid var(--line);background:var(--bg);border-radius:0 0 12px 12px}
.cfm{display:flex;gap:var(--s4)}.cfm .ic{width:44px;height:44px;border-radius:50%;background:var(--bad-t);color:var(--bad);display:grid;place-items:center;flex:none}.cfm p{color:var(--ink2);margin-top:var(--s2)}
/* toasts + undo (top layer, so they always sit above open dialogs) */
#layer{inset:auto auto 24px 24px;margin:0;padding:0;border:0;background:transparent;overflow:visible;width:min(440px,calc(100vw - 32px));color:var(--ink)}
#layer>*{margin-top:var(--s3)}
.toast{display:flex;gap:var(--s3);align-items:flex-start;background:#222a25;color:#fff;border-radius:var(--r);padding:var(--s3) var(--s4);box-shadow:0 8px 24px rgba(0,0,0,.25)}.toast.err{background:var(--bad)}.toast .i{margin-top:2px}
.undo{background:#222a25;color:#fff;border-radius:10px;overflow:hidden;box-shadow:0 12px 32px rgba(0,0,0,.35)}
.u-body{display:flex;align-items:center;gap:var(--s3);padding:var(--s3) var(--s3) var(--s3) var(--s4)}
.u-body .ic{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.12);display:grid;place-items:center;flex:none}
.u-txt{flex:1;min-width:0}.u-txt b{display:block}.u-txt span{display:block;color:#b9c4bd;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.undo .btn{background:#fff;color:var(--ink);border-color:#fff;white-space:nowrap;flex:none}.undo .btn:hover{background:#e7f3ec}.undo .btn .i{vertical-align:-4px;margin-right:6px}
.undo .ib{color:#b9c4bd;flex:none}.undo .ib:hover{background:rgba(255,255,255,.14);color:#fff}
.u-bar{height:4px;background:#3a453e}.u-bar i{display:block;height:100%;background:#6fcf97;transform-origin:left;animation:drain 15s linear forwards}
#ulayer{inset:auto 0 24px 0;margin:0 auto;padding:0;border:0;background:transparent;overflow:visible;width:min(580px,calc(100vw - 32px));height:fit-content;color:var(--ink)}
#ulayer>*+*{margin-top:var(--s2)}
.rs{display:grid;justify-items:center;text-align:center;gap:var(--s2);padding-top:var(--s5)}
.ok-ic{width:52px;height:52px;border-radius:50%;background:var(--primary-t);color:var(--primary);display:grid;place-items:center}.ok-ic .i{width:28px;height:28px}
.rs h3{font-size:20px}.rs-d{width:100%;margin:var(--s3) 0 0;text-align:left;border:1px solid var(--line);border-radius:var(--r)}
.rs-d div{display:flex;justify-content:space-between;gap:var(--s4);padding:var(--s3) var(--s4);border-top:1px solid var(--line)}.rs-d div:first-child{border-top:0}.rs-d dt{color:var(--mute)}.rs-d dd{margin:0;font-weight:600;text-align:right;overflow-wrap:anywhere}
.dlg-f.fill .btn{flex:1}
.cb{position:relative}.cb input{padding-right:36px}.cb .chev{position:absolute;right:12px;top:12px;width:18px;height:18px;color:var(--mute);pointer-events:none}
.cb-list{position:fixed;z-index:50;margin:0;padding:var(--s1);list-style:none;background:var(--surface);border:1px solid var(--line2);border-radius:var(--r);box-shadow:0 8px 24px rgba(20,30,25,.18);max-height:240px;overflow:auto}
.cb-list[hidden]{display:none}.cb-list li{padding:9px 12px;border-radius:6px;cursor:pointer}.cb-list li.hi{background:var(--primary-t)}.cb-list li.sel{font-weight:600}.cb-list li.none{color:var(--mute);cursor:default}
@keyframes drain{to{transform:scaleX(0)}}
/* animated check */
.anim{width:80px;height:80px}.anim circle{fill:var(--primary-t);stroke:var(--primary);stroke-width:2.5;stroke-dasharray:151;stroke-dashoffset:151;animation:draw .55s ease forwards}
.anim path{fill:none;stroke:var(--primary);stroke-width:4;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:40;stroke-dashoffset:40;animation:draw .35s .5s ease forwards}
@keyframes draw{to{stroke-dashoffset:0}}@keyframes pop{from{transform:scale(.94);opacity:0}}
#rdlg[open]{animation:pop .22s ease}
/* repayment progress + bottom summary */
.prog{margin:0 var(--s5) var(--s5);padding:var(--s4) var(--s5);border:1px solid var(--line);border-radius:var(--r);background:var(--bg)}
.prog-top{display:flex;justify-content:space-between;align-items:flex-end;gap:var(--s4);flex-wrap:wrap}.prog small{display:block;color:var(--mute);font-size:13.5px}
.prog-amt{font-size:26px;line-height:1.3;font-weight:700;letter-spacing:-.01em}.prog-amt span{color:var(--mute);font-weight:500;font-size:20px}
.prog-pct{font-size:26px;line-height:1.3;font-weight:700;color:var(--primary)}
.bar{height:10px;border-radius:99px;background:var(--line2);overflow:hidden;margin-top:var(--s3)}.bar i{display:block;height:100%;background:var(--primary);border-radius:99px}
.sum{padding:var(--s5);border-top:1px solid var(--line)}.sum-box{margin-left:auto;max-width:480px}
.sum-r{display:flex;justify-content:space-between;gap:var(--s5);padding:6px 0;color:var(--ink2)}.sum-r b{color:var(--ink)}
.sum-r.total{margin-top:var(--s3);padding:var(--s3) var(--s4);border-top:2px solid var(--ink);background:var(--primary-t);border-radius:0 0 var(--r) var(--r);font-size:17px;font-weight:700;color:var(--ink);align-items:center}.sum-r.total b{color:var(--primary);font-size:24px}
.sum-note{text-align:right;color:var(--mute);font-size:13px;margin-top:var(--s2)}
/* dashboard */
#dHero{overflow:hidden}.prog-amt.big,.prog-pct.big{font-size:32px}
.owed-row{display:flex;justify-content:space-between;align-items:center;gap:var(--s4);padding:var(--s4) var(--s5);margin-top:var(--s5);border-top:2px solid var(--ink);background:var(--primary-t)}
.owed-row span{font-weight:700;font-size:17px;display:block}.owed-row small{display:block;color:var(--mute);font-size:13.5px}.owed-row b{font-size:32px;line-height:1.2;color:var(--primary);letter-spacing:-.01em}
.charts{display:grid;grid-template-columns:1fr 1fr;gap:var(--s5);margin-top:var(--s5)}
.ch{width:100%;height:auto;display:block}.ch text{fill:var(--mute);font-size:11px}.ch .gl{stroke:var(--line);stroke-width:1}
.legend{display:flex;gap:var(--s4);font-size:13px;color:var(--mute);margin-bottom:var(--s3)}.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px}
.cr{display:grid;gap:6px;width:100%;text-align:left;background:none;border:0;border-bottom:1px solid var(--line);padding:var(--s3) 0;font:inherit;color:inherit;cursor:pointer}.cr:last-child{border-bottom:0}.cr:hover .cr-top b{color:var(--primary)}
.cr-top{display:flex;justify-content:space-between;gap:var(--s3)}.cr small{color:var(--mute);font-size:13px}.bar.sm{height:8px;background:#f1d9a6;margin-top:0}
.donut{display:flex;align-items:center;gap:var(--s5);flex-wrap:wrap}.donut svg{width:170px;height:170px;flex:none}.donut .dn{font-size:8px;font-weight:700;fill:var(--ink)}.donut .dl{font-size:3.2px;fill:var(--mute)}
.donut ul{list-style:none;margin:0;padding:0;display:grid;gap:var(--s3);flex:1;min-width:180px}.donut li{display:flex;align-items:center;gap:var(--s3)}.donut li i{width:12px;height:12px;border-radius:3px;flex:none}.donut li span{flex:1}
@media(max-width:820px){.charts{grid-template-columns:1fr}.two{grid-template-columns:1fr}.filters{grid-template-columns:1fr 1fr}.wrap{padding:0 var(--s4)}.app-tag{display:none}}
@media(max-width:520px){.g2,.seg{grid-template-columns:1fr}.filters{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.u-bar i{animation-duration:15s}}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="i-sprout" viewBox="0 0 24 24"><path d="M12 21v-9"/><path d="M12 12C12 8 9 5 4 5c0 4 3 7 8 7z"/><path d="M12 14c0-3.5 2.5-6 8-6 0 3.5-2.5 6-8 6z"/></symbol>
<symbol id="i-dash" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
<symbol id="i-swap" viewBox="0 0 24 24"><path d="M7 7h13l-4-4"/><path d="M17 17H4l4 4"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-userplus" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></symbol>
<symbol id="i-pen" viewBox="0 0 24 24"><path d="M17.5 3.5l3 3-2.5 2.5-3-3z"/><path d="M15 6l3 3-9.5 9.5L4 20l1-4.5z"/><path d="M4 20.5h7"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/></symbol>
<symbol id="i-down" viewBox="0 0 24 24"><path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M5 21h14"/></symbol>
<symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></symbol>
<symbol id="i-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
<symbol id="i-sheet" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
<symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l3 3 5-6"/></symbol>
<symbol id="i-undo" viewBox="0 0 24 24"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></symbol>
<symbol id="i-inbox" viewBox="0 0 24 24"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.7 4H7.3a2 2 0 0 0-1.8 1.1z"/></symbol>
</defs></svg>

<header class="top"><div class="wrap top-in">
  <div class="brand">
    <div class="mark"><svg class="i"><use href="#i-sprout"/></svg><img src="tito.jpg" alt="Lorenzo Gregorio B. Nuñez III" onerror="this.remove()"></div>
    <div><h1>Ragnarr Agrivet Trading</h1><p>Tarum, Libmanan, Camarines Sur</p><p><b>Owned by:</b> Lorenzo Gregorio B. Nuñez III</p></div>
  </div>
  <span class="app-tag">Loan Management</span>
</div></header>
<nav class="tabs" aria-label="Main"><div class="wrap tabs-in">
  <button class="tab on" data-p="dash"><svg class="i"><use href="#i-dash"/></svg>Dashboard</button>
  <button class="tab" data-p="clients"><svg class="i"><use href="#i-users"/></svg>Clients</button>
  <button class="tab" data-p="trx"><svg class="i"><use href="#i-swap"/></svg>Transactions</button>
  <button class="tab" data-p="mine"><svg class="i"><use href="#i-inbox"/></svg>My Loans</button>
</div></nav>

<main class="wrap">
  <section class="panel on" id="p-dash">
    <div class="ph"><div><h2>Dashboard</h2><p>What clients owe right now.</p></div>
      <div class="acts"><button class="btn" data-act="newClient"><svg class="i"><use href="#i-userplus"/></svg>Add client</button><button class="btn pri" data-act="newTrx"><svg class="i"><use href="#i-plus"/></svg>New transaction</button></div></div>
    <div class="card" id="dHero"></div>
    <div class="charts">
      <div class="card"><div class="card-h"><h3>Money movement</h3><p>Loaned vs paid back each month, last 12 months.</p></div><div class="card-b" id="chMove"></div></div>
      <div class="card"><div class="card-h"><h3>Total owed over time</h3><p>Amount still owed at the end of each month.</p></div><div class="card-b" id="chOwed"></div></div>
      <div class="card"><div class="card-h"><h3>Repayment by client</h3><p>Paid back vs still owed. Select a client to open their transactions.</p></div><div class="card-b" id="chClients"></div></div>
      <div class="card"><div class="card-h"><h3>Client status</h3><p>Who owes, who is settled, and who has no transactions yet.</p></div><div class="card-b" id="chStatus"></div></div>
    </div>
    <div class="card" style="margin-top:var(--s5)"><div class="card-h"><h3>Recent transactions</h3><p>Latest 8 by date. Select one to open that client's transactions.</p></div><div id="dRec"></div></div>
  </section>

  <section class="panel" id="p-clients">
    <div class="ph"><div><h2>Clients</h2><p>A negative balance means the client still owes that amount.</p></div>
      <div class="acts"><button class="btn pri" data-act="newClient"><svg class="i"><use href="#i-userplus"/></svg>Add client</button></div></div>
    <div class="card">
      <div class="tools"><div class="sbox"><svg class="i"><use href="#i-search"/></svg><input id="cSearch" placeholder="Search clients" aria-label="Search clients" autocomplete="off"></div></div>
      <div class="tw" id="cTable"></div>
    </div>
  </section>

  <section class="panel" id="p-trx">
    <div class="ph"><div><h2>Transactions</h2><p>Record loans and payments, and review the ledger.</p></div>
      <div class="acts"><button class="btn" id="exportBtn"><svg class="i"><use href="#i-down"/></svg>Export JPEG</button><button class="btn" id="xlsBtn"><svg class="i"><use href="#i-sheet"/></svg>Export Excel</button><button class="btn pri" data-act="newTrx"><svg class="i"><use href="#i-plus"/></svg>New transaction</button></div></div>
    <div class="card" style="margin-bottom:var(--s5)">
      <div class="filters">
        <div><label for="fClientIn">Client</label><div id="fClient"></div></div>
        <div><label for="fFrom">From</label><input type="date" id="fFrom"></div>
        <div><label for="fTo">To</label><input type="date" id="fTo"></div>
        <div><label for="fOrder">Sort</label><select id="fOrder"><option value="asc">Oldest first</option><option value="desc">Newest first</option></select></div>
      </div>
      <div class="filters-f"><span>Quick range</span>
        <button class="chip" data-r="month">This month</button><button class="chip" data-r="30">Last 30 days</button><button class="chip" data-r="year">This year</button>
        <button class="chip" id="resetF">Reset filters</button></div>
    </div>
    <section class="card" id="exp">
      <div class="exp-only"><b>Ragnarr Agrivet Trading</b><span>Tarum, Libmanan, Camarines Sur</span><span>Owned by: Lorenzo Gregorio B. Nuñez III</span></div>
      <div class="stmt-h"><h3 id="stTitle"></h3><p id="stSub"></p></div>
      <div class="prog" id="stKpis"></div>
      <div class="tw" id="stTable"></div>
      <div class="sum" id="stSum"></div>
    </section>
  </section>
  <section class="panel" id="p-mine">
    <div class="ph"><div><h2>My Loans</h2><p>Money you owe to other people.</p></div>
      <div class="acts"><button class="btn pri" id="mCustom"><svg class="i"><use href="#i-check"/></svg>Custom settlement</button></div></div>
    <div class="card" style="margin-bottom:var(--s5)"><form class="card-b" id="mForm" novalidate>
      <div class="alert" role="alert" hidden></div>
      <div class="g2"><div class="fld"><label for="mName">Who I owe</label><input id="mName" list="mNames" autocomplete="off" placeholder="Start typing a name">
          <p class="hint">Pick a suggestion so one person isn't saved under two spellings.</p></div>
        <div class="fld"><label for="mAmt">How much (₱)</label><input id="mAmt" inputmode="decimal" placeholder="0.00"></div></div>
      <div class="g2"><div class="fld"><label for="mRcpt">Receipt number</label><input id="mRcpt" autocomplete="off" placeholder="e.g. 0012345">
          <p class="hint">Required. The number on the receipt for this loan.</p></div><div></div></div>
      <div class="g2"><div class="fld"><label for="mDate">Date borrowed <em>optional</em></label><input type="date" id="mDate">
          <p class="hint">If you don't input a date, the date as of encoding will be saved instead.</p></div>
        <div class="fld"><label for="mReason">Reason <em>optional</em></label><input id="mReason" autocomplete="off" placeholder="What the loan was for"></div></div>
      <button class="btn pri" type="submit"><svg class="i"><use href="#i-plus"/></svg>Add loan</button>
    </form></div>
    <datalist id="mNames"></datalist>
    <div class="card" style="margin-bottom:var(--s5)"><div class="card-h"><h3>Unpaid loans</h3><p>Settle pays one loan in full. Custom settlement pays the oldest loan first.</p></div>
      <div class="filters">
        <div><label for="mfName">Name</label><input id="mfName" list="mNames" autocomplete="off" placeholder="Search a name"></div>
        <div><label for="mfFrom">From</label><input type="date" id="mfFrom"></div>
        <div><label for="mfTo">To</label><input type="date" id="mfTo"></div>
        <div><label for="mfSort">Sort</label><select id="mfSort"><option value="dd">Date: newest first</option><option value="da">Date: oldest first</option><option value="na">Name: A to Z</option><option value="nd">Name: Z to A</option></select></div>
      </div>
      <div class="filters-f"><button class="chip" id="mfReset">Reset filters</button></div>
      <div class="tw" id="mTable"></div>
      <div class="owed-row" id="mTotal"></div></div>
    <div class="card"><div class="card-h"><h3>Settled loans</h3><p>Fully paid loans, with the day each was settled. Filters above apply here too.</p></div><div class="tw" id="mSettled"></div></div>
  </section>
</main>

<dialog id="adlg" aria-labelledby="adT"><form class="dlg-in" id="aForm" novalidate>
  <div class="dlg-h"><div><h3 id="adT">Add client</h3><p>Use the client's real full name. No aliases or nicknames.</p></div><button type="button" class="ib" data-x aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
  <div class="dlg-b"><div class="alert" role="alert" hidden></div>
    <div class="fld" style="margin:0"><label for="cname">Full name</label><input id="cname" autocomplete="off" placeholder="First Middle Last">
    <p class="hint">If two clients share a name, add a detail after an underscore, like Charles Andrei Galvan_Marikina.</p></div></div>
  <div class="dlg-f"><button type="button" class="btn" data-x>Cancel</button><button class="btn pri" type="submit">Save client</button></div>
</form></dialog>
<dialog id="tdlg" aria-labelledby="tdT"></dialog>
<dialog id="sdlg" class="sm" aria-labelledby="sdT"><form class="dlg-in" id="sForm" novalidate>
  <div class="dlg-h"><div><h3 id="sdT">Custom settlement</h3><p>The amount pays the oldest loan first, then the next one.</p></div><button type="button" class="ib" data-x aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
  <div class="dlg-b"><div class="alert" role="alert" hidden></div>
    <div class="fld"><label for="sName">Who you paid</label><input id="sName" list="mNames" autocomplete="off" placeholder="Start typing a name"></div>
    <div class="fld"><label for="sAmt">Amount paid (₱)</label><input id="sAmt" inputmode="decimal" placeholder="0.00"><p class="hint" id="sOwed"></p></div>
    <div id="sPrev"></div>
    <div class="fld" style="margin-top:var(--s4)"><label for="sCr">Collection receipt number</label><input id="sCr" autocomplete="off" placeholder="e.g. CR-000123">
      <p class="hint">Required. One collection receipt covers all the loans listed above.</p></div></div>
  <div class="dlg-f"><button type="button" class="btn" data-x>Cancel</button><button class="btn pri" type="submit">Apply settlement</button></div>
</form></dialog>
<dialog id="crdlg" class="sm" aria-labelledby="crT"><form class="dlg-in" id="crForm" novalidate>
  <div class="dlg-h"><div><h3 id="crT">Settle this loan?</h3><p id="crSub"></p></div><button type="button" class="ib" data-x aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
  <div class="dlg-b"><div class="alert" role="alert" hidden></div>
    <div id="crInfo"></div>
    <div class="fld" style="margin:var(--s4) 0 0"><label for="crNo">Collection receipt number</label><input id="crNo" autocomplete="off" placeholder="e.g. CR-000123">
      <p class="hint">Required. The number on the collection receipt you got when you paid.</p></div></div>
  <div class="dlg-f"><button type="button" class="btn" data-x>Cancel</button><button class="btn pri" type="submit">Settle</button></div>
</form></dialog>
<dialog id="cdlg" class="sm"></dialog>
<dialog id="rdlg" class="sm"></dialog>
<div id="ulayer" popover="manual" aria-live="polite"></div>
<div id="layer" popover="manual" aria-live="polite"></div>

<script>
const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
const S={clients:[],view:'dash',f:{client:0,from:'',to:'',order:'asc'},H:null};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=x=>(x<0?'-':'')+'₱'+Math.abs(x).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const signed=x=>(x>0?'+':'')+money(x), tone=x=>x<0?'neg':x>0?'pos':'zero';
const MO=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const fdate=s=>{const [y,m,d]=s.slice(0,10).split('-');return `${MO[+m-1]} ${+d}, ${y}`};
const ymd=d=>d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
const ico=(n,c='')=>`<svg class="i ${c}" aria-hidden="true"><use href="#i-${n}"/></svg>`;
async function api(a,d={}){const r=await fetch('?api='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const t=await r.text();let j;
  try{j=JSON.parse(t)}catch(e){throw new Error('Server error: '+t.replace(/<[^>]+>/g,' ').trim().slice(0,200))}if(!j.ok)throw new Error(j.error);return j}

// ---------- top-layer message stack (toasts + undo cards) ----------
const layer=$('#layer');
const ulayer=$('#ulayer');
function push(el,L=layer){L.appendChild(el);if(L.showPopover){if(L.matches(':popover-open'))L.hidePopover();L.showPopover()}}  // re-show = move above any open dialog
function drop(el,L=layer){el.remove();if(!L.children.length&&L.hidePopover&&L.matches(':popover-open'))L.hidePopover()}
function toast(msg,bad){const e=document.createElement('div');e.className='toast'+(bad?' err':'');e.setAttribute('role',bad?'alert':'status');
  e.innerHTML=ico(bad?'alert':'check')+`<span>${esc(msg)}</span>`;push(e);setTimeout(()=>drop(e),bad?7000:3500)}
const UNDO_MS=15000;
function undoCard(t){const e=document.createElement('div');e.className='undo';e.setAttribute('role','alertdialog');
  const msg=`#${t.tid}: ${t.amt<0?'loan':'payment'} of ${money(Math.abs(t.amt))} for ${t.client}`;
  e.innerHTML=`<div class="u-body"><div class="ic">${ico('trash')}</div><div class="u-txt"><b>Transaction deleted</b><span title="${esc(msg)}">${esc(msg)}</span></div><button class="btn" data-u><span>${ico('undo')}Undo (<span data-n>15</span>s)</span></button><button class="ib" data-c title="Dismiss" aria-label="Dismiss">${ico('x')}</button></div><div class="u-bar"><i></i></div>`;
  const end=Date.now()+UNDO_MS,n=$('[data-n]',e),btn=$('[data-u]',e);
  const iv=setInterval(()=>{const left=Math.ceil((end-Date.now())/1000);if(left<=0){clearInterval(iv);drop(e,ulayer)}else n.textContent=left},250);
  btn.onclick=async()=>{clearInterval(iv);btn.disabled=true;const c=S.clients.find(c=>c.id===t.client_id),before=c?c.bal:0;
    try{const r=await api('restoreTrx',{id:t.id,client_id:t.client_id,tid:t.tid,type:t.amt<0?'loan':'pay',amount:Math.abs(t.amt).toFixed(2),date:t.date,notes:t.notes||''});drop(e,ulayer);refresh();
      notice({title:'Transaction restored',client:t.client,type:t.amt<0?'loan':'pay',amount:Math.abs(t.amt),tid:t.tid,date:t.date,before,after:r.bal})}
    catch(x){drop(e,ulayer);toast(x.message,1)}};
  $('[data-c]',e).onclick=()=>{clearInterval(iv);drop(e,ulayer)};
  push(e,ulayer)}
const owed=b=>money(b<0?-b:0);
const dl=rows=>`<dl class="rs-d">${rows.map(([k,v])=>`<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl>`;
const trxRows=o=>[['Client',esc(o.client)],['Transaction',`${o.type==='loan'?'Loan':'Payment'} of ${money(+o.amount)}`],['Transaction ID','#'+esc(o.tid)],['Date',fdate(o.date)],['Amount owed',`${owed(o.before)} <span class="mute">&rarr;</span> <b>${owed(o.after)}</b>`]];
const checkAnim='<svg class="anim" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M14 27l8 8 16-17"/></svg>';
function reviewDlg({title,rows,yes}){return new Promise(res=>{const d=$('#cdlg');let v=false;
  d.innerHTML=`<div class="dlg-b rs"><div class="ok-ic">${ico('swap')}</div><h3>${esc(title)}</h3><p class="mute">Please check the details before saving.</p>${dl(rows)}</div><div class="dlg-f fill"><button class="btn" data-n>Back</button><button class="btn pri" data-y>${esc(yes)}</button></div>`;
  d.onclose=()=>res(v);d.onclick=e=>{if(e.target===d)d.close()};$('[data-n]',d).onclick=()=>d.close();$('[data-y]',d).onclick=()=>{v=true;d.close()};d.showModal();$('[data-y]',d).focus()})}
function notice(o){const d=$('#rdlg');
  d.innerHTML=`<div class="dlg-b rs">${checkAnim}<h3>${esc(o.title)}</h3><p class="mute">The balance has been updated.</p>${dl(trxRows(o))}</div><div class="dlg-f fill"><button class="btn pri" data-ok>Okay</button></div>`;
  d.onclick=e=>{if(e.target===d)d.close()};$('[data-ok]',d).onclick=()=>d.close();d.showModal();$('[data-ok]',d).focus()}

// ---------- searchable client picker ----------
let cbN=0;
function combo(host,{items,value=0,all=null,disabled=false,onChange=()=>{}}){
  const id=host.id||'cb'+(++cbN),opts=()=>(all?[{id:0,name:all}]:[]).concat(items);
  host.className='cb';
  host.innerHTML=`<input id="${id}In" role="combobox" aria-expanded="false" aria-controls="${id}Ls" aria-autocomplete="list" autocomplete="off" placeholder="Search clients" ${disabled?'disabled':''}>${ico('chev','chev')}<ul class="cb-list" id="${id}Ls" role="listbox" hidden></ul>`;
  const inp=$('input',host),ul=$('ul',host);let val=value,hi=0,shown=[];
  const label=()=>{const o=opts().find(o=>o.id==val);return o?o.name:''};
  const place=()=>{if(ul.hidden)return;const r=inp.getBoundingClientRect(),h=ul.offsetHeight;
    ul.style.left=r.left+'px';ul.style.width=r.width+'px';ul.style.top=((r.bottom+h+8>innerHeight&&r.top>h+8)?r.top-h-4:r.bottom+4)+'px'};
  ul._place=place;
  const mark=()=>{$$('li',ul).forEach((li,i)=>li.classList.toggle('hi',i===hi));const h=$('.hi',ul);if(h)h.scrollIntoView({block:'nearest'})};
  const draw=q=>{shown=opts().filter(o=>o.name.toLowerCase().includes(q.trim().toLowerCase()));hi=q.trim()?0:Math.max(0,shown.findIndex(o=>o.id==val));
    ul.innerHTML=shown.length?shown.map((o,i)=>`<li role="option" data-i="${i}" class="${o.id==val?'sel':''}">${esc(o.name)}</li>`).join(''):'<li class="none">No client matches that search</li>';
    ul.hidden=false;inp.setAttribute('aria-expanded','true');place();mark()};
  const close=()=>{ul.hidden=true;inp.setAttribute('aria-expanded','false');inp.value=label()};
  const pick=o=>{val=o.id;close();onChange(val)};
  inp.value=label();
  inp.onfocus=()=>{inp.select();draw('')};
  inp.onclick=()=>{if(ul.hidden)draw('')};
  inp.oninput=()=>draw(inp.value);
  inp.onblur=close;
  inp.onkeydown=e=>{
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(ul.hidden)return draw('');if(!shown.length)return;hi=(hi+(e.key==='ArrowDown'?1:-1)+shown.length)%shown.length;mark()}
    else if(e.key==='Enter'&&!ul.hidden){e.preventDefault();if(shown[hi])pick(shown[hi])}
    else if(e.key==='Escape'&&!ul.hidden){e.preventDefault();e.stopPropagation();close()}};
  ul.onmousedown=e=>{e.preventDefault();const li=e.target.closest('li[data-i]');if(li)pick(shown[+li.dataset.i])};
  host.get=()=>val;
  return host}
document.addEventListener('scroll',()=>$$('.cb-list:not([hidden])').forEach(u=>u._place&&u._place()),true);

// ---------- dialogs ----------
function wire(d){d.onclick=e=>{if(e.target===d)d.close()};$$('[data-x]',d).forEach(b=>b.onclick=()=>d.close())}
function fail(d,msg,el){const a=$('.alert',d);a.innerHTML=ico('alert')+`<span>${esc(msg)}</span>`;a.hidden=false;a.scrollIntoView({block:'nearest'});if(el)el.focus()}
function confirmDlg({title,body,yes}){return new Promise(res=>{const d=$('#cdlg');let v=false;
  d.innerHTML=`<div class="dlg-b" style="padding-top:var(--s5)"><div class="cfm"><div class="ic">${ico('trash')}</div><div><h3>${title}</h3><p>${body}</p></div></div></div><div class="dlg-f"><button class="btn" data-n>Cancel</button><button class="btn bad" data-y>${yes}</button></div>`;
  d.onclose=()=>res(v);d.onclick=e=>{if(e.target===d)d.close()};$('[data-n]',d).onclick=()=>d.close();$('[data-y]',d).onclick=()=>{v=true;d.close()};d.showModal()})}

// ---------- navigation ----------
async function show(p){S.view=p;document.body.classList.toggle('wide',p==='trx');$$('.panel').forEach(e=>e.classList.toggle('on',e.id==='p-'+p));$$('.tab').forEach(b=>b.classList.toggle('on',b.dataset.p===p));
  if(p==='mine'){try{await renderMine()}catch(e){toast(e.message,1)}return}
  try{await loadClients();if(p==='dash')await renderDash();if(p==='clients')renderClients();if(p==='trx')await renderLedger()}catch(e){toast(e.message,1)}}
const refresh=()=>show(S.view);
$$('.tab').forEach(b=>b.onclick=()=>show(b.dataset.p));
$$('[data-act=newTrx]').forEach(b=>b.onclick=()=>openTrx());$$('[data-act=newClient]').forEach(b=>b.onclick=openClient);
async function loadClients(){S.clients=(await api('clients')).clients}
function openLedger(id){S.f.client=id;show('trx')}

// ---------- dashboard ----------
const COL={paid:'#1e6b45',owed:'#e0a030',bad:'#b3261e',grey:'#c9d0ca'},compact=new Intl.NumberFormat('en',{notation:'compact',maximumFractionDigits:1});
const cpeso=x=>'₱'+compact.format(x),noData=m=>`<div class="empty">${ico('inbox')}<span>${m}</span></div>`;
const axis=mx=>{const raw=(mx||1)/4,p=10**Math.floor(Math.log10(raw)),f=raw/p,step=(f<=1?1:f<=2?2:f<=2.5?2.5:f<=5?5:10)*p;return{step,top:step*4}};
const mlab=(ym,i)=>{const [y,m]=ym.split('-');return MO[+m-1]+(i===0||m==='01'?' '+y.slice(2):'')};
function gridLines(W,L,R,T,ph,step,y){let g='';for(let i=0;i<=4;i++){const v=step*i;g+=`<line class="gl" x1="${L}" x2="${W-R}" y1="${y(v)}" y2="${y(v)}"/><text x="${L-8}" y="${y(v)+4}" text-anchor="end">${cpeso(v)}</text>`}return g}
function moveChart(ms){const mx=Math.max(...ms.map(m=>Math.max(m.loaned,m.paid)));if(!mx)return noData('No transactions in the last 12 months.');
  const W=560,H=270,L=56,R=12,T=14,B=34,pw=W-L-R,ph=H-T-B,{step,top}=axis(mx),y=v=>T+ph-v/top*ph,gw=pw/ms.length,bw=Math.min(16,gw*.34);
  const bars=ms.map((m,i)=>{const cx=L+gw*i+gw/2;return `<g><title>${mlab(m.ym,1)} ${m.ym.slice(0,4)}: loaned ${money(m.loaned)}, paid ${money(m.paid)}</title><rect x="${cx-bw-1}" y="${y(m.loaned)}" width="${bw}" height="${T+ph-y(m.loaned)}" rx="3" fill="${COL.owed}"/><rect x="${cx+1}" y="${y(m.paid)}" width="${bw}" height="${T+ph-y(m.paid)}" rx="3" fill="${COL.paid}"/><text x="${cx}" y="${H-12}" text-anchor="middle">${mlab(m.ym,i)}</text></g>`}).join('');
  return `<div class="legend"><span><i style="background:${COL.owed}"></i>Loaned</span><span><i style="background:${COL.paid}"></i>Paid back</span></div><svg class="ch" viewBox="0 0 ${W} ${H}" role="img" aria-label="Loaned versus paid back per month">${gridLines(W,L,R,T,ph,step,y)}${bars}</svg>`}
function owedChart(ms){const mx=Math.max(...ms.map(m=>m.owed));if(!(mx>0))return noData('Nothing is owed in this period.');
  const W=560,H=270,L=56,R=16,T=14,B=34,pw=W-L-R,ph=H-T-B,{step,top}=axis(mx),y=v=>T+ph-Math.max(0,v)/top*ph,gw=pw/ms.length,x=i=>L+gw*i+gw/2;
  const pts=ms.map((m,i)=>`${x(i)},${y(m.owed)}`),base=T+ph;
  return `<div class="legend"><span><i style="background:${COL.bad}"></i>Still owed at month end</span></div><svg class="ch" viewBox="0 0 ${W} ${H}" role="img" aria-label="Total owed at the end of each month">${gridLines(W,L,R,T,ph,step,y)}<path d="M${x(0)},${base} L${pts.join(' L')} L${x(ms.length-1)},${base}Z" fill="${COL.bad}" fill-opacity=".12"/><polyline points="${pts.join(' ')}" fill="none" stroke="${COL.bad}" stroke-width="2.5" stroke-linejoin="round"/>${ms.map((m,i)=>`<g><title>${mlab(m.ym,1)} ${m.ym.slice(0,4)}: ${money(m.owed)} owed</title><circle cx="${x(i)}" cy="${y(m.owed)}" r="4" fill="#fff" stroke="${COL.bad}" stroke-width="2"/><text x="${x(i)}" y="${H-12}" text-anchor="middle">${mlab(m.ym,i)}</text></g>`).join('')}</svg>`}
function clientBars(cl){if(!cl.length)return noData('No client has transactions yet.');
  return `<div class="legend"><span><i style="background:${COL.paid}"></i>Paid back</span><span><i style="background:#f1d9a6"></i>Still owed</span></div>`+cl.map(c=>{const o=Math.max(0,c.loaned-c.paid),pp=c.loaned>0?c.paid/c.loaned*100:0;
    return `<button class="cr" data-h="${c.id}"><span class="cr-top"><b>${esc(c.name)}</b><span class="${o>0?'neg':'pos'}">${o>0?money(o)+' owed':'Settled'}</span></span><span class="bar sm"><i style="width:${pp.toFixed(1)}%"></i></span><small>${money(c.paid)} of ${money(c.loaned)} paid back (${pp.toFixed(1)}%)</small></button>`}).join('')}
function statusChart(st){const tot=st.owing+st.settled+st.fresh;if(!tot)return noData('No clients yet.');
  const seg=[['Owing',st.owing,COL.owed],['Settled',st.settled,COL.paid],['No transactions yet',st.fresh,COL.grey]];let off=0;
  const rings=seg.filter(x=>x[1]).map(([n,v,c])=>{const pc=v/tot*100,r=`<circle cx="21" cy="21" r="15.9155" fill="none" stroke="${c}" stroke-width="6" stroke-dasharray="${pc} ${100-pc}" stroke-dashoffset="${25-off}"><title>${n}: ${v}</title></circle>`;off+=pc;return r}).join('');
  return `<div class="donut"><svg viewBox="0 0 42 42" role="img" aria-label="Clients by status">${rings}<text class="dn" x="21" y="22.5" text-anchor="middle">${tot}</text><text class="dl" x="21" y="27" text-anchor="middle">clients</text></svg><ul>${seg.map(([n,v,c])=>`<li><i style="background:${c}"></i><span>${n}</span><b>${v}</b></li>`).join('')}</ul></div>`}
async function renderDash(){const d=await api('dashboard'),pct=d.loaned>0?Math.min(100,d.paid/d.loaned*100):0;
  $('#dHero').innerHTML=`<div class="card-b"><div class="prog-top"><div><small class="mute">Paid back out of total loaned</small><div class="prog-amt big">${money(d.paid)} <span>/ ${money(d.loaned)}</span></div></div><div style="text-align:right"><small class="mute">paid back</small><div class="prog-pct big">${pct.toFixed(1)}%</div></div></div><div class="bar"><i style="width:${pct.toFixed(1)}%"></i></div></div><div class="owed-row"><div><span>Still owed</span><small>as of ${fdate(ymd(new Date()))}</small></div><b>${money(d.owed)}</b></div>`;
  $('#chMove').innerHTML=moveChart(d.months);$('#chOwed').innerHTML=owedChart(d.months);$('#chClients').innerHTML=clientBars(d.cp);$('#chStatus').innerHTML=statusChart(d.status);
  $('#dRec').innerHTML=d.recent.length?d.recent.map(r=>`<div class="li" data-h="${r.client_id}"><span>${esc(r.name)}<small>#${esc(r.tid)} &middot; ${fdate(r.trx_date)}</small></span><span style="text-align:right"><span class="tag ${r.amt<0?'l':'p'}">${r.amt<0?'Loan':'Payment'}</span><small style="margin-top:2px">${money(Math.abs(r.amt))}</small></span></div>`).join(''):`<div class="empty">${ico('inbox')}<span>No transactions yet.</span></div>`;
  $$('#p-dash [data-h]').forEach(e=>e.onclick=()=>openLedger(+e.dataset.h))}

// ---------- clients ----------
function renderClients(){const q=$('#cSearch').value.trim().toLowerCase(),l=S.clients.filter(c=>c.name.toLowerCase().includes(q));
  $('#cTable').innerHTML=l.length?`<table><thead><tr><th>Client</th><th>Status</th><th class="num">Transactions</th><th class="num">Balance</th><th></th></tr></thead><tbody>${l.map(c=>`<tr><td><span class="who"><span class="av">${esc(c.name[0])}</span>${esc(c.name)}</span></td><td>${c.bal<0?'<span class="tag l">Owes</span>':'<span class="tag p">Settled</span>'}</td><td class="num">${c.n}</td><td class="num ${tone(c.bal)}">${money(c.bal)}</td><td class="act"><button class="ib" data-n="${c.id}" title="New transaction" aria-label="New transaction for ${esc(c.name)}">${ico('plus')}</button><button class="ib" data-v="${c.id}" title="View transactions" aria-label="View transactions of ${esc(c.name)}">${ico('list')}</button></td></tr>`).join('')}</tbody></table>`
   :`<div class="empty">${ico('users')}<span>${S.clients.length?'No client matches that search.':'No clients yet. Add your first client.'}</span></div>`;
  $$('[data-n]',$('#cTable')).forEach(b=>b.onclick=()=>openTrx({clientId:+b.dataset.n}));$$('[data-v]',$('#cTable')).forEach(b=>b.onclick=()=>openLedger(+b.dataset.v))}
$('#cSearch').oninput=renderClients;
function openClient(){const d=$('#adlg');$('.alert',d).hidden=true;$('#cname').value='';d.showModal();$('#cname').focus()}
wire($('#adlg'));
$('#aForm').onsubmit=async e=>{e.preventDefault();const d=$('#adlg'),i=$('#cname'),b=$('button[type=submit]',d);$('.alert',d).hidden=true;b.disabled=true;
  try{const r=await api('addClient',{name:i.value});d.close();toast('Client saved: '+r.name);refresh()}
  catch(x){fail(d,x.message,i);if(/already exists/.test(x.message))i.value=i.value.trim().replace(/\s+/g,' ')+'_'}
  finally{b.disabled=false}};

// ---------- my loans (stored in MySQL: my_loans + my_loan_payments) ----------
S.mine=[];
const r2=x=>Math.round(x*100)/100,left=x=>r2(x.amt-(x.paid||0)),okAmt=a=>/^\d{1,9}(\.\d{1,2})?$/.test(a)&&+a>0;
// a loan's date: the date borrowed, else the moment it was encoded
const mDay=x=>x.date||x.at.slice(0,10),mTime=x=>new Date(x.date?x.date+'T00:00':x.at).getTime();
const midnight=t=>{const d=new Date(t);d.setHours(0,0,0,0);return d.getTime()};
const mDays=x=>Math.max(0,Math.round((midnight(x.settledAt?new Date(x.settledAt):Date.now())-midnight(mTime(x)))/864e5));
const dayTxt=n=>n?n+' day'+(n>1?'s':''):'Today';
const dateCell=x=>x.date?fdate(x.date):`<span class="mute">(Encoded on: ${esc(x.encoded)})</span>`;
const rcpt=x=>x.receipt?esc(x.receipt):'<span class="mute">—</span>';
const mNamesList=()=>[...new Set(S.mine.map(x=>x.name))].sort((a,b)=>a.localeCompare(b));
// reuse an existing spelling when the typed name matches it ignoring case/spaces
const canon=n=>{n=n.trim().replace(/\s+/g,' ');return mNamesList().find(x=>x.toLowerCase()===n.toLowerCase())||n};
const oldestFirst=(a,b)=>mTime(a)-mTime(b)||a.id-b.id;
// preview only; the server does the real allocation the same way
function allocate(name,amt){let r=r2(amt);const out=[];
  for(const x of S.mine.filter(x=>x.name===name&&left(x)>0).sort(oldestFirst)){if(r<=0)break;const p=Math.min(r,left(x));out.push({x,p});r=r2(r-p)}return out}
async function renderMine(){S.mine=(await api('myLoans')).loans;drawMine()}
function drawMine(){const all=S.mine,names=mNamesList(),q=$('#mfName').value.trim().toLowerCase(),from=$('#mfFrom').value,to=$('#mfTo').value,so=$('#mfSort').value;
  $('#mNames').innerHTML=names.map(n=>`<option value="${esc(n)}">`).join('');
  const hit=x=>(!q||x.name.toLowerCase().includes(q))&&(!from||mDay(x)>=from)&&(!to||mDay(x)<=to);
  const srt=(a,b)=>so==='da'?oldestFirst(a,b):so==='na'?a.name.localeCompare(b.name)||mTime(b)-mTime(a):so==='nd'?b.name.localeCompare(a.name)||mTime(b)-mTime(a):mTime(b)-mTime(a)||b.id-a.id;
  const open=all.filter(x=>!x.settledAt&&hit(x)).sort(srt),done=all.filter(x=>x.settledAt&&hit(x)).sort(srt);
  const tot=r2(open.reduce((s,x)=>s+left(x),0)),people=new Set(open.map(x=>x.name)),none=all.length?'No loans match these filters.':'No loans recorded. Add one above.';
  $('#mTable').innerHTML=open.length?`<table><thead><tr><th>Who I owe</th><th>Receipt #</th><th>Date borrowed</th><th class="num">Days</th><th>Reason</th><th class="num">Amount</th><th class="num">Remaining</th><th></th></tr></thead><tbody>${open.map(x=>`<tr><td>${esc(x.name)}</td><td>${rcpt(x)}</td><td>${dateCell(x)}</td><td class="num">${dayTxt(mDays(x))}</td><td>${x.reason?esc(x.reason):'<span class="mute">—</span>'}</td><td class="num">${money(x.amt)}</td><td class="num neg">${money(left(x))}${x.paid?`<small class="mute" style="display:block;font-weight:400">${money(x.paid)} paid</small>`:''}</td><td class="act"><button class="btn" style="padding:5px 12px" data-ms="${x.id}">Settle</button><button class="ib" data-md="${x.id}" title="Delete" aria-label="Delete loan from ${esc(x.name)}">${ico('trash')}</button></td></tr>`).join('')}</tbody></table>`
    :`<div class="empty">${ico('inbox')}<span>${all.some(x=>!x.settledAt)?none:all.length?'Everything is settled.':none}</span></div>`;
  $('#mTotal').style.display=open.length?'':'none';
  $('#mTotal').innerHTML=open.length?`<div><span>You owe</span><small>${open.length} unpaid loan${open.length>1?'s':''}${from||to?` &middot; ${from?fdate(from):'start'} to ${to?fdate(to):'today'}`:''}</small></div><span style="display:flex;align-items:center;gap:var(--s4);flex-wrap:wrap;justify-content:flex-end"><b class="neg">${money(tot)}${people.size===1?` <span style="font-weight:600">to ${esc([...people][0])}</span>`:''}</b><button class="btn pri" data-mc>Custom settlement</button></span>`:'';
  $('#mSettled').innerHTML=done.length?`<table><thead><tr><th>Who I owed</th><th>Receipt #</th><th>Collection receipt</th><th>Date borrowed</th><th>Settled on</th><th class="num">Took</th><th>Reason</th><th class="num">Amount</th><th></th></tr></thead><tbody>${done.map(x=>`<tr><td>${esc(x.name)}</td><td>${rcpt(x)}</td><td>${x.crs?esc(x.crs):'<span class="mute">—</span>'}</td><td>${dateCell(x)}</td><td>${esc(x.settled)}</td><td class="num">${dayTxt(mDays(x))}</td><td>${x.reason?esc(x.reason):'<span class="mute">—</span>'}</td><td class="num pos">${money(x.amt)}</td><td class="act"><button class="ib" data-md="${x.id}" title="Delete" aria-label="Delete settled loan from ${esc(x.name)}">${ico('trash')}</button></td></tr>`).join('')}</tbody></table>`
    :`<div class="empty">${ico('check')}<span>No settled loans${q||from||to?' match these filters':' yet'}.</span></div>`;
  $$('#p-mine [data-mc]').forEach(b=>b.onclick=()=>$('#mCustom').click());
  $$('#p-mine [data-md]').forEach(b=>b.onclick=async()=>{if(!await confirmDlg({title:'Delete this loan?',body:'This removes it and its payments from My Loans completely.',yes:'Delete'}))return;
    try{await api('deleteMyLoan',{id:+b.dataset.md});toast('Loan deleted');await renderMine()}catch(e){toast(e.message,1)}});
  $$('#p-mine [data-ms]').forEach(b=>b.onclick=()=>{const x=S.mine.find(z=>z.id==b.dataset.ms),d=$('#crdlg');
    $('.alert',d).hidden=true;$('#crForm').reset();d.dataset.id=x.id;
    $('#crSub').textContent=`Mark ${money(left(x))} to ${x.name} as paid today.`;
    $('#crInfo').innerHTML=`<div class="li"><span>Receipt #${rcpt(x)}<small>${money(x.amt)} loan &middot; ${dateCell(x)}</small></span><span style="text-align:right"><b>${money(left(x))}</b><small>to pay</small></span></div>`;
    d.showModal();$('#crNo').focus()})}
wire($('#crdlg'));
$('#crForm').onsubmit=async e=>{e.preventDefault();const d=$('#crdlg'),b=$('button[type=submit]',d),x=S.mine.find(z=>z.id==d.dataset.id),cr=$('#crNo').value.trim();
  $('.alert',d).hidden=true;
  if(!cr)return fail(d,'Enter the collection receipt number.',$('#crNo'));
  b.disabled=true;
  try{await api('settleMyLoan',{id:x.id,cr});d.close();toast(`Settled ${money(x.amt)} to ${x.name}`);await renderMine()}catch(z){fail(d,z.message)}finally{b.disabled=false}};
$('#mForm').onsubmit=async e=>{e.preventDefault();const f=e.target,b=$('button[type=submit]',f);$('.alert',f).hidden=true;
  const name=canon($('#mName').value),a=$('#mAmt').value.replace(/,/g,'').trim();
  if(!name)return fail(f,'Enter who you owe.',$('#mName'));
  if(!okAmt(a))return fail(f,'Amount must be greater than 0, with up to 2 decimal places.',$('#mAmt'));
  const receipt=$('#mRcpt').value.trim();
  if(!receipt)return fail(f,'Enter the receipt number.',$('#mRcpt'));
  b.disabled=true;
  try{await api('addMyLoan',{name,amount:a,date:$('#mDate').value,reason:$('#mReason').value,receipt});f.reset();toast('Loan added');await renderMine();$('#mName').focus()}
  catch(x){fail(f,x.message)}finally{b.disabled=false}};
['mfFrom','mfTo','mfSort'].forEach(i=>$('#'+i).onchange=drawMine);$('#mfName').oninput=drawMine;
$('#mfReset').onclick=()=>{$('#mfName').value='';$('#mfFrom').value='';$('#mfTo').value='';$('#mfSort').value='dd';drawMine()};
// custom settlement: preview how the amount spreads over the oldest loans
function sPreview(){const name=canon($('#sName').value),a=$('#sAmt').value.replace(/,/g,'').trim(),owed=r2(S.mine.filter(x=>x.name===name&&left(x)>0).reduce((s,x)=>s+left(x),0));
  $('#sOwed').textContent=owed?`You owe ${money(owed)} to ${name}.`:'';
  $('#sPrev').innerHTML=owed&&okAmt(a)?`<div class="lbl">What this pays</div>`+allocate(name,Math.min(+a,owed)).map(({x,p})=>`<div class="li"><span>Receipt #${rcpt(x)} &middot; ${money(x.amt)} loan<small>${dateCell(x)}</small></span><span style="text-align:right">${p>=left(x)?'<span class="tag p">Paid</span>':`<span class="tag l">Deduct ${money(p)}</span><small style="margin-top:2px">Remaining: ${money(r2(left(x)-p))}</small>`}</span></div>`).join('')
    +`<p class="hint" style="margin-top:var(--s3)">Receipts covered: <b>${allocate(name,Math.min(+a,owed)).map(({x})=>x.receipt?'#'+esc(x.receipt):'(no receipt #)').join(', ')}</b></p>`:'';
  return {name,a,owed}}
$('#mCustom').onclick=()=>{const d=$('#sdlg');$('.alert',d).hidden=true;$('#sForm').reset();
  const q=$('#mfName').value.trim();if(mNamesList().includes(canon(q)))$('#sName').value=canon(q);sPreview();d.showModal();($('#sName').value?$('#sAmt'):$('#sName')).focus()};
wire($('#sdlg'));$('#sName').oninput=sPreview;$('#sAmt').oninput=sPreview;
$('#sForm').onsubmit=async e=>{e.preventDefault();const d=$('#sdlg'),b=$('button[type=submit]',d);$('.alert',d).hidden=true;const {name,a,owed}=sPreview();
  if(!owed)return fail(d,name?`You have no unpaid loans to ${name}.`:'Enter who you paid.',$('#sName'));
  if(!okAmt(a))return fail(d,'Amount must be greater than 0, with up to 2 decimal places.',$('#sAmt'));
  if(+a>owed)return fail(d,`That's more than you owe ${name} (${money(owed)}).`,$('#sAmt'));
  const cr=$('#sCr').value.trim();
  if(!cr)return fail(d,'Enter the collection receipt number.',$('#sCr'));
  b.disabled=true;
  try{await api('customSettle',{name,amount:a,cr});d.close();toast(`Paid ${money(+a)} to ${name}`);await renderMine()}catch(x){fail(d,x.message)}finally{b.disabled=false}};

// ---------- transaction dialog (new + edit share one form) ----------
function openTrx({edit=null,clientId=0}={}){const d=$('#tdlg');
  const list=edit?S.clients.filter(c=>c.id===edit.client_id):S.clients,pre=edit?edit.client_id:clientId||(S.view==='trx'&&S.f.client)||0;
  d.innerHTML=`<form class="dlg-in" novalidate>
  <div class="dlg-h"><div><h3 id="tdT">${edit?'Edit transaction':'New transaction'}</h3><p>${edit?'Changes recalculate the running balance.':'Loans increase what a client owes. Payments reduce it.'}</p></div><button type="button" class="ib" data-x aria-label="Close">${ico('x')}</button></div>
  <div class="dlg-b"><div class="alert" role="alert" hidden></div>
    <div class="fld"><label for="t-clientIn">Client</label><div id="t-client"></div></div>
    <div class="lbl">Type</div>
    <div class="seg" role="radiogroup" aria-label="Transaction type"><label><input type="radio" name="ttype" value="loan"><span>Loan<small>Increases what they owe</small></span></label><label><input type="radio" name="ttype" value="pay"><span>Payment<small>Reduces what they owe</small></span></label></div>
    <div class="g2"><div class="fld"><label for="t-tid">Transaction ID</label><input id="t-tid" autocomplete="off"></div><div class="fld"><label for="t-date">Date</label><input type="date" id="t-date"></div></div>
    <div class="fld"><label for="t-amt">Amount</label><div class="adorn"><span>₱</span><input id="t-amt" inputmode="decimal" autocomplete="off" placeholder="0.00"></div></div>
    <div class="fld"><label for="t-notes">Notes<em>optional</em></label><textarea id="t-notes"></textarea></div>
    <div class="preview"><div><small>Current balance</small><b data-cur>-</b></div><span class="arr">${ico('swap')}</span><div><small>Balance after</small><b data-after>-</b></div><div class="warn" data-warn hidden>A payment can't be more than what the client owes.</div></div>
  </div>
  <div class="dlg-f"><button type="button" class="btn" data-x>Cancel</button><button class="btn pri" type="submit">${edit?'Save changes':'Save transaction'}</button></div></form>`;
  const f=$('form',d),amt=$('#t-amt',d),cc=combo($('#t-client',d),{items:list,value:pre||0,disabled:!!edit,onChange:()=>upd()}),cid=()=>cc.get(),radios=$$('[name=ttype]',d),type=()=>(radios.find(r=>r.checked)||{}).value||'';
  const upd=()=>{const c=S.clients.find(c=>c.id==cid()),a=parseFloat(amt.value),ty=type();
    $('[data-cur]',d).textContent=c?money(c.bal):'-';$('[data-cur]',d).className=c?tone(c.bal):'';
    let after=null;if(c&&ty&&a>0)after=Math.round((c.bal-(edit?edit.amt:0)+(ty==='loan'?-a:a))*100)/100;
    $('[data-after]',d).textContent=after===null?'-':money(after);$('[data-after]',d).className=after===null?'':tone(after);$('[data-warn]',d).hidden=!(after>0.004)};
  radios.forEach(r=>r.onchange=upd);
  amt.oninput=e=>{let v=e.target.value.replace(/[^\d.]/g,'');const p=v.indexOf('.');if(p>-1)v=v.slice(0,p+1)+v.slice(p+1).replace(/\./g,'');e.target.value=v;upd()};
  if(edit){$('#t-tid',d).value=edit.tid;radios.forEach(r=>r.checked=r.value===(edit.amt<0?'loan':'pay'));$('#t-date',d).value=edit.date;amt.value=Math.abs(edit.amt).toFixed(2);$('#t-notes',d).value=edit.notes||''}
  else{$('#t-date',d).value=ymd(new Date())}
  upd();wire(d);
  f.onsubmit=async e=>{e.preventDefault();$('.alert',d).hidden=true;
    const v={client_id:cid(),tid:$('#t-tid',d).value.trim(),type:type(),date:$('#t-date',d).value,amount:amt.value.trim(),notes:$('#t-notes',d).value.trim()};
    if(!v.client_id)return fail(d,'Select a client.',$('input',$('#t-client',d)));
    if(!v.type)return fail(d,'Choose Loan or Payment.');
    if(!v.tid)return fail(d,'Transaction ID is required.',$('#t-tid',d));
    if(!v.date)return fail(d,'Date is required.',$('#t-date',d));
    if(!v.amount||+v.amount<=0)return fail(d,'Amount must be greater than 0.',amt);
    const cl=S.clients.find(c=>c.id==v.client_id),amtN=+v.amount,exp=Math.round((cl.bal-(edit?edit.amt:0)+(v.type==='loan'?-amtN:amtN))*100)/100;
    const info={client:cl.name,type:v.type,amount:amtN,tid:v.tid,date:v.date,before:cl.bal};
    if(!await reviewDlg({title:edit?'Confirm these changes?':'Confirm this transaction?',yes:edit?'Confirm changes':'Confirm',rows:trxRows({...info,after:exp})}))return;
    const b=$('button[type=submit]',d);b.disabled=true;
    try{const r=await api(edit?'updateTrx':'addTrx',edit?{...v,id:edit.id}:v);d.close();refresh();notice({...info,title:edit?'Transaction updated':'Transaction complete',after:r.bal})}
    catch(x){fail(d,x.message)}finally{b.disabled=false}};
  d.showModal();(edit||pre?$('#t-tid',d):$('input',$('#t-client',d))).focus()}

// ---------- ledger ----------
function readF(){S.f.from=$('#fFrom').value;S.f.to=$('#fTo').value;S.f.order=$('#fOrder').value}
async function renderLedger(){
  if(!S.clients.some(c=>c.id===S.f.client))S.f.client=0;
  combo($('#fClient'),{items:S.clients,value:S.f.client,all:'All clients',onChange:v=>{S.f.client=+v;renderLedger().catch(e=>toast(e.message,1))}});
  $('#fFrom').value=S.f.from;$('#fTo').value=S.f.to;$('#fOrder').value=S.f.order;
  const q=S.f,all=!q.client,h=await api('history',{client_id:q.client,from:q.from,to:q.to,order:q.order});S.H=h;
  $('#stTitle').textContent=all?'All clients':h.name;
  $('#stSub').textContent=(q.from||q.to?`${q.from?fdate(q.from):'Beginning'} to ${q.to?fdate(q.to):'today'}`:'All dates')+` · ${h.rows.length} transaction${h.rows.length===1?'':'s'}`;
  const pct=h.loan>0?Math.min(100,h.pay/h.loan*100):0,ranged=!!(q.from||q.to);
  $('#stKpis').innerHTML=`<div class="prog-top"><div><small>Paid back in this view</small><div class="prog-amt">${money(h.pay)} <span>/ ${money(h.loan)}</span></div></div><div style="text-align:right"><small>of the amount loaned</small><div class="prog-pct">${pct.toFixed(1)}%</div></div></div><div class="bar"><i style="width:${pct.toFixed(1)}%"></i></div>`;
  $('#stTable').innerHTML=h.rows.length?`<table class="ledger"><thead><tr><th>Date</th><th>Transaction ID</th>${all?'<th>Client</th>':''}<th>Type</th><th class="num">Balance before</th><th class="num">Amount</th><th class="num">Balance after</th><th>Notes</th><th class="noexp"></th></tr></thead><tbody>${h.rows.map(r=>`<tr><td class="nw">${fdate(r.date)}</td><td class="tid">#${esc(r.tid)}</td>${all?`<td class="cl">${esc(r.client)}</td>`:''}<td><span class="tag ${r.amt<0?'l':'p'}">${r.amt<0?'Loan':'Payment'}</span></td><td class="num ${tone(r.before)}">${money(r.before)}</td><td class="num ${r.amt>0?'pos':''}"><span class="op">${r.amt<0?'&minus;':'+'}</span>${money(Math.abs(r.amt))}</td><td class="num ${tone(r.after)}"><span class="op">=</span>${money(r.after)}</td><td class="notes">${r.notes?esc(r.notes):'<span class="mute">-</span>'}</td><td class="noexp act"><button class="ib" data-e="${r.id}" title="Edit" aria-label="Edit #${esc(r.tid)}">${ico('pen')}</button><button class="ib danger" data-d="${r.id}" title="Delete" aria-label="Delete #${esc(r.tid)}">${ico('trash')}</button></td></tr>`).join('')}</tbody></table>`
   :`<div class="empty">${ico('inbox')}<span>No transactions match these filters.</span></div>`;
  $('#stSum').innerHTML=`<div class="sum-box"><div class="sum-r"><span>Total loaned</span><b>${money(h.tloan)}</b></div><div class="sum-r"><span>Less: total paid</span><b>&minus;${money(h.tpay)}</b></div><div class="sum-r total"><span>${all?'Total still owed':'Still owed'} as of ${fdate(h.asof)}</span><b>${money(-h.bal)}</b></div></div>${ranged?'<p class="sum-note">These totals cover all dates, not only the selected range.</p>':''}`;
  $$('[data-e]').forEach(b=>b.onclick=()=>openTrx({edit:S.H.rows.find(r=>r.id===+b.dataset.e)}));
  $$('[data-d]').forEach(b=>b.onclick=()=>delTrx(+b.dataset.d))}
['fFrom','fTo','fOrder'].forEach(i=>$('#'+i).onchange=()=>{readF();renderLedger().catch(e=>toast(e.message,1))});
$('#resetF').onclick=()=>{S.f={client:0,from:'',to:'',order:'asc'};renderLedger().catch(e=>toast(e.message,1))};
$$('[data-r]').forEach(b=>b.onclick=()=>{const n=new Date(),r=b.dataset.r;readF();
  S.f.from=r==='month'?ymd(new Date(n.getFullYear(),n.getMonth(),1)):r==='year'?n.getFullYear()+'-01-01':ymd(new Date(n.getTime()-30*864e5));S.f.to=ymd(n);renderLedger().catch(e=>toast(e.message,1))});

async function delTrx(id){const t=S.H.rows.find(r=>r.id===id);
  if(!await confirmDlg({title:'Delete this transaction?',body:`<b>#${esc(t.tid)}</b>, ${t.amt<0?'loan':'payment'} of <b>${money(Math.abs(t.amt))}</b> for ${esc(t.client)}. The balance will be recalculated. You can undo for 15 seconds after deleting.`,yes:'Delete'}))return;
  try{await api('deleteTrx',{id});undoCard(t);refresh()}catch(e){toast(e.message,1)}}

$('#exportBtn').onclick=async()=>{if(!S.H)return;const n=S.H.name.replace(/[\\/:*?"<>|]/g,'-'),d=new Date(),p=x=>String(x).padStart(2,'0');
  try{const c=await html2canvas($('#exp'),{scale:2,backgroundColor:'#ffffff',ignoreElements:e=>e.classList&&e.classList.contains('noexp'),
      onclone:doc=>{$('.exp-only',doc).style.display='block';$$('.tw',doc).forEach(e=>e.style.overflow='visible');const t=$('table',doc);if(t){const x=$('#exp',doc);x.style.maxWidth='none';x.style.width=Math.max(x.offsetWidth,t.scrollWidth)+'px'}}});
    const a=document.createElement('a');a.href=c.toDataURL('image/jpeg',.95);a.download=`Transaction_History_${n}_as_of_${p(d.getMonth()+1)}-${p(d.getDate())}-${d.getFullYear()}.jpg`;a.click();toast('JPEG exported.')}
  catch(e){toast('Could not export the image. Check your internet connection (html2canvas loads from a CDN).',1)}};

$('#xlsBtn').onclick=()=>{if(!S.H)return;
  if(typeof XLSX==='undefined')return toast('Could not load the Excel library. Check your internet connection (it loads from a CDN).',1);
  const h=S.H,q=S.f,P='₱#,##0.00;[Red]-₱#,##0.00',P2='₱#,##0.00;-₱#,##0.00',num=(v,z=P)=>({t:'n',v,z}),
    ser=d=>{const [y,m,x]=d.split('-').map(Number);return Math.round((Date.UTC(y,m-1,x)-Date.UTC(1899,11,30))/864e5)};
  const range=q.from||q.to?`${q.from?fdate(q.from):'Beginning'} to ${q.to?fdate(q.to):'today'}`:'All dates';
  const aoa=[['Ragnarr Agrivet Trading'],['Tarum, Libmanan, Camarines Sur'],['Owned by: Lorenzo Gregorio B. Nuñez III'],[],['Client',h.name],['Date range',range],['As of',fdate(h.asof)],[],
    ['Date','Transaction ID','Client','Type','Balance before','Amount','Balance after','Notes']],hr=aoa.length-1;
  h.rows.forEach(r=>aoa.push([{t:'n',v:ser(r.date),z:'mmm d, yyyy'},r.tid,r.client,r.amt<0?'Loan':'Payment',num(r.before),num(r.amt),num(r.after),r.notes||'']));
  aoa.push([],['','','','','Total loaned','',num(h.tloan,P2)],['','','','','Less: total paid','',num(-h.tpay,P2)],['','','','','Still owed as of '+fdate(h.asof),'',num(-h.bal,P2)]);
  if(q.from||q.to)aoa.push(['','','','','Totals above cover all dates, not only the selected range.']);
  const ws=XLSX.utils.aoa_to_sheet(aoa);
  ws['!cols']=[{wch:14},{wch:22},{wch:30},{wch:10},{wch:18},{wch:18},{wch:18},{wch:40}];
  if(h.rows.length)ws['!autofilter']={ref:XLSX.utils.encode_range({s:{r:hr,c:0},e:{r:hr+h.rows.length,c:7}})};
  const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Transactions');
  const n=h.name.replace(/[\\/:*?"<>|]/g,'-'),d=new Date(),p=x=>String(x).padStart(2,'0');
  try{XLSX.writeFile(wb,`Transactions_${n}_as_of_${p(d.getMonth()+1)}-${p(d.getDate())}-${d.getFullYear()}.xlsx`);toast('Excel file exported.')}
  catch(e){toast('Could not create the Excel file: '+e.message,1)}};

show('dash');
</script>
</body>
</html>