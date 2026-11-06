<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$secure = (bool)($config['app']['secure_cookies'] ?? true);
session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax', 'secure'=>$secure]);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function clearRememberCookie(bool $secure): void { setcookie('due_flow_remember', '', ['expires'=>time()-3600, 'path'=>'/', 'httponly'=>true, 'samesite'=>'Lax', 'secure'=>$secure]); }
function rememberExpiry(): string { return date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 365 * 100); }
function restoreRememberedLogin(PDO $pdo, bool $secure): void {
    if (!empty($_SESSION['user_id']) || empty($_COOKIE['due_flow_remember'])) return;
    $token = (string)$_COOKIE['due_flow_remember']; $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT user_id FROM remember_tokens WHERE token_hash = ? AND expires_at > ?');
    $stmt->execute([$hash, now()]);
    $userId = $stmt->fetchColumn();
    if ($userId !== false) {
        $expires = rememberExpiry();
        $pdo->prepare('UPDATE remember_tokens SET expires_at = ? WHERE token_hash = ?')->execute([$expires,$hash]);
        setcookie('due_flow_remember', $token, ['expires'=>strtotime($expires), 'path'=>'/', 'httponly'=>true, 'samesite'=>'Lax', 'secure'=>$secure]);
        $_SESSION['user_id'] = $userId;
    }
}
restoreRememberedLogin($pdo, $secure);
function requireLogin(): void { if (empty($_SESSION['user_id'])) jsonResponse(['error'=>'Authentication required'], 401); }
function requireCsrf(): void { if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) jsonResponse(['error'=>'Invalid request token'], 419); }
function queueSync(PDO $pdo, string $type, string $id, array $payload): void {
    $pdo->prepare('DELETE FROM sync_queue WHERE entity_type = ? AND entity_id = ? AND synced_at IS NULL')->execute([$type, $id]);
    $stmt = $pdo->prepare('INSERT INTO sync_queue (entity_type, entity_id, payload, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$type, $id, json_encode($payload, JSON_UNESCAPED_UNICODE), now()]);
}
function customerPayload(array $c): array { return ['customer_id'=>$c['id'],'name'=>$c['name'],'phone'=>$c['phone'],'original_due'=>(float)$c['original_due'],'outstanding_due'=>(float)$c['outstanding_due'],'notes'=>$c['notes'],'created_at'=>$c['created_at'],'updated_at'=>$c['updated_at'],'archived_at'=>$c['archived_at']]; }

$action = (string)($_GET['action'] ?? '');
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? '')); $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?'); $stmt->execute([$email]); $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) jsonResponse(['error'=>'Invalid email or password'], 422);
    session_regenerate_id(true); $_SESSION['user_id'] = $user['id'];
    if (($_POST['remember'] ?? '') === 'yes') {
        $token = bin2hex(random_bytes(32)); $expires = rememberExpiry();
        $pdo->prepare('INSERT INTO remember_tokens (token_hash,user_id,expires_at,created_at) VALUES (?,?,?,?)')->execute([hash('sha256', $token),$user['id'],$expires,now()]);
        setcookie('due_flow_remember', $token, ['expires'=>strtotime($expires), 'path'=>'/', 'httponly'=>true, 'samesite'=>'Lax', 'secure'=>$secure]);
    } else {
        clearRememberCookie($secure);
    }
    jsonResponse(['ok'=>true]);
}
if ($action === 'logout') {
    if (!empty($_COOKIE['due_flow_remember'])) $pdo->prepare('DELETE FROM remember_tokens WHERE token_hash = ?')->execute([hash('sha256', (string)$_COOKIE['due_flow_remember'])]);
    clearRememberCookie($secure); session_destroy(); header('Location: ' . ($config['app']['base_path'] ?? '') . '/'); exit;
}
if (str_starts_with($action, 'api/')) {
    requireLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') requireCsrf();
    if ($action === 'api/customers') {
        $archived = ($_GET['archived'] ?? '0') === '1';
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE ' . ($archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL') . ' ORDER BY outstanding_due DESC, created_at DESC');
        $stmt->execute(); jsonResponse(['customers'=>$stmt->fetchAll()]);
    }
    if ($action === 'api/customer/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = trim((string)($_POST['id'] ?? '')); $name = trim((string)($_POST['name'] ?? '')); $phone = trim((string)($_POST['phone'] ?? ''));
        $due = filter_var($_POST['due'] ?? null, FILTER_VALIDATE_FLOAT); $notes = trim((string)($_POST['notes'] ?? ''));
        if ($name === '' || $phone === '' || $due === false || $due < 0) jsonResponse(['error'=>'Name, phone and a valid non-negative due are required.'], 422);
        $pdo->beginTransaction();
        try {
            $time = now();
            if ($id === '') {
                $id = uuid(); $stmt = $pdo->prepare('INSERT INTO customers (id,name,phone,original_due,outstanding_due,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$id,$name,$phone,$due,$due,$notes,$time,$time]);
                if ($due > 0) { $tx = uuid(); $pdo->prepare('INSERT INTO transactions (id,customer_id,type,amount,occurred_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$tx,$id,'given',$due,$time,$time]); queueSync($pdo,'transaction',$tx,['transaction_id'=>$tx,'customer_id'=>$id,'type'=>'given','amount'=>$due,'occurred_at'=>$time]); }
            } else {
                $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?' . $lock); $stmt->execute([$id]); $old = $stmt->fetch();
                if (!$old) throw new RuntimeException('Customer not found.');
                $diff = round((float)$old['outstanding_due'] - $due, 2);
                $stmt = $pdo->prepare('UPDATE customers SET name=?, phone=?, outstanding_due=?, notes=?, updated_at=? WHERE id=?');
                $stmt->execute([$name,$phone,$due,$notes,$time,$id]);
                if ($diff > 0) {
                    $confirm = ($_POST['confirm_payment'] ?? '') === 'yes';
                    if ($confirm) { $tx = uuid(); $pdo->prepare('INSERT INTO transactions (id,customer_id,type,amount,occurred_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$tx,$id,'received',$diff,$time,$time]); queueSync($pdo,'transaction',$tx,['transaction_id'=>$tx,'customer_id'=>$id,'type'=>'received','amount'=>$diff,'occurred_at'=>$time]); }
                }
            }
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?'); $stmt->execute([$id]); $customer = $stmt->fetch();
            queueSync($pdo,'customer',$id,customerPayload($customer)); $pdo->commit(); jsonResponse(['ok'=>true,'customer'=>$customer]);
        } catch (Throwable $e) { $pdo->rollBack(); jsonResponse(['error'=>$e->getMessage()], 422); }
    }
    if ($action === 'api/customer/archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (string)($_POST['id'] ?? ''); $stmt = $pdo->prepare('UPDATE customers SET archived_at=?, updated_at=? WHERE id=? AND outstanding_due=0'); $stmt->execute([now(),now(),$id]);
        if ($stmt->rowCount() !== 1) jsonResponse(['error'=>'Only a zero-balance customer can be archived.'], 422);
        queueSync($pdo,'customer',$id,['customer_id'=>$id,'status'=>'archived','archived_at'=>now()]); jsonResponse(['ok'=>true]);
    }
    if ($action === 'api/customer/paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') jsonResponse(['error'=>'Customer ID is required.'], 422);
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND archived_at IS NULL' . $lock);
            $stmt->execute([$id]);
            $customer = $stmt->fetch();
            if (!$customer) throw new RuntimeException('Active customer not found.');
            $time = now();
            $amount = round((float)$customer['outstanding_due'], 2);
            if ($amount > 0) {
                $tx = uuid();
                $pdo->prepare('INSERT INTO transactions (id,customer_id,type,amount,occurred_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$tx,$id,'received',$amount,$time,$time]);
                queueSync($pdo,'transaction',$tx,['transaction_id'=>$tx,'customer_id'=>$id,'type'=>'received','amount'=>$amount,'occurred_at'=>$time]);
            }
            $pdo->prepare('UPDATE customers SET outstanding_due=0, archived_at=?, updated_at=? WHERE id=?')->execute([$time,$time,$id]);
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?'); $stmt->execute([$id]); $customer = $stmt->fetch();
            queueSync($pdo,'customer',$id,customerPayload($customer));
            $pdo->commit();
            jsonResponse(['ok'=>true,'customer'=>$customer]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['error'=>$e->getMessage()], 422);
        }
    }
    if ($action === 'api/reports') {
        $week = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['week'] ?? '')) ? $_GET['week'] : date('Y-m-d');
        $start = new DateTimeImmutable($week); $start = $start->modify('monday this week')->setTime(0,0); $end = $start->modify('+7 days');
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='given' THEN amount ELSE 0 END),0) given, COALESCE(SUM(CASE WHEN type='received' THEN amount ELSE 0 END),0) received, COALESCE(SUM(CASE WHEN type='received' THEN 1 ELSE 0 END),0) paid_count FROM transactions WHERE occurred_at >= ? AND occurred_at < ?");
        $stmt->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]); $totals = $stmt->fetch();
        $outstanding = (float)$pdo->query("SELECT COALESCE(SUM(outstanding_due),0) total FROM customers WHERE archived_at IS NULL")->fetch()['total'];
        $report = ['week_start'=>$start->format('Y-m-d'),'week_end'=>$end->modify('-1 day')->format('Y-m-d'),'given'=>(float)$totals['given'],'received'=>(float)$totals['received'],'paid'=>(float)$totals['received'],'paid_count'=>(int)$totals['paid_count'],'outstanding'=>$outstanding];
        queueSync($pdo, 'weekly_report', $report['week_start'], $report);
        jsonResponse($report);
    }
    if ($action === 'api/ranking') {
        $rows = $pdo->query("SELECT c.*, COALESCE(SUM(CASE WHEN t.type='given' THEN t.amount ELSE 0 END),0) total_given, COALESCE(SUM(CASE WHEN t.type='received' THEN t.amount ELSE 0 END),0) total_repaid, MAX(CASE WHEN t.type='given' THEN t.occurred_at END) latest_borrowing, MAX(CASE WHEN t.type='received' THEN t.occurred_at END) latest_payment, COUNT(CASE WHEN t.type='received' THEN 1 END) payment_count FROM customers c LEFT JOIN transactions t ON t.customer_id=c.id GROUP BY c.id ORDER BY c.archived_at IS NULL DESC, total_repaid DESC")->fetchAll();
        $scored=[]; foreach ($rows as $r) { $given=(float)$r['total_given']; $repaid=(float)$r['total_repaid']; $ratio=$given>0?min(1,$repaid/$given):0; $recent=$r['latest_payment'] && strtotime($r['latest_payment']) >= strtotime('-90 days') ? 1 : 0; $history=(int)$r['payment_count'] >= 2 ? 1 : 0; $score=round($ratio*60+$recent*20+$history*20- min(20,(float)$r['outstanding_due']>0?10:0),2); $r['score']=$score; $r['enough_history']=((int)$r['payment_count']>=2 && $given>0); $scored[]=$r; }
        usort($scored, fn($a,$b)=>$b['score']<=>$a['score']); $rank=0;$prev=null;$index=0; foreach($scored as &$r){$index++; if($prev===null||$r['score']<$prev)$rank=$index; $r['rank']=$r['enough_history']?$rank:null; $prev=$r['score']; queueSync($pdo, 'performance', $r['id'], $r);} jsonResponse(['ranking'=>$scored]);
    }
    jsonResponse(['error'=>'Unknown action'], 404);
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b6bcb"><link rel="manifest" href="manifest.webmanifest"><title><?= esc($config['app']['name'] ?? 'Due Flow') ?></title><link rel="stylesheet" href="assets/style.css"></head>
<body>
<?php if (empty($_SESSION['user_id'])): ?><main class="auth"><h1>Due Flow</h1><p>Private customer due management</p><form id="login"><input type="email" name="email" placeholder="Email" required autocomplete="username"><input type="password" name="password" placeholder="Password" required autocomplete="current-password"><label><input type="checkbox" name="remember" value="yes" checked> Keep me signed in on this device</label><button>Sign in</button><output></output></form></main>
<?php else: ?><header><strong>Due Flow</strong><a href="?action=logout">Sign out</a></header><main><section id="dashboard" class="view active"><div class="section-head"><h1>Customers</h1><button id="add">+ Add customer</button></div><div class="tabs"><button class="tab active" data-tab="active">Active</button><button class="tab" data-tab="paid">Paid</button></div><div id="customers"></div></section><section id="reports" class="view"><h1>Weekly summary</h1><div class="week-nav"><button id="prev-week">‹</button><strong id="week-label"></strong><button id="next-week">›</button></div><div class="metrics"><article><small>Money given</small><b id="given">৳0</b></article><article><small>Paid this week</small><b id="paid">৳0</b><small><span id="paid-count">0</span> payment(s)</small></article><article><small>Outstanding due</small><b id="outstanding">৳0</b></article></div><p class="hint">Weeks run Monday–Sunday. Paid amounts are included only after confirmation.</p></section><section id="ranking" class="view"><h1>Performance ranking</h1><p class="hint">Score: repayment ratio 60%, recent activity 20%, consistent history 20%; outstanding debt is a small penalty. Ties share positions.</p><div id="ranking-list"></div></section></main><nav class="bottom"><button class="active" data-view="dashboard">Customers</button><button data-view="reports">Reports</button><button data-view="ranking">Ranking</button></nav><dialog id="customer-dialog"><form method="dialog" id="customer-form"><h2 id="form-title">Add customer</h2><input type="hidden" name="id"><label>Name<input name="name" required maxlength="160"></label><label>Phone<div class="phone-row"><input name="phone" type="tel" required maxlength="40" inputmode="tel"><button type="button" id="contacts" title="Choose a contact">+</button></div></label><label>Outstanding due (৳)<input name="due" type="number" min="0" step="0.01" required inputmode="decimal"></label><label>Notes<textarea name="notes" rows="3" maxlength="2000"></textarea></label><output></output><div class="dialog-actions"><button type="button" class="secondary" id="cancel">Cancel</button><button>Save</button></div></form></dialog><?php endif; ?><script>window.DUE_FLOW={csrf:<?= json_encode($_SESSION['csrf']) ?>,base:<?= json_encode($config['app']['base_path'] ?? '') ?>};</script><script src="assets/app.js"></script></body></html>
