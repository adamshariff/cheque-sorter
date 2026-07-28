<?php
$errors = [];
$generatedCheques = [];
$count = 3;
$exportFolder = 'exports';

function numberToWords($number)
{
    $map = [
        0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven',
        12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen',
        17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty', 30 => 'thirty',
        40 => 'forty', 50 => 'fifty', 60 => 'sixty', 70 => 'seventy', 80 => 'eighty', 90 => 'ninety'
    ];

    if ($number < 21) {
        return $map[$number];
    }

    if ($number < 100) {
        $tens = intdiv($number, 10) * 10;
        $ones = $number % 10;
        return $map[$tens] . ($ones ? ' ' . $map[$ones] : '');
    }

    if ($number < 1000) {
        $hundreds = intdiv($number, 100);
        $remainder = $number % 100;
        $word = $map[$hundreds] . ' hundred';
        return $remainder ? $word . ' ' . numberToWords($remainder) : $word;
    }

    return 'amount too large';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
    $folderName = trim((string)($_POST['export_folder'] ?? ''));

    if ($folderName === '') {
        $folderName = 'exports';
    }

    $exportFolder = rtrim($folderName, '/\\');

    if ($count === false || $count === null) {
        $errors[] = 'Please enter a valid number between 1 and 20.';
    } else {
        $payees = ['Alex Rivera', 'Jordan Kim', 'Taylor Brooks', 'Morgan Chen', 'Casey Patel', 'Riley Singh', 'Jamie Lewis'];
        $memos = ['Office Supplies', 'Consulting Fee', 'Rent Payment', 'Equipment Purchase', 'Travel Reimbursement', 'Service Invoice', 'Monthly Payroll'];

        if (!is_dir($exportFolder)) {
            mkdir($exportFolder, 0777, true);
        }

        for ($i = 0; $i < $count; $i++) {
            $cheque = [
                'date' => date('Y-m-d', strtotime('+' . random_int(0, 30) . ' days')),
                'payee' => $payees[array_rand($payees)],
                'amount' => random_int(50, 2500),
                'memo' => $memos[array_rand($memos)]
            ];

            $content = "Date: {$cheque['date']}\nPayee: {$cheque['payee']}\nAmount: {$cheque['amount']}\nMemo: {$cheque['memo']}\n";
            $filePath = $exportFolder . '/cheque_' . ($i + 1) . '.tif';
            file_put_contents($filePath, $content);

            $cheque['export_path'] = $filePath;
            $generatedCheques[] = $cheque;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cheque Generator</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; }
        .wrap { max-width: 1100px; margin: auto; background: white; padding: 24px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.1); }
        form { display: grid; gap: 10px; margin-bottom: 20px; }
        input { padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
        button { padding: 10px 14px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; }
        .cheque-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .cheque { border: 2px solid #111827; padding: 16px; border-radius: 10px; background: #fffdf7; }
        .row { display: flex; justify-content: space-between; gap: 12px; margin: 8px 0; }
        .amount-box { border: 1px solid #111827; padding: 8px 12px; min-width: 140px; text-align: right; }
        .error { color: #b91c1c; }
        .hint { color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Random Cheque Generator</h1>
        <p class="hint">Generate a batch of random cheques with one click.</p>

        <form method="post">
            <label for="count">Number of cheques</label>
            <input id="count" name="count" type="number" min="1" max="20" value="<?= htmlspecialchars((string)$count, ENT_QUOTES) ?>">

            <label for="export_folder">Export folder</label>
            <input id="export_folder" name="export_folder" type="text" value="<?= htmlspecialchars($exportFolder, ENT_QUOTES) ?>" placeholder="exports">

            <button type="submit">Generate Random Cheques</button>
        </form>

        <?php if (!empty($errors)) : ?>
            <div class="error">
                <?php foreach ($errors as $error) : ?>
                    <p><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($generatedCheques)) : ?>
            <div class="cheque-grid">
                <?php foreach ($generatedCheques as $cheque) : ?>
                    <div class="cheque">
                        <div class="row">
                            <strong>Date:</strong>
                            <span><?= htmlspecialchars($cheque['date'], ENT_QUOTES) ?></span>
                        </div>
                        <div class="row">
                            <strong>Pay to the Order of:</strong>
                            <span><?= htmlspecialchars($cheque['payee'], ENT_QUOTES) ?></span>
                        </div>
                        <div class="row">
                            <strong>Amount:</strong>
                            <div class="amount-box">$<?= htmlspecialchars(number_format($cheque['amount'], 2), ENT_QUOTES) ?></div>
                        </div>
                        <div class="row">
                            <strong>In Words:</strong>
                            <span><?= htmlspecialchars(ucwords(numberToWords($cheque['amount'])), ENT_QUOTES) ?></span>
                        </div>
                        <div class="row">
                            <strong>Memo:</strong>
                            <span><?= htmlspecialchars($cheque['memo'], ENT_QUOTES) ?></span>
                        </div>
                        <div class="row">
                            <strong>Exported:</strong>
                            <span><?= htmlspecialchars($cheque['export_path'] ?? '—', ENT_QUOTES) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
