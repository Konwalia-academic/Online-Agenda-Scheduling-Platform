<?php
/*
 * Admin authentication. Single administrator account.
 */
declare(strict_types=1);

function admin_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM admin WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$row['id'];
    return true;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $rel = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? 'login.php' : '../admin/login.php';
        header('Location: ' . $rel);
        exit;
    }
}

function is_admin(): bool
{
    return !empty($_SESSION['admin_id']);
}

function admin_logout(): void
{
    unset($_SESSION['admin_id']);
    session_regenerate_id(true);
}
