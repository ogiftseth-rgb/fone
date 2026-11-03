<?php
declare(strict_types=1);

/**
 * FoneFlex operational modules added in v0.2.
 * Loaded by app/bootstrap.php after the core routes.
 */
function ops_require_org_record(string $table, int $id, int $organizationId): ?array
{
    $allowed = ['agreements', 'customers'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported record type.');
    }
    return get_one("SELECT * FROM {$table} WHERE id=? AND organization_id=?", [$id, $organizationId]);
}

function ops_money_row(float $amount, string $class = 'amount'): string
{
    return '<span class="' . h($class) . '">' . h(money($amount)) . '</span>';
}

function handle_operations_route(string $route): bool
{
    $u = require_login();
    $oid = org_id();

    if ($route === 'team') {
        require_role(['owner', 'admin']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user') {
            $id = (int)($_POST['user_id'] ?? 0);
            $name = post_string('full_name', 160);
            $email = strtolower(post_string('email', 190));
            $phone = clean_phone(post_string('phone', 30));
            $role = post_string('role', 30);
            $active = isset($_POST['is_active']) ? 1 : 0;
            $password = (string)($_POST['password'] ?? '');
            $allowedRoles = ['admin', 'sales', 'collections', 'cashier', 'accountant', 'auditor'];
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedRoles, true)) {
                throw new RuntimeException('Enter a valid name, email address, and staff role.');
            }
            if ($id === (int)$u['id'] && (!$active || $role !== $u['role'])) {
                throw new RuntimeException('You cannot deactivate yourself or change your own role.');
            }
            $existing = get_one('SELECT id, organization_id, role FROM users WHERE email=?', [$email]);
            if ($existing && (int)$existing['id'] !== $id) {
                throw new RuntimeException('That email address is already in use.');
            }
            if ($id > 0) {
                $target = get_one('SELECT * FROM users WHERE id=? AND organization_id=?', [$id, $oid]);
                if (!$target) throw new RuntimeException('Staff account not found in this business.');
                if ($target['role'] === 'owner') throw new RuntimeException('The business owner account cannot be changed from this screen.');
                if ($password !== '' && strlen($password) < 12) throw new RuntimeException('A new password must contain at least 12 characters.');
                if ($password !== '') {
                    db()->prepare('UPDATE users SET full_name=?,email=?,phone=?,role=?,is_active=?,password_hash=? WHERE id=? AND organization_id=?')
                        ->execute([$name, $email, $phone ?: null, $role, $active, password_hash($password, PASSWORD_DEFAULT), $id, $oid]);
                } else {
                    db()->prepare('UPDATE users SET full_name=?,email=?,phone=?,role=?,is_active=? WHERE id=? AND organization_id=?')
                        ->execute([$name, $email, $phone ?: null, $role, $active, $id, $oid]);
                }
                audit('staff.updated', 'user', $id, ['role' => $role, 'is_active' => $active]);
                flash('success', 'Staff account updated.');
            } else {
                if (strlen($password) < 12) throw new RuntimeException('Set a password of at least 12 characters.');
                db()->prepare('INSERT INTO users(organization_id,full_name,email,phone,password_hash,role,is_active) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$oid, $name, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
                $newId = (int)db()->lastInsertId();
                audit('staff.created', 'user', $newId, ['role' => $role]);
                flash('success', 'Staff account created.');
            }
            redirect('?r=team');
        }
        $editId = (int)($_GET['edit'] ?? 0);
        $edit = $editId ? get_one('SELECT id,full_name,email,phone,role,is_active FROM users WHERE id=? AND organization_id=?', [$editId, $oid]) : null;
        $rows = get_all('SELECT id,full_name,email,phone,role,is_active,last_login_at,created_at FROM users WHERE organization_id=? ORDER BY CASE role WHEN "owner" THEN 0 ELSE 1 END,full_name', [$oid]);
        layout_start('Team & access', 'team'); alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">BUSINESS ACCESS CONTROL</div><h1>Team & access</h1><p class="muted">Create staff logins and assign permissions using predefined business roles.</p></div><a class="btn primary" href="?r=team">＋ Add staff member</a></div>';
        echo '<div class="section-grid"><div class="panel"><h3>' . ($edit ? 'Edit staff account' : 'Create staff account') . '</h3><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" value="' . (int)($edit['id'] ?? 0) . '"><div class="form-grid"><div><label>Full name</label><input required name="full_name" maxlength="160" value="' . h($edit['full_name'] ?? '') . '"></div><div><label>Email / username</label><input required type="email" name="email" value="' . h($edit['email'] ?? '') . '"></div><div><label>Phone number</label><input name="phone" value="' . h($edit['phone'] ?? '') . '"></div><div><label>Role</label><select name="role" required>';
        foreach (['admin'=>'Administrator','sales'=>'Sales Agent','collections'=>'Collections Officer','cashier'=>'Cashier','accountant'=>'Accountant','auditor'=>'Auditor'] as $value => $label) {
            echo '<option value="' . h($value) . '" ' . (($edit['role'] ?? 'sales') === $value ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select></div><div class="span-2"><label>' . ($edit ? 'New password (leave blank to keep current)' : 'Temporary password') . '</label><input type="password" name="password" ' . ($edit ? '' : 'required') . ' minlength="12" autocomplete="new-password"></div></div><label class="checkline"><input type="checkbox" name="is_active" value="1" ' . (($edit['is_active'] ?? 1) ? 'checked' : '') . '> Account is active</label><button class="btn primary">' . ($edit ? 'Save changes' : 'Create staff login') . '</button>' . ($edit ? ' <a class="btn" href="?r=team">Cancel</a>' : '') . '<p class="hint">The owner account is managed separately. Never share staff passwords; each person should use an individual login.</p></form></div>';
        echo '<div class="panel"><h3>Role guide</h3><div class="health-list"><div><span>Administrator</span><b>Business setup and staff</b></div><div><span>Sales Agent</span><b>Customers, stock, agreements</b></div><div><span>Collections Officer</span><b>Follow-ups and arrears</b></div><div><span>Cashier</span><b>Record authorized payments</b></div><div><span>Accountant</span><b>Finance and reconciliation</b></div><div><span>Auditor</span><b>Read-only records</b></div></div><div class="info-note">Role permissions are enforced server-side. Platform administrator accounts cannot be created by a merchant.</div></div></div>';
        echo '<div class="panel"><div class="panel-head"><h3>Staff register</h3><input class="search-input" data-table-search="teamTable" placeholder="Search staff…"></div><div class="table-wrap"><table id="teamTable"><thead><tr><th>Staff member</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Account created</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><b>' . h($r['full_name']) . '</b><small>' . h($r['email']) . ' · ' . h($r['phone'] ?? 'No phone') . '</small></td><td>' . h(ucwords(str_replace('_',' ',$r['role']))) . '</td><td><span class="pill ' . ($r['is_active'] ? 'success' : 'danger') . '">' . ($r['is_active'] ? 'Active' : 'Inactive') . '</span></td><td>' . (!empty($r['last_login_at']) ? date('d M Y H:i', strtotime($r['last_login_at'])) : 'Never') . '</td><td>' . date('d M Y', strtotime($r['created_at'])) . '</td><td>' . ($r['role'] === 'owner' ? '<span class="pill neutral">Owner</span>' : '<a class="tiny-btn" href="?r=team&edit=' . (int)$r['id'] . '">Edit</a>') . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6" class="empty">No staff accounts found.</td></tr>';
        echo '</tbody></table></div></div>';
        layout_end(); return true;
    }

    if ($route === 'collections') {
        require_role(['owner', 'admin', 'sales', 'collections', 'accountant', 'auditor']);
        $openDisputes = (int)(get_one('SELECT COUNT(*) c FROM customer_disputes WHERE organization_id=? AND status IN ("open","reviewing")', [$oid])['c'] ?? 0);
        $summary = get_one('SELECT COUNT(DISTINCT a.id) cases, COALESCE(SUM(GREATEST(s.principal_due-s.principal_paid+s.interest_due-s.interest_paid,0)),0) arrears, COUNT(*) overdue_installments FROM installment_schedules s JOIN agreements a ON a.id=s.agreement_id WHERE s.organization_id=? AND s.due_date<CURDATE() AND s.status IN ("pending","partial") AND a.status NOT IN ("settled","terminated","draft")', [$oid]);
        $rows = get_all('SELECT a.id agreement_id,a.agreement_no,c.full_name,c.phone,MIN(s.due_date) oldest_due,COUNT(s.id) overdue_count,SUM(GREATEST(s.principal_due-s.principal_paid+s.interest_due-s.interest_paid,0)) overdue_amount,MAX(DATEDIFF(CURDATE(),s.due_date)) days_late, (SELECT COUNT(*) FROM customer_disputes d WHERE d.agreement_id=a.id AND d.status IN ("open","reviewing")) dispute_count FROM agreements a JOIN customers c ON c.id=a.customer_id JOIN installment_schedules s ON s.agreement_id=a.id AND s.organization_id=a.organization_id WHERE a.organization_id=? AND s.due_date<CURDATE() AND s.status IN ("pending","partial") AND a.status NOT IN ("settled","terminated","draft") GROUP BY a.id,a.agreement_no,c.full_name,c.phone ORDER BY days_late DESC,oldest_due ASC LIMIT 300', [$oid]);
        layout_start('Collections & arrears', 'collections'); alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">REPAYMENT FOLLOW-UP</div><h1>Collections & arrears</h1><p class="muted">Review overdue installments, record customer contact, promises to pay, and disputes.</p></div></div>';
        echo '<div class="kpi-grid"><div class="kpi-card"><span>Overdue agreements</span><strong>' . (int)$summary['cases'] . '</strong></div><div class="kpi-card"><span>Total overdue installments</span><strong>' . (int)$summary['overdue_installments'] . '</strong></div><div class="kpi-card"><span>Overdue amount</span><strong>' . money((float)$summary['arrears']) . '</strong></div><div class="kpi-card"><span>Open disputes</span><strong>' . $openDisputes . '</strong></div></div>';
        echo '<div class="info-note"><b>Enforcement safeguard:</b> this module records collections activity only. A phone must not be automatically restricted because an account appears overdue. Verify payments, pending transactions, notices, disputes, the agreement terms, legal safeguards and provider approval separately.</div>';
        echo '<div class="panel"><div class="panel-head"><h3>Overdue accounts</h3><input class="search-input" data-table-search="arrearsTable" placeholder="Search customer, phone or agreement…"></div><div class="table-wrap"><table id="arrearsTable"><thead><tr><th>Customer / agreement</th><th>Contact</th><th>Oldest due date</th><th>Installments</th><th>Days late</th><th>Overdue amount</th><th>Disputes</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><b>' . h($r['full_name']) . '</b><small>' . h($r['agreement_no']) . '</small></td><td>' . h($r['phone']) . '</td><td>' . date('d M Y', strtotime($r['oldest_due'])) . '</td><td>' . (int)$r['overdue_count'] . '</td><td><span class="pill ' . ((int)$r['days_late'] > 30 ? 'danger' : 'warning') . '">' . (int)$r['days_late'] . ' days</span></td><td class="amount">' . money((float)$r['overdue_amount']) . '</td><td>' . (int)$r['dispute_count'] . '</td><td><a class="tiny-btn" href="?r=collection-case&id=' . (int)$r['agreement_id'] . '">Open case</a></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="8" class="empty">No overdue installments. This table updates using the server Africa/Nairobi business date.</td></tr>';
        echo '</tbody></table></div></div>';
        $disputes = get_all('SELECT d.*,c.full_name,a.agreement_no FROM customer_disputes d JOIN customers c ON c.id=d.customer_id JOIN agreements a ON a.id=d.agreement_id WHERE d.organization_id=? ORDER BY d.opened_at DESC LIMIT 30', [$oid]);
        echo '<div class="panel"><div class="panel-head"><h3>Recent customer disputes</h3></div><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Customer</th><th>Agreement</th><th>Category</th><th>Status</th><th>Opened</th></tr></thead><tbody>';
        foreach ($disputes as $d) echo '<tr><td><b>' . h($d['dispute_reference']) . '</b></td><td>' . h($d['full_name']) . '</td><td>' . h($d['agreement_no']) . '</td><td>' . h(str_replace('_',' ',$d['category'])) . '</td><td><span class="pill ' . (in_array($d['status'],['open','reviewing'],true) ? 'warning' : 'success') . '">' . h($d['status']) . '</span></td><td>' . date('d M Y', strtotime($d['opened_at'])) . '</td></tr>';
        if (!$disputes) echo '<tr><td colspan="6" class="empty">No customer disputes have been recorded.</td></tr>';
        echo '</tbody></table></div></div>';
        layout_end(); return true;
    }

    if ($route === 'collection-case') {
        require_role(['owner', 'admin', 'sales', 'collections', 'accountant', 'auditor']);
        $aid = (int)($_GET['id'] ?? 0);
        $agreement = get_one('SELECT a.*,c.full_name,c.phone,c.customer_no,c.email,i.manufacturer,i.model,i.imei1 FROM agreements a JOIN customers c ON c.id=a.customer_id JOIN inventory_items i ON i.id=a.inventory_item_id WHERE a.id=? AND a.organization_id=?', [$aid, $oid]);
        if (!$agreement) throw new RuntimeException('Hire-purchase agreement was not found.');
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_followup') {
            require_role(['owner','admin','sales','collections']);
            $type = post_string('action_type', 30);
            $notes = post_string('notes', 4000);
            $outcome = post_string('outcome', 80);
            $promiseAmount = post_amount('promise_amount');
            $promiseDate = post_string('promise_date', 10);
            $nextAt = post_string('next_followup_at', 20);
            if (!in_array($type, ['call','sms','whatsapp','visit','promise_to_pay','note'], true) || strlen($notes) < 3) throw new RuntimeException('Select an activity and enter meaningful notes.');
            if ($type === 'promise_to_pay' && ($promiseAmount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $promiseDate))) throw new RuntimeException('A promise to pay requires an amount and date.');
            $next = null;
            if ($nextAt !== '') { $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $nextAt); if (!$dt) throw new RuntimeException('Enter a valid next follow-up date and time.'); $next = $dt->format('Y-m-d H:i:s'); }
            db()->prepare('INSERT INTO collection_followups(organization_id,agreement_id,customer_id,user_id,action_type,outcome,notes,promise_amount,promise_date,next_followup_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$oid, $aid, $agreement['customer_id'], $u['id'], $type, $outcome ?: null, $notes, $type === 'promise_to_pay' ? $promiseAmount : null, $type === 'promise_to_pay' ? $promiseDate : null, $next]);
            $fid = (int)db()->lastInsertId(); audit('collection.followup_recorded','collection_followup',$fid,['agreement_id'=>$aid,'action_type'=>$type]);
            flash('success', 'Collections activity recorded. No SMS or WhatsApp message was sent by this entry.'); redirect('?r=collection-case&id=' . $aid);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_dispute') {
            require_role(['owner','admin','sales','collections']);
            $category = post_string('category', 40); $description = post_string('description', 4000);
            $allowed = ['payment_not_reflected','amount_disputed','device_restriction','product_issue','agreement_terms','other'];
            if (!in_array($category, $allowed, true) || strlen($description) < 8) throw new RuntimeException('Select a dispute category and provide sufficient details.');
            $ref = 'DSP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            db()->beginTransaction();
            db()->prepare('INSERT INTO customer_disputes(organization_id,agreement_id,customer_id,dispute_reference,category,description,status,opened_by,opened_at) VALUES(?,?,?,?,?,?,"open",?,NOW())')->execute([$oid,$aid,$agreement['customer_id'],$ref,$category,$description,$u['id']]);
            db()->prepare('UPDATE agreements SET status="under_review" WHERE id=? AND organization_id=? AND status NOT IN ("settled","terminated")')->execute([$aid,$oid]);
            db()->commit(); audit('customer_dispute.opened','agreement',$aid,['dispute_reference'=>$ref,'category'=>$category]);
            flash('warning', 'Dispute ' . $ref . ' opened. The agreement is marked under review.'); redirect('?r=collection-case&id=' . $aid);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve_dispute') {
            require_role(['owner','admin','accountant']);
            $did = (int)($_POST['dispute_id'] ?? 0); $status = post_string('resolution_status', 20); $resolution = post_string('resolution', 3000);
            if (!in_array($status, ['resolved','rejected'], true) || strlen($resolution) < 5) throw new RuntimeException('Choose a valid outcome and enter the resolution.');
            $dispute = get_one('SELECT id FROM customer_disputes WHERE id=? AND agreement_id=? AND organization_id=? AND status IN ("open","reviewing")', [$did,$aid,$oid]);
            if (!$dispute) throw new RuntimeException('Open dispute not found.');
            db()->beginTransaction();
            db()->prepare('UPDATE customer_disputes SET status=?,resolution=?,reviewed_by=?,resolved_at=NOW() WHERE id=?')->execute([$status,$resolution,$u['id'],$did]);
            $remaining = (int)(get_one('SELECT COUNT(*) c FROM customer_disputes WHERE agreement_id=? AND organization_id=? AND status IN ("open","reviewing")',[$aid,$oid])['c'] ?? 0);
            if ($remaining === 0) db()->prepare('UPDATE agreements SET status=CASE WHEN balance_due<=0.005 THEN "settled" WHEN EXISTS (SELECT 1 FROM installment_schedules s WHERE s.agreement_id=agreements.id AND s.organization_id=agreements.organization_id AND s.due_date<CURDATE() AND s.status IN ("pending","partial")) THEN "overdue" ELSE "active" END WHERE id=? AND organization_id=? AND status="under_review"')->execute([$aid,$oid]);
            db()->commit(); audit('customer_dispute.closed','dispute',$did,['status'=>$status]);
            flash('success','Dispute outcome saved.'); redirect('?r=collection-case&id=' . $aid);
        }
        $schedules = get_all('SELECT *,(principal_due-principal_paid+interest_due-interest_paid) balance FROM installment_schedules WHERE agreement_id=? AND organization_id=? ORDER BY installment_no', [$aid,$oid]);
        $followups = get_all('SELECT f.*,u.full_name staff_name FROM collection_followups f JOIN users u ON u.id=f.user_id WHERE f.agreement_id=? AND f.organization_id=? ORDER BY f.created_at DESC LIMIT 100',[$aid,$oid]);
        $disputes = get_all('SELECT d.*,u.full_name opened_name,ru.full_name reviewed_name FROM customer_disputes d JOIN users u ON u.id=d.opened_by LEFT JOIN users ru ON ru.id=d.reviewed_by WHERE d.agreement_id=? AND d.organization_id=? ORDER BY d.opened_at DESC',[$aid,$oid]);
        $paid = (float)(get_one('SELECT COALESCE(SUM(amount),0) v FROM payments WHERE agreement_id=? AND organization_id=? AND payment_status="verified"',[$aid,$oid])['v'] ?? 0);
        $overdue = (float)(get_one('SELECT COALESCE(SUM(principal_due-principal_paid+interest_due-interest_paid),0) v FROM installment_schedules WHERE agreement_id=? AND organization_id=? AND due_date<CURDATE() AND status IN ("pending","partial")',[$aid,$oid])['v'] ?? 0);
        layout_start('Collections case','collections'); alert_box();
        echo '<div class="page-heading"><div><div class="eyebrow">COLLECTIONS CASE</div><h1>' . h($agreement['full_name']) . '</h1><p class="muted">' . h($agreement['agreement_no']) . ' · ' . h($agreement['phone']) . ' · ' . h($agreement['manufacturer'].' '.$agreement['model']) . '</p></div><a class="btn" href="?r=collections">← Back to arrears</a></div>';
        echo '<div class="kpi-grid"><div class="kpi-card"><span>Hire-purchase price</span><strong>' . money((float)$agreement['hp_price']) . '</strong></div><div class="kpi-card"><span>Verified contributions</span><strong>' . money($paid) . '</strong></div><div class="kpi-card"><span>Current balance</span><strong>' . money(max(0,(float)$agreement['hp_price']-$paid)) . '</strong></div><div class="kpi-card"><span>Overdue installments</span><strong>' . money(max(0,$overdue)) . '</strong></div></div>';
        echo '<div class="section-grid"><div class="panel"><h3>Record customer contact / follow-up</h3><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_followup"><div class="form-grid"><div><label>Activity type</label><select name="action_type" required><option value="call">Phone call</option><option value="sms">SMS attempt / contact</option><option value="whatsapp">WhatsApp contact</option><option value="visit">Physical visit</option><option value="promise_to_pay">Promise to pay</option><option value="note">Internal note</option></select></div><div><label>Outcome</label><select name="outcome"><option value="">Select outcome</option><option>Reached customer</option><option>No answer</option><option>Number unavailable</option><option>Customer requested statement</option><option>Payment pending</option><option>Promise received</option><option>Escalated for review</option></select></div><div><label>Promise amount (KES, if applicable)</label><input type="number" min="0" step="0.01" name="promise_amount"></div><div><label>Promise date (if applicable)</label><input type="date" name="promise_date"></div><div class="span-2"><label>Next follow-up (optional)</label><input type="datetime-local" name="next_followup_at"></div></div><label>Notes</label><textarea name="notes" rows="3" required></textarea><button class="btn primary">Save follow-up</button></form><p class="hint">This records the contact activity only. Actual SMS and WhatsApp delivery will require a separately configured messaging provider.</p></div>';
        echo '<div class="panel"><h3>Open a customer dispute</h3><form method="post"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="open_dispute"><label>Category</label><select name="category"><option value="payment_not_reflected">Payment not reflected</option><option value="amount_disputed">Amount disputed</option><option value="device_restriction">Device restriction complaint</option><option value="product_issue">Product issue</option><option value="agreement_terms">Agreement terms</option><option value="other">Other</option></select><label>Description</label><textarea name="description" rows="5" required minlength="8"></textarea><button class="btn primary">Open dispute & place account under review</button></form><div class="info-note">An open dispute places the agreement under review. Review it before considering any enforcement step.</div></div></div>';
        echo '<div class="panel"><div class="panel-head"><h3>Installment schedule</h3><a class="text-link" href="?r=agreement-view&id=' . $aid . '">View agreement →</a></div><div class="table-wrap"><table><thead><tr><th>No.</th><th>Due date</th><th>Principal due</th><th>Interest due</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
        foreach ($schedules as $s) { $remaining = max(0,(float)$s['balance']); $paidRow=(float)$s['principal_paid']+(float)$s['interest_paid']; echo '<tr><td>' . (int)$s['installment_no'] . '</td><td>' . date('d M Y',strtotime($s['due_date'])) . '</td><td>' . money((float)$s['principal_due']) . '</td><td>' . money((float)$s['interest_due']) . '</td><td>' . money($paidRow) . '</td><td class="amount">' . money($remaining) . '</td><td><span class="pill ' . ($s['status']==='paid'?'success':(strtotime($s['due_date'])<strtotime(date('Y-m-d'))?'warning':'neutral')) . '">' . h($s['status']) . '</span></td></tr>'; }
        if (!$schedules) echo '<tr><td colspan="7" class="empty">No installment schedule found.</td></tr>';
        echo '</tbody></table></div></div>';
        echo '<div class="panel"><h3>Follow-up history</h3><div class="table-wrap"><table><thead><tr><th>Date</th><th>Activity</th><th>Outcome</th><th>Notes</th><th>Promise</th><th>Staff</th></tr></thead><tbody>';
        foreach ($followups as $f) echo '<tr><td>' . date('d M Y H:i',strtotime($f['created_at'])) . '</td><td>' . h(str_replace('_',' ',$f['action_type'])) . '</td><td>' . h($f['outcome']??'—') . '</td><td>' . h($f['notes']) . '</td><td>' . (!empty($f['promise_date']) ? money((float)$f['promise_amount']).' by '.date('d M Y',strtotime($f['promise_date'])) : '—') . '</td><td>' . h($f['staff_name']) . '</td></tr>';
        if (!$followups) echo '<tr><td colspan="6" class="empty">No follow-ups recorded.</td></tr>';
        echo '</tbody></table></div></div>';
        echo '<div class="panel"><h3>Dispute register</h3><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Category</th><th>Description</th><th>Status</th><th>Outcome / action</th></tr></thead><tbody>';
        foreach ($disputes as $d) {
            echo '<tr><td><b>' . h($d['dispute_reference']) . '</b><small>' . date('d M Y',strtotime($d['opened_at'])) . '</small></td><td>' . h(str_replace('_',' ',$d['category'])) . '</td><td>' . h($d['description']) . '</td><td><span class="pill ' . (in_array($d['status'],['open','reviewing'],true)?'warning':'success') . '">' . h($d['status']) . '</span></td><td>' . (in_array($d['status'],['open','reviewing'],true) && in_array($u['role'],['owner','admin','accountant'],true) ? '<form method="post"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="resolve_dispute"><input type="hidden" name="dispute_id" value="'.(int)$d['id'].'"><select name="resolution_status"><option value="resolved">Resolved</option><option value="rejected">Rejected</option></select><input name="resolution" required placeholder="Resolution notes"><button class="tiny-btn">Save outcome</button></form>' : h($d['resolution']??'—')) . '</td></tr>';
        }
        if (!$disputes) echo '<tr><td colspan="5" class="empty">No disputes recorded for this agreement.</td></tr>';
        echo '</tbody></table></div></div>';
        layout_end(); return true;
    }

    if ($route === 'agreement-view') {
        require_role(['owner','admin','sales','collections','accountant','auditor']);
        $aid=(int)($_GET['id']??0);
        $a=get_one('SELECT a.*,c.full_name,c.phone,c.email,c.customer_no,c.general_location,i.manufacturer,i.model,i.storage_capacity,i.colour,i.imei1,i.imei2 FROM agreements a JOIN customers c ON c.id=a.customer_id JOIN inventory_items i ON i.id=a.inventory_item_id WHERE a.id=? AND a.organization_id=?',[$aid,$oid]);
        if(!$a) throw new RuntimeException('Agreement not found.');
        $schedule=get_all('SELECT * FROM installment_schedules WHERE agreement_id=? AND organization_id=? ORDER BY installment_no',[$aid,$oid]);
        $paid=(float)(get_one('SELECT COALESCE(SUM(amount),0) v FROM payments WHERE agreement_id=? AND organization_id=? AND payment_status="verified"',[$aid,$oid])['v']??0);
        layout_start('Agreement document','agreements');
        echo '<div class="page-heading"><div><div class="eyebrow">CUSTOMER AGREEMENT COPY</div><h1>Hire-purchase agreement</h1><p class="muted">'.h($a['agreement_no']).' · Created '.date('d M Y',strtotime($a['created_at'])).'</p></div><button class="btn primary" data-print>Print / Save PDF</button></div>';
        echo '<div class="panel print-document"><div style="display:flex;justify-content:space-between;gap:20px;align-items:flex-start"><div><div class="brand-mark">F</div><h2>FoneFlex Hire-Purchase Record</h2><p class="muted">Retailer-issued agreement summary</p></div><div style="text-align:right"><b>'.h($a['agreement_no']).'</b><p>'.date('d F Y',strtotime($a['created_at'])).'</p></div></div><hr><h3>1. Customer details</h3><div class="health-list"><div><span>Customer</span><b>'.h($a['full_name']).'</b></div><div><span>Customer number</span><b>'.h($a['customer_no']).'</b></div><div><span>Phone</span><b>'.h($a['phone']).'</b></div><div><span>Email / location</span><b>'.h(($a['email']?:'—').' / '.($a['general_location']?:'—')).'</b></div></div><h3>2. Financed device</h3><div class="health-list"><div><span>Device</span><b>'.h($a['manufacturer'].' '.$a['model'].' '.($a['storage_capacity']??'')).'</b></div><div><span>Colour</span><b>'.h($a['colour']??'—').'</b></div><div><span>IMEI 1</span><b>'.h($a['imei1']??'—').'</b></div><div><span>IMEI 2</span><b>'.h($a['imei2']??'—').'</b></div></div><h3>3. Financial summary</h3><div class="health-list"><div><span>Cash price</span><b>'.money((float)$a['cash_price']).'</b></div><div><span>Interest calculation</span><b>'.h(($a['interest_method']??'legacy_flat')==='reducing_balance'?'Reducing balance':'Legacy flat schedule').'</b></div><div><span>Annual interest rate</span><b>'.number_format((float)($a['annual_interest_rate']??0),2).'% p.a.</b></div><div><span>Total agreed interest</span><b>'.money((float)$a['interest_amount']).'</b></div><div><span>Other charges</span><b>'.money((float)$a['other_charges']).'</b></div><div><span>Hire-purchase price</span><b>'.money((float)$a['hp_price']).'</b></div><div><span>Deposit agreed</span><b>'.money((float)$a['deposit']).'</b></div><div><span>Verified contributions to date</span><b>'.money($paid).'</b></div><div><span>Outstanding balance</span><b>'.money(max(0,(float)$a['hp_price']-$paid)).'</b></div><div><span>Repayment frequency</span><b>'.h(ucfirst($a['frequency'])).'</b></div></div><h3>4. Installment schedule</h3><div class="table-wrap"><table><thead><tr><th>No.</th><th>Due date</th><th>Principal</th><th>Interest</th><th>Total due</th></tr></thead><tbody>';
        foreach($schedule as $s) echo '<tr><td>'.(int)$s['installment_no'].'</td><td>'.date('d M Y',strtotime($s['due_date'])).'</td><td>'.money((float)$s['principal_due']).'</td><td>'.money((float)$s['interest_due']).'</td><td>'.money((float)$s['principal_due']+(float)$s['interest_due']).'</td></tr>';
        echo '</tbody></table></div><h3>5. Device-management disclosure</h3><p class="muted">Any device-financing management is subject to the separate disclosure provided to the customer, phone/provider eligibility, supported operating-system capability, applicable law, and the relevant service terms. This system does not claim that every Android phone can be remotely restricted or made permanently unenrollable.</p><h3>6. Acknowledgment record</h3><p>Agreement acknowledgment recorded: <b>'.(!empty($a['customer_acknowledged_at'])?date('d M Y H:i',strtotime($a['customer_acknowledged_at'])):'Not recorded').'</b></p><p>Device disclosure acknowledgment recorded: <b>'.(!empty($a['device_notice_acknowledged_at'])?date('d M Y H:i',strtotime($a['device_notice_acknowledged_at'])):'Not recorded').'</b></p><div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:50px"><div>____________________________<br>Customer signature / date</div><div>____________________________<br>Retailer representative / date</div></div><p class="hint" style="margin-top:35px">This page is a system-generated agreement summary. The retailer must attach/use the reviewed, complete legal agreement containing all required terms and customer notices. Obtain Kenyan legal review before using this as the sole contract.</p></div>';
        layout_end(); return true;
    }

    if ($route === 'customer-statement') {
        require_role(['owner','admin','sales','collections','cashier','accountant','auditor']);
        $cid=(int)($_GET['id']??0);$c=ops_require_org_record('customers',$cid,$oid);if(!$c)throw new RuntimeException('Customer not found.');
        $agreements=get_all('SELECT a.*,i.manufacturer,i.model,(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.agreement_id=a.id AND p.payment_status="verified") paid FROM agreements a JOIN inventory_items i ON i.id=a.inventory_item_id WHERE a.customer_id=? AND a.organization_id=? ORDER BY a.created_at DESC',[$cid,$oid]);
        $payments=get_all('SELECT p.*,a.agreement_no FROM payments p JOIN agreements a ON a.id=p.agreement_id WHERE p.customer_id=? AND p.organization_id=? ORDER BY p.received_at DESC',[$cid,$oid]);
        $totalPaid=(float)(get_one('SELECT COALESCE(SUM(amount),0) v FROM payments WHERE customer_id=? AND organization_id=? AND payment_status="verified"',[$cid,$oid])['v']??0);
        $balance=0.0;foreach($agreements as $a)if(!in_array($a['status'],['terminated'],true))$balance+=max(0,(float)$a['hp_price']-(float)$a['paid']);
        layout_start('Customer statement','customers');
        echo '<div class="page-heading"><div><div class="eyebrow">CUSTOMER ACCOUNT STATEMENT</div><h1>'.h($c['full_name']).'</h1><p class="muted">'.h($c['customer_no']).' · '.h($c['phone']).' · As at '.date('d M Y H:i').'</p></div><button class="btn primary" data-print>Print / Save PDF</button></div>';
        echo '<div class="kpi-grid two"><div class="kpi-card"><span>Total verified contributions</span><strong>'.money($totalPaid).'</strong></div><div class="kpi-card"><span>Total outstanding across agreements</span><strong>'.money($balance).'</strong></div></div><div class="panel print-document"><h3>Agreements</h3><div class="table-wrap"><table><thead><tr><th>Agreement</th><th>Device</th><th>Hire-purchase price</th><th>Verified paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
        foreach($agreements as $a){$b=max(0,(float)$a['hp_price']-(float)$a['paid']);echo '<tr><td>'.h($a['agreement_no']).'</td><td>'.h($a['manufacturer'].' '.$a['model']).'</td><td>'.money((float)$a['hp_price']).'</td><td>'.money((float)$a['paid']).'</td><td>'.money($b).'</td><td>'.h($a['status']).'</td></tr>';}
        if(!$agreements)echo '<tr><td colspan="6" class="empty">No agreements.</td></tr>';
        echo '</tbody></table></div><h3 style="margin-top:22px">Contribution history</h3><div class="table-wrap"><table><thead><tr><th>Date</th><th>Receipt</th><th>Agreement</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
        foreach($payments as $p)echo '<tr><td>'.date('d M Y H:i',strtotime($p['received_at'])).'</td><td>'.h($p['receipt_no']).'</td><td>'.h($p['agreement_no']).'</td><td>'.h(strtoupper($p['channel'])).'</td><td>'.money((float)$p['amount']).'</td><td>'.h($p['payment_status']).'</td></tr>';
        if(!$payments)echo '<tr><td colspan="6" class="empty">No contributions recorded.</td></tr>';
        echo '</tbody></table></div><p class="hint">Only verified payments are included in the totals. Pending, failed, and reversed transactions are displayed in the history but are not counted as contributions.</p></div>';
        layout_end(); return true;
    }

    return false;
}
