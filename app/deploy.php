<?php
// Deploy endpoint — called by GitHub Actions after successful IPA build.
// Strips WidgetKit extensions before saving (SideStore free-signing crashes on them).

define('DEPLOY_TOKEN', 'f75b12c8d673804725d96a6b88d87059162cb91896f5283e7a4beb5632c912ab');

$token = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(401);
    die(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['ipa'])) {
    http_response_code(400);
    die(json_encode(['ok' => false, 'error' => 'POST with ipa file required']));
}

$upload = $_FILES['ipa']['tmp_name'];
$dest   = __DIR__ . '/SingOpKoelsch.ipa';
$tmp    = $dest . '.new';

// Strip PlugIns/*.appex — SideStore with free Apple ID crashes on WidgetKit extensions
$py = 'import sys,zipfile,os\n'
    . 'src,dst=sys.argv[1],sys.argv[2]\n'
    . 'removed=0\n'
    . 'zin=zipfile.ZipFile(src,"r")\n'
    . 'zout=zipfile.ZipFile(dst,"w",zipfile.ZIP_DEFLATED)\n'
    . '[zout.writestr(i,zin.read(i.filename)) or None for i in zin.infolist() if "/PlugIns/" not in i.filename or [setattr(sys.modules[__name__],"removed",removed+1)]]\n'
    . 'zin.close();zout.close()\n'
    . 'print(f"ok:{os.path.getsize(dst)}")';

$out = shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($upload) . ' ' . escapeshellarg($tmp) . ' 2>&1');

if (file_exists($tmp) && filesize($tmp) > 10000) {
    rename($tmp, $dest);
} else {
    move_uploaded_file($upload, $dest);
}

$size    = filesize($dest);
$version = trim($_POST['version'] ?? '');
$desc    = trim($_POST['description'] ?? '');
$today   = date('Y-m-d');

foreach (['altstore.json', 'altstore-pal.json'] as $f) {
    $path = __DIR__ . '/' . $f;
    $data = json_decode(file_get_contents($path), true);
    $data['apps'][0]['size'] = $size;
    if ($version) {
        $data['apps'][0]['version']            = $version;
        $data['apps'][0]['versionDate']        = $today;
        $data['apps'][0]['versionDescription'] = $desc ?: $version;
        // Prepend new entry to versions array
        $newEntry = [
            'version'              => $version,
            'date'                 => $today,
            'localizedDescription' => $desc ?: $version,
            'downloadURL'          => 'https://singopkoelsch.de/app/SingOpKoelsch.ipa',
            'size'                 => $size,
            'minOSVersion'         => '17.0',
        ];
        // Only prepend if version differs from current top entry
        if (empty($data['apps'][0]['versions'][0]['version']) ||
            $data['apps'][0]['versions'][0]['version'] !== $version) {
            array_unshift($data['apps'][0]['versions'], $newEntry);
        } else {
            $data['apps'][0]['versions'][0]['size'] = $size;
        }
    } else {
        if (!empty($data['apps'][0]['versions'][0])) {
            $data['apps'][0]['versions'][0]['size'] = $size;
        }
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => true, 'size' => $size, 'msg' => 'deployed (extensions stripped)']);
