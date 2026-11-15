<?php
// Copy to config.local.php on the host only. Never commit the populated file.
// The deployment package denies direct HTTP access to both configuration files.
return [
    'PHP_BACKEND' => 'native',
    'PHP_SITE_PATH' => '',
    'PHP_PUBLIC_URL' => 'https://your-cinema.example',
    'BASE_DB_HOST' => 'your-host-provided-mysql-server',
    'BASE_DB_PORT' => '3306',
    'PHP_DB_NAME' => 'your_host_database_name',
    'BASE_DB_USER' => 'your_host_database_user',
    'BASE_DB_PASSWORD' => '',
    'MYSQL_SSL' => 'false',
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
    'GOOGLE_REDIRECT_URI' => 'https://your-cinema.example/api/auth/google/callback',
    'GOOGLE_OWNER_EMAILS' => '',
    'EMAILJS_SERVICE_ID' => '',
    'EMAILJS_TEMPLATE_ID' => '',
    'EMAILJS_PUBLIC_KEY' => '',
    'EMAILJS_PRIVATE_KEY' => '',
];
