<?php
/*
 * Starts the Microsoft OAuth authorization-code flow with PKCE.
 * Redirects the administrator to the Microsoft sign-in page.
 */
require __DIR__ . '/../app/bootstrap.php';
require_admin();

if (!graph()->configured()) {
    redirect(base_url() . '/admin/calendars.php');
}

$state = random_token(16);
$_SESSION['graph_state'] = $state;
[$url, $verifier] = graph()->authorizeUrl($state);
$_SESSION['graph_verifier'] = $verifier;

header('Location: ' . $url);
exit;
