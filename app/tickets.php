<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin', 'doctor', 'receptionist', 'nurse', 'accountant', 'pharmacist', 'lab_technician']);
$tid = require_tenant();
check_tenant_active($pdo);
$isAdmin = $user['role'] === 'hospital_admin';

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'General')) ?: 'General';
        $priority = (string)($_POST['priority'] ?? 'normal');
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $priority = 'normal';
        $message = trim((string)($_POST['message'] ?? ''));
        if ($subject === '') { flash('error', 'Give your ticket a subject.'); redirect('tickets.php'); }
        $ins = $pdo->prepare('INSERT INTO support_tickets(tenant_id,user_id,subject,category,priority,status,created_at,updated_at) VALUES(?,?,?,?,?,"open",NOW(),NOW())');
        $ins->execute([$tid, (int)$user['id'], $subject, $category, $priority]);
        $ticketId = (int)$pdo->lastInsertId();
        if ($message !== '') {
            $r = $pdo->prepare('INSERT INTO support_ticket_replies(ticket_id,user_id,message,created_at) VALUES(?,?,?,NOW())');
            $r->execute([$ticketId, (int)$user['id'], $message]);
        }
        audit_log($pdo, 'ticket.create', 'support_ticket', $ticketId, $subject);
        flash('success', 'Support ticket created. Our platform team will reply here.');
        redirect('tickets.php?view=' . $ticketId);
    }

    if ($action === 'reply') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));
        $vs = $pdo->prepare('SELECT id, status FROM support_tickets WHERE id=? AND tenant_id=?');
        $vs->execute([$ticketId, $tid]);
        $t = $vs->fetch();
        if (!$t || $message === '') { flash('error', 'Ticket not found, or the reply was empty.'); redirect('tickets.php'); }
        if ($t['status'] === 'closed') { flash('error', 'This ticket is closed. Open a new ticket if you still need help.'); redirect('tickets.php?view=' . $ticketId); }
        $r = $pdo->prepare('INSERT INTO support_ticket_replies(ticket_id,user_id,message,created_at) VALUES(?,?,?,NOW())');
        $r->execute([$ticketId, (int)$user['id'], $message]);
        $u = $pdo->prepare('UPDATE support_tickets SET updated_at=NOW() WHERE id=? AND tenant_id=?');
        $u->execute([$ticketId, $tid]);
        audit_log($pdo, 'ticket.reply', 'support_ticket', $ticketId, '');
        flash('success', 'Reply added.');
        redirect('tickets.php?view=' . $ticketId);
    }

    if ($action === 'close') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $vs = $pdo->prepare('SELECT user_id FROM support_tickets WHERE id=? AND tenant_id=?');
        $vs->execute([$ticketId, $tid]);
        $owner = $vs->fetchColumn();
        if ($owner === false || (!$isAdmin && (int)$owner !== (int)$user['id'])) {
            flash('error', 'Only the ticket creator or a hospital admin can close this ticket.');
            redirect('tickets.php');
        }
        $u = $pdo->prepare('UPDATE support_tickets SET status="closed", updated_at=NOW() WHERE id=? AND tenant_id=?');
        $u->execute([$ticketId, $tid]);
        audit_log($pdo, 'ticket.close', 'support_ticket', $ticketId, '');
        flash('success', 'Ticket closed.');
        redirect('tickets.php?view=' . $ticketId);
    }

    flash('error', 'Unknown ticket action.');
    redirect('tickets.php');
}

/* ---------------- List + detail ---------------- */
$ls = $pdo->prepare('SELECT t.*, u.name AS creator_name FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.tenant_id=? ORDER BY t.updated_at DESC, t.id DESC LIMIT 100');
$ls->execute([$tid]);
$tickets = $ls->fetchAll();

$view = null; $replies = [];
if (!empty($_GET['view'])) {
    $vs = $pdo->prepare('SELECT t.*, u.name AS creator_name, u.email AS creator_email FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.tenant_id=?');
    $vs->execute([(int)$_GET['view'], $tid]);
    $view = $vs->fetch() ?: null;
    if ($view) {
        $rs = $pdo->prepare('SELECT r.*, u.name AS author_name FROM support_ticket_replies r JOIN users u ON u.id=r.user_id WHERE r.ticket_id=? ORDER BY r.created_at ASC, r.id ASC');
        $rs->execute([(int)$view['id']]);
        $replies = $rs->fetchAll();
    }
}

$pageTitle = 'Support Tickets';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card mb-4"><div class="card-header">Open a ticket</div><div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="mb-2"><label class="form-label">Subject *</label><input name="subject" class="form-control" required maxlength="200"></div>
        <div class="row g-2">
          <div class="col-6 mb-2"><label class="form-label">Category</label><select name="category" class="form-select"><option>General</option><option>Billing</option><option>Technical</option><option>Account</option><option>Feature request</option></select></div>
          <div class="col-6 mb-2"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
        </div>
        <div class="mb-3"><label class="form-label">Describe the issue</label><textarea name="message" class="form-control" rows="4"></textarea></div>
        <button class="btn btn-primary w-100"><i class="bi bi-life-preserver me-1"></i>Submit ticket</button>
      </form>
    </div></div>

    <div class="card"><div class="card-header">Your hospital's tickets</div>
      <div class="list-group list-group-flush">
      <?php if (!$tickets): ?><div class="empty-state">No tickets yet.</div><?php endif; ?>
      <?php foreach ($tickets as $t): ?>
        <a class="list-group-item list-group-item-action <?= $view && (int)$view['id'] === (int)$t['id'] ? 'active' : '' ?>" href="tickets.php?view=<?= (int)$t['id'] ?>">
          <div class="d-flex justify-content-between gap-2"><strong><?= e($t['subject']) ?></strong><?= status_badge((string)$t['status']) ?></div>
          <small class="<?= $view && (int)$view['id'] === (int)$t['id'] ? '' : 'text-muted' ?>"><?= e($t['category']) ?> · <?= e(ucfirst((string)$t['priority'])) ?> priority · by <?= e($t['creator_name']) ?> · updated <?= fmt_datetime($t['updated_at']) ?></small>
        </a>
      <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <?php if (!$view): ?>
    <div class="card"><div class="empty-state"><img src="../assets/img/empty.svg" alt=""><p>Select a ticket to read the conversation, or open a new one on the left.</p></div></div>
    <?php else: ?>
    <div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
      <span>#<?= (int)$view['id'] ?> — <?= e($view['subject']) ?></span><?= status_badge((string)$view['status']) ?>
    </div>
    <div class="card-body">
      <p class="small text-muted"><?= e($view['category']) ?> · <?= e(ucfirst((string)$view['priority'])) ?> priority · opened by <?= e($view['creator_name']) ?> on <?= fmt_datetime($view['created_at']) ?></p>
      <?php if (!$replies): ?><p class="text-muted">No messages yet.</p><?php endif; ?>
      <?php foreach ($replies as $r): $mine = (int)$r['user_id'] === (int)$user['id']; ?>
      <div class="d-flex gap-2 mb-3 <?= $mine ? 'flex-row-reverse' : '' ?>">
        <span class="avatar-sm"><?= e(initials((string)$r['author_name'])) ?></span>
        <div class="p-3 rounded-3 <?= $mine ? 'bg-primary text-white' : 'bg-light border' ?>" style="max-width:85%">
          <div class="small fw-semibold"><?= e($r['author_name']) ?> <span class="opacity-75 fw-normal">· <?= fmt_datetime($r['created_at']) ?></span></div>
          <div style="white-space:pre-wrap"><?= e($r['message']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <hr>
      <?php if ($view['status'] !== 'closed'): ?>
      <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="<?= (int)$view['id'] ?>">
        <label class="form-label">Add a reply</label>
        <textarea name="message" class="form-control mb-2" rows="3" required></textarea>
        <button class="btn btn-primary">Post reply</button>
      </form>
      <?php if ($isAdmin || (int)$view['user_id'] === (int)$user['id']): ?>
      <form method="post" data-confirm="Close this ticket?"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="ticket_id" value="<?= (int)$view['id'] ?>"><button class="btn btn-outline-secondary btn-sm">Close ticket</button></form>
      <?php endif; ?>
      <?php else: ?><p class="text-muted mb-0">This ticket is closed.</p><?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
