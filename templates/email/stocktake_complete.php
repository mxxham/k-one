<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Take Complete</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4;padding:20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#e65100;padding:20px 30px;text-align:center;">
                            <h1 style="color:#ffffff;margin:0;font-size:20px;">K-one Warehouse Management</h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:30px;">
                            <h2 style="color:#e65100;margin-top:0;">Stock Take Complete</h2>
                            <p style="color:#333;font-size:14px;line-height:1.6;">
                                Stock take <strong>#<?= htmlspecialchars((string)($stocktakeId ?? 'N/A')) ?></strong> has been completed successfully.
                            </p>
                            <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e0e0e0;border-radius:4px;margin:20px 0;">
                                <tr style="background-color:#f9f9f9;">
                                    <td style="font-weight:bold;color:#555;width:140px;">Stock Take ID</td>
                                    <td style="color:#333;"><?= htmlspecialchars((string)($stocktakeId ?? 'N/A')) ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight:bold;color:#555;">Completed At</td>
                                    <td style="color:#333;"><?= htmlspecialchars($completedAt ?? date('Y-m-d H:i:s')) ?></td>
                                </tr>
                            </table>
                            <p style="color:#666;font-size:12px;margin-top:30px;">
                                This is an automated notification from <?= APP_NAME ?>.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f4f4f4;padding:15px 30px;text-align:center;">
                            <p style="color:#999;font-size:11px;margin:0;"><?= APP_NAME ?> v<?= APP_VERSION ?></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
