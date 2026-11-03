<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

function inviteEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
require_once __DIR__ . '/auth.php';
/* Verify Super Admin directly from database */
$adminId = (int)$_SESSION['admin_id'];

$stmt = $pdo->prepare("
    SELECT id, name, email, role, status, email_verified_at
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$adminId]);
$currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$currentAdmin ||
    $currentAdmin['role'] !== 'super_admin' ||
    $currentAdmin['status'] !== 'active' ||
    empty($currentAdmin['email_verified_at'])
) {
    http_response_code(403);
    exit('Access denied. Verified Super Admin access required.');
}

if (empty($_SESSION['invite_csrf'])) {
    $_SESSION['invite_csrf'] = bin2hex(random_bytes(32));
}

function inviteRedirect(): void {
    header('Location: invite-admin.php');
    exit;
}

function createInvitation(PDO $pdo, int $adminId): string {
    $code = 'LSBD-' . strtoupper(bin2hex(random_bytes(16)));
    $hash = hash('sha256', $code);

    $stmt = $pdo->prepare("
        INSERT INTO admin_invites
            (invite_code_hash, created_by, expires_at)
        VALUES
            (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
    ");
    $stmt->execute([$hash, $adminId]);

    return $code;
}

$success = $_SESSION['invite_success'] ?? '';
$generatedCode = $_SESSION['invite_code'] ?? '';

unset($_SESSION['invite_success'], $_SESSION['invite_code']);

$error = '';

/* Handle invitation actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !$token ||
        !hash_equals($_SESSION['invite_csrf'], $token)
    ) {
        $error = 'Security verification failed. Refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'generate') {
                $pdo->beginTransaction();

                /*
                 * Lock admin rows to serialize slot reservations.
                 * Requires InnoDB tables.
                 */
                $pdo->query("
                    SELECT id FROM admins FOR UPDATE
                ")->fetchAll();

                $count = (int)$pdo->query("
                    SELECT COUNT(*) FROM admins
                ")->fetchColumn();

                if ($count >= 5) {
                    throw new RuntimeException(
                        'Maximum 5 Admin accounts already reached.'
                    );
                }

                $activeInvites = (int)$pdo->query("
                    SELECT COUNT(*)
                    FROM admin_invites
                    WHERE used_at IS NULL
                    AND expires_at > NOW()
                ")->fetchColumn();

                if ($activeInvites >= (5 - $count)) {
                    throw new RuntimeException(
                        'All remaining Admin slots are reserved by active invitations.'
                    );
                }

                $code = createInvitation($pdo, $adminId);

                $pdo->commit();

                $_SESSION['invite_code'] = $code;
                $_SESSION['invite_success'] =
                    'New single-use invitation generated. It expires in 24 hours.';

                inviteRedirect();
            }

            elseif ($action === 'reset') {
                $inviteId = filter_input(
                    INPUT_POST,
                    'invite_id',
                    FILTER_VALIDATE_INT
                );

                if (!$inviteId) {
                    throw new RuntimeException('Invalid invitation.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT id, used_at, expires_at
                    FROM admin_invites
                    WHERE id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$inviteId]);
                $invite = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$invite) {
                    throw new RuntimeException('Invitation not found.');
                }

                if (
                    $invite['used_at'] !== null ||
                    strtotime($invite['expires_at']) <= time()
                ) {
                    throw new RuntimeException(
                        'Only active, unused invitations can be reset.'
                    );
                }

                /*
                 * Mark old invitation as used.
                 * Its previous code will no longer work.
                 */
                $stmt = $pdo->prepare("
                    UPDATE admin_invites
                    SET used_at = NOW()
                    WHERE id = ?
                    AND used_at IS NULL
                ");
                $stmt->execute([$inviteId]);

                $count = (int)$pdo->query("
                    SELECT COUNT(*) FROM admins
                ")->fetchColumn();

                if ($count >= 5) {
                    throw new RuntimeException(
                        'Maximum Admin account limit reached.'
                    );
                }

                $code = createInvitation($pdo, $adminId);

                $pdo->commit();

                $_SESSION['invite_code'] = $code;
                $_SESSION['invite_success'] =
                    'Invitation reset. The old code is invalid and a new code has been generated.';

                inviteRedirect();
            }

            elseif ($action === 'delete') {
                $inviteId = filter_input(
                    INPUT_POST,
                    'invite_id',
                    FILTER_VALIDATE_INT
                );

                if (!$inviteId) {
                    throw new RuntimeException('Invalid invitation.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM admin_invites
                    WHERE id = ?
                ");
                $stmt->execute([$inviteId]);

                $_SESSION['invite_success'] = $stmt->rowCount()
                    ? 'Invitation deleted. Its code can no longer be used.'
                    : 'Invitation not found.';

                inviteRedirect();
            }

            else {
                throw new RuntimeException('Invalid action.');
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Admin invitation error: ' . $e->getMessage());

            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Something went wrong. Please try again.';
        }
    }
}

/* Capacity */
$totalAdmins = (int)$pdo->query("
    SELECT COUNT(*) FROM admins
")->fetchColumn();

$activeInvites = (int)$pdo->query("
    SELECT COUNT(*)
    FROM admin_invites
    WHERE used_at IS NULL
    AND expires_at > NOW()
")->fetchColumn();

$remainingSlots = max(0, 5 - $totalAdmins);
$unreservedSlots = max(0, $remainingSlots - $activeInvites);

/* Show invitation history for all creators */
$listStmt = $pdo->query("
    SELECT
        i.id,
        i.created_by,
        i.created_at,
        i.expires_at,
        i.used_at,
        a.name AS creator_name,
        a.email AS creator_email
    FROM admin_invites i
    LEFT JOIN admins a ON a.id = i.created_by
    ORDER BY i.id DESC
");
$invitations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Invitations | Leather Store BD</title>

<style>
*{box-sizing:border-box}
body{
    margin:0;
    background:#f5f2ed;
    color:#34271f;
    font-family:Arial,sans-serif;
}
.header{
    background:#38281f;
    color:white;
    padding:18px 5%;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
}
.header a{color:#f1d4ad;text-decoration:none}
.header-links{display:flex;gap:15px;align-items:center;flex-wrap:wrap}
.back{
    background:#fff;
    color:#38281f!important;
    padding:9px 13px;
    border-radius:7px;
    font-weight:bold;
}
.container{max-width:1150px;margin:30px auto;padding:0 18px}
h1{font-size:27px}
.card{
    background:white;
    padding:22px;
    border-radius:12px;
    margin-bottom:22px;
    box-shadow:0 3px 15px rgba(0,0,0,.05);
}
.stats{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:15px;
}
.stat{
    background:#faf7f3;
    padding:18px;
    border-radius:9px;
}
.stat strong{font-size:25px;display:block;margin-top:8px}
button{
    border:0;
    background:#79543b;
    color:white;
    padding:11px 16px;
    border-radius:7px;
    cursor:pointer;
}
button:hover{background:#513824}
button:disabled{opacity:.5;cursor:not-allowed}
.danger{background:#a43b32}
.light{background:#eee5dc;color:#49382c}
.alert{padding:14px;border-radius:8px;margin-bottom:18px}
.success{background:#e2f5e6;color:#236b32}
.error{background:#ffe5e3;color:#9b2923}
.code-box{
    background:#faf7f3;
    border:1px dashed #a88b70;
    padding:16px;
    border-radius:8px;
    margin-top:15px;
}
.code{
    font-size:22px;
    font-weight:bold;
    letter-spacing:2px;
    overflow-wrap:anywhere;
}
.copy-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
table{width:100%;border-collapse:collapse;min-width:750px}
th,td{
    text-align:left;
    padding:13px;
    border-bottom:1px solid #eee7e0;
    font-size:13px;
}
th{background:#faf7f3}
.table-wrap{overflow-x:auto}
.badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:20px;
    background:#eee;
    font-size:11px;
}
.active{background:#e0f3df;color:#27712c}
.used{background:#dcecff;color:#205a91}
.expired{background:#ffe5e3;color:#a52e28}
.inline-form{display:inline-block;margin:2px}
.note{color:#806e60;font-size:13px;line-height:1.6}
@media(max-width:600px){
    .container{margin:18px auto}
    .card{padding:15px}
    h1{font-size:22px}
}
</style>
</head>

<body>

<header class="header">
    <strong>Leather Store BD | Admin Invitations</strong>

    <div class="header-links">
        <?= inviteEscape($currentAdmin['name']) ?>
        <a class="back" href="dashboard.php">← Back to Dashboard</a>
        <a href="admins.php">Admin Management</a>
        <a href="logout.php">Logout</a>
    </div>
</header>

<main class="container">

<h1>Admin Invitations</h1>
<p class="note">
    Create secure, single-use invitations for new Admin accounts.
    Only verified, active Super Admins can access this page.
</p>

<?php if ($success): ?>
    <div class="alert success"><?= inviteEscape($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert error"><?= inviteEscape($error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="stats">
        <div class="stat">
            Total Admin Accounts
            <strong><?= $totalAdmins ?> / 5</strong>
        </div>

        <div class="stat">
            Available Account Slots
            <strong><?= $remainingSlots ?></strong>
        </div>

        <div class="stat">
            Active Invitations
            <strong><?= $activeInvites ?></strong>
        </div>

        <div class="stat">
            Unreserved Slots
            <strong><?= $unreservedSlots ?></strong>
        </div>
    </div>
</section>

<section class="card">
    <h2>Generate New Invitation</h2>

    <p class="note">
        Each invitation expires after 24 hours and can only be used once.
        Resetting an invitation invalidates its previous code.
    </p>

    <form method="POST">
        <input type="hidden" name="csrf_token"
               value="<?= inviteEscape($_SESSION['invite_csrf']) ?>">
        <input type="hidden" name="action" value="generate">

        <button type="submit" <?= $unreservedSlots <= 0 ? 'disabled' : '' ?>>
            Generate Invitation Code
        </button>
    </form>

    <?php if ($generatedCode): ?>
        <div class="code-box">
            <p><strong>Your new invitation code</strong></p>

            <div class="code" id="inviteCode">
                <?= inviteEscape($generatedCode) ?>
            </div>

            <div class="copy-row">
                <button type="button" onclick="copyInvite()">
                    Copy Invitation Code
                </button>

                <a href="signup.php" style="text-decoration:none">
                    <button type="button" class="light">
                        Open Admin Signup
                    </button>
                </a>
            </div>

            <p class="note">
                Save this code securely. It is shown only once and should
                be shared only with the intended person.
            </p>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Invitation History</h2>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Created By</th>
                    <th>Created At</th>
                    <th>Expires At</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($invitations as $invite): ?>

                <?php
                if ($invite['used_at'] !== null) {
                    $inviteStatus = 'Used';
                    $statusClass = 'used';
                } elseif (strtotime($invite['expires_at']) <= time()) {
                    $inviteStatus = 'Expired';
                    $statusClass = 'expired';
                } else {
                    $inviteStatus = 'Active';
                    $statusClass = 'active';
                }
                ?>

                <tr>
                    <td>#<?= (int)$invite['id'] ?></td>

                    <td>
                        <?= inviteEscape($invite['creator_name'] ?? 'Deleted Admin') ?><br>
                        <span class="note">
                            <?= inviteEscape($invite['creator_email'] ?? '') ?>
                        </span>
                    </td>

                    <td><?= inviteEscape($invite['created_at']) ?></td>
                    <td><?= inviteEscape($invite['expires_at']) ?></td>

                    <td>
                        <span class="badge <?= $statusClass ?>">
                            <?= $inviteStatus ?>
                        </span>
                    </td>

                    <td>
                        <?php if ($inviteStatus === 'Active'): ?>

                            <form method="POST" class="inline-form"
                                onsubmit="return confirm('Reset this invitation? The old code will stop working.')">
                                <input type="hidden" name="csrf_token"
                                    value="<?= inviteEscape($_SESSION['invite_csrf']) ?>">
                                <input type="hidden" name="action" value="reset">
                                <input type="hidden" name="invite_id"
                                    value="<?= (int)$invite['id'] ?>">
                                <button type="submit">Reset</button>
                            </form>

                        <?php endif; ?>

                        <form method="POST" class="inline-form"
                            onsubmit="return confirm('Delete this invitation permanently?')">
                            <input type="hidden" name="csrf_token"
                                value="<?= inviteEscape($_SESSION['invite_csrf']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="invite_id"
                                value="<?= (int)$invite['id'] ?>">
                            <button type="submit" class="danger">Delete</button>
                        </form>
                    </td>
                </tr>

            <?php endforeach; ?>

            <?php if (!$invitations): ?>
                <tr>
                    <td colspan="6">No invitations created yet.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

</main>

<script>
function copyInvite() {
    const code = document.getElementById('inviteCode');
    if (!code) return;

    navigator.clipboard.writeText(code.innerText.trim())
        .then(() => alert('Invitation code copied!'))
        .catch(() => alert('Could not copy. Please copy the code manually.'));
}
</script>

</body>
</html>
