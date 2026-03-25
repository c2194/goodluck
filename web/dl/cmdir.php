<?php
$message = '';
$inputMac = '';
$base62Mac = '';

function normalizeMacInput($input, &$error) {
  $input = trim($input);
  if ($input === '') {
    $error = '请输入 MAC 地址。';
    return '';
  }

  if (!preg_match('/^[A-Fa-f0-9:-]+$/', $input)) {
    $error = 'MAC 地址格式不正确。';
    return '';
  }

  $hex = preg_replace('/[^A-Fa-f0-9]/', '', $input);
  if (strlen($hex) !== 12) {
    $error = 'MAC 地址长度不正确。';
    return '';
  }

  $error = '';
  return strtoupper($hex);
}

function toBase62($number) {
  $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  if ($number === 0) {
    return $alphabet[0];
  }
  $result = '';
  while ($number > 0) {
    $remainder = $number % 62;
    $result = $alphabet[$remainder] . $result;
    $number = intdiv($number, 62);
  }
  return $result;
}

function generateBase62Key($length = 8) {
  $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $maxIndex = strlen($alphabet) - 1;
  $key = '';
  for ($i = 0; $i < $length; $i++) {
    $key .= $alphabet[random_int(0, $maxIndex)];
  }
  return $key;
}

// API 模式：处理 reg 参数
if (isset($_GET['reg'])) {
  header('Content-Type: application/json; charset=utf-8');
  $regMac = $_GET['reg'];
  $error = '';
  $hexMac = normalizeMacInput($regMac, $error);

  if ($error !== '') {
    echo json_encode([
      'cmd' => 'reg',
      're' => 'error',
      'msg' => $error,
      'monthDir' => null,
      'macDir' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $number = hexdec($hexMac);
  $base62Mac = toBase62($number);
  $base62Mac = str_pad($base62Mac, 9, 'a', STR_PAD_LEFT);

  $monthYear = date('my');
  $baseDir = __DIR__;
  $monthDir = $monthYear;
  $macDir = $base62Mac;
  $monthDirPath = $baseDir . DIRECTORY_SEPARATOR . $monthDir;
  $macDirPath = $monthDirPath . DIRECTORY_SEPARATOR . $macDir;

  if (!is_dir($monthDirPath)) {
    if (!mkdir($monthDirPath, 0777, true)) {
      echo json_encode([
        'cmd' => 'reg',
        're' => 'error',
        'msg' => '创建月份目录失败',
        'monthDir' => $monthDir,
        'macDir' => null
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  if (!is_dir($macDirPath)) {
    if (!mkdir($macDirPath, 0777, true)) {
      echo json_encode([
        'cmd' => 'reg',
        're' => 'error',
        'msg' => '创建 MAC 目录失败',
        'monthDir' => $monthDir,
        'macDir' => $macDir
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  $jsonPath = $macDirPath . DIRECTORY_SEPARATOR . 'config.json';
  if (!file_exists($jsonPath)) {
    $config = [];
    while (count($config) < 30) {
      $key = generateBase62Key(8);
      if (!array_key_exists($key, $config)) {
        $config[$key] = [
          'pw' => '',
          'state' => '1'
        ];
      }
    }
    $config['SETUP'] = [
      'systime' => strval(time()),
      'sleep' => '15',
      'attime' => '3000'
    ];
    $jsonData = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($jsonData === false || file_put_contents($jsonPath, $jsonData) === false) {
      echo json_encode([
        'cmd' => 'reg',
        're' => 'error',
        'msg' => '写入配置失败',
        'monthDir' => $monthDir,
        'macDir' => $macDir
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  echo json_encode([
    'cmd' => 'reg',
    're' => 'ok',
    'monthDir' => $monthDir,
    'macDir' => $macDir
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $inputMac = isset($_POST['mac']) ? trim($_POST['mac']) : '';
  $error = '';
  $hexMac = normalizeMacInput($inputMac, $error);

  if ($error !== '') {
    $message = $error;
  } else {
    $number = hexdec($hexMac);
    $base62Mac = toBase62($number);
    $base62Mac = str_pad($base62Mac, 9, 'a', STR_PAD_LEFT);

    $monthYear = date('my');
    $baseDir = __DIR__;
    $monthDir = $baseDir . DIRECTORY_SEPARATOR . $monthYear;
    $macDir = $monthDir . DIRECTORY_SEPARATOR . $base62Mac;

    if (!is_dir($monthDir)) {
      if (!mkdir($monthDir, 0777, true)) {
        $message = '创建月份目录失败。';
      }
    }

    if ($message === '') {
      if (!is_dir($macDir)) {
        if (!mkdir($macDir, 0777, true)) {
          $message = '创建 MAC 目录失败。';
        }
      }

      if ($message === '') {
        $config = [];
        while (count($config) < 30) {
          $key = generateBase62Key(8);
          if (!array_key_exists($key, $config)) {
            $config[$key] = [
              'pw' => '',
              'state' => '1'
            ];
          }
        }
        $config['SETUP'] = [
          'systime' => strval(time()),
          'sleep' => '15',
          'attime' => '3000'
        ];

        $jsonPath = $macDir . DIRECTORY_SEPARATOR . 'config.json';
        $jsonData = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($jsonData === false) {
          $message = '生成 JSON 失败。';
        } elseif (file_put_contents($jsonPath, $jsonData) === false) {
          $message = '写入 JSON 失败。';
        } else {
          $message = '目录创建成功：' . $monthYear . '/' . $base62Mac;
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>创建 MAC 目录</title>
  <style>
    body {
      margin: 0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      background: #0b0f14;
      color: #e6edf3;
    }
    .wrap {
      max-width: 520px;
      margin: 40px auto;
      padding: 20px;
      background: #121826;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 12px;
    }
    h1 {
      font-size: 20px;
      margin: 0 0 16px;
    }
    label {
      display: block;
      font-size: 12px;
      color: #9aa4b2;
      margin-bottom: 8px;
    }
    input[type="text"] {
      width: 100%;
      box-sizing: border-box;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.1);
      background: rgba(255,255,255,0.03);
      color: #e6edf3;
      padding: 10px 12px;
      font-size: 16px;
      outline: none;
    }
    button {
      margin-top: 14px;
      border: 0;
      border-radius: 10px;
      padding: 10px 14px;
      font-weight: 600;
      background: #1f6feb;
      color: #fff;
      cursor: pointer;
    }
    .message {
      margin-top: 14px;
      color: #9aa4b2;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>创建 MAC 目录</h1>
    <form method="post">
      <label for="mac">输入 MAC 地址（如 BC-6E-E2-35-7E-24 或 BC6EE2357E24）</label>
      <input id="mac" name="mac" type="text" value="<?php echo htmlspecialchars($inputMac, ENT_QUOTES, 'UTF-8'); ?>" />
      <button type="submit">确定</button>
    </form>
    <?php if ($message !== ''): ?>
      <div class="message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
  </div>
</body>
</html>
