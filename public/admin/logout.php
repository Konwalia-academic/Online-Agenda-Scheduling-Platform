<?php
require __DIR__ . '/../../app/bootstrap.php';
admin_logout();
redirect(base_url() . '/admin/login.php');
