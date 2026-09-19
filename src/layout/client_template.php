<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($this->title) ?></title>
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