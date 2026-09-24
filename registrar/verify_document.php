<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar', 'staff']);

$docNumber = trim($_GET['doc'] ?? $_POST['doc_number'] ?? '');
$verificationResult = null;
$error = '';

if (is_post()) {
    verify_csrf();
    $docNumber = trim($_POST['doc_number'] ?? '');

    if ($docNumber === '') {
        $error = 'Please enter a document number.';
    } else {
        $verificationResult = verify_tor_document($docNumber);
        if (!$verificationResult) {
            $error = 'Document not found. Please verify the document number and try again.';
        }
    }
}

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>Verify Document</h1><p>Verify the authenticity of a TOR document.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card" style="max-width:600px;margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">TOR Document Verification</h3>
    <form method="post">
        <?= csrf_field() ?>
        <div style="display:flex;gap:8px;">
            <input type="text" name="doc_number" value="<?= h($docNumber) ?>" style="flex:1;font-size:14px;padding:8px 12px;" placeholder="Enter TOR document number (e.g. TOR-2026-000001)">
            <button class="btn" type="submit">Verify</button>
        </div>
    </form>
</div>

<?php if ($error): ?>
<div class="card" style="border-left:4px solid #dc2626;max-width:600px;margin-bottom:16px;">
    <p style="color:#dc2626;font-weight:600;"><?= h($error) ?></p>
</div>
<?php endif; ?>

<?php if ($verificationResult): ?>
<div class="card" style="border-left:4px solid #16a34a;max-width:600px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
        <span class="material-symbols-outlined" style="font-size:24px;color:#16a34a;">verified</span>
        <h3 style="margin:0;color:#16a34a;">Document Verified</h3>
    </div>
    <div style="font-size:13px;">
        <div style="display:grid;grid-template-columns:140px 1fr;gap:6px;">
            <span style="font-weight:600;">Document #:</span><span><?= h($verificationResult['document_number']) ?></span>
            <span style="font-weight:600;">Student:</span><span><?= h($verificationResult['full_name']) ?></span>
            <span style="font-weight:600;">Student No.:</span><span><?= h($verificationResult['student_number']) ?></span>
            <span style="font-weight:600;">Purpose:</span><span><?= h(ucfirst(str_replace('_', ' ', $verificationResult['purpose']))) ?></span>
            <?php if ($verificationResult['destination']): ?><span style="font-weight:600;">Destination:</span><span><?= h($verificationResult['destination']) ?></span><?php endif; ?>
            <span style="font-weight:600;">Status:</span>
            <span><?= $verificationResult['workflow_status'] === 'released' ? '<span style="color:#16a34a;font-weight:600;">Released</span>' : '<span style="color:#f59e0b;">' . h(ucfirst(str_replace('_', ' ', $verificationResult['workflow_status']))) . '</span>' ?></span>
            <?php if ($verificationResult['released_at']): ?><span style="font-weight:600;">Released:</span><span><?= h(date('F j, Y', strtotime($verificationResult['released_at']))) ?></span><?php endif; ?>
            <span style="font-weight:600;">Generated:</span><span><?= h(date('F j, Y', strtotime($verificationResult['created_at']))) ?></span>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
render_page('Verify Document', 'Verify Document', (string) ob_get_clean());
