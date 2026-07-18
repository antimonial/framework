<?php

declare(strict_types=1);
// Integration harness for real HTTP file uploads (is_uploaded_file /
// move_uploaded_file only behave correctly for genuine uploads).
//
// This script is invoked by the built-in PHP server. It exercises the
// framework's UploadedFile + Controller file rules end-to-end and emits
// a single line of JSON describing the outcome, which the test asserts on.

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/constants.php';

use Antimonial\Controller\Controller;
use Antimonial\Http\Request;
use Antimonial\Http\ValidationException;

$request = Request::fromGlobals();
$out = ['action' => $_GET['action'] ?? 'unknown'];

switch ($out['action']) {
    case 'store':
        $file = $request->file('upload');
        $dir = sys_get_temp_dir().'/ant_up_srv_'.uniqid();
        $path = $file->store($dir, 'saved.txt');
        $out['stored'] = file_get_contents($path);
        $out['path'] = $path;
        break;

    case 'validate':
        $ctrl = new class extends Controller
        {
            public function run(Request $r): array
            {
                return $this->validate($r, [
                    'avatar' => 'file|image',
                    'doc' => 'mimes:pdf,txt',
                    'big' => 'max_size:1',
                ]);
            }
        };
        try {
            $ctrl->run($request);
            $out['valid'] = true;
        } catch (ValidationException $e) {
            $out['valid'] = false;
            $out['errors'] = $e->errors();
        }
        break;
}

echo json_encode($out);
