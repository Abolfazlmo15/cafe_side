<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($this->title) ?></title>

    <!-- PWA manifest -->
    <link rel="manifest" href="../../manifest.json">

    <!-- PWA theme -->
    <meta name="theme-color" content="#6f4e37">

    <!-- iOS PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Cafe Side">
    <link rel="apple-touch-icon" href="../../assets/images/icons/side_coffee.png">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../../assets/images/icons/side_coffee.png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?= $this->extraHead ?>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; margin: 0; padding: 1rem; }
        .client-wrapper { max-width: 700px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="client-wrapper">
        <?= $this->content ?>
    </div>
</body>
</html>