<?php
/**
 * csrf.php — cross-site request forgery protection.
 *
 * Included by boot.php, so every page that starts a session has these
 * available. Two rules:
 *   - every <form method="POST"> must contain <?php echo csrf_field(); ?>
 *   - every POST handler must call csrf_verify() before it writes anything
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Rejects the request unless it carries the current session's token.
 * No-op on GET, so it is safe to call unconditionally at the top of a page.
 *
 * @param bool $json  Respond as JSON instead of plain text (for API endpoints).
 */
function csrf_verify(bool $json = false): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $sent = $_POST['_token'] ?? '';

    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Your session expired. Reload the page and try again.',
            ]);
        } else {
            echo 'Your session expired. Reload the page and try again.';
        }
        exit;
    }
}
