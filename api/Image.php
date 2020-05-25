<?php

namespace app\extensions\fkgmedia\api;

use \app\conf\App;
use \app\inc\Response;
use \app\models\Database;
use \app\conf\Connection;
use \app\inc\Session;
use \app\inc\Input;
use \app\inc\Model;
use \app\models\Table;
use \Aws\S3\S3Client;
use \League\Flysystem\AwsS3v3\AwsS3Adapter;
use \League\Flysystem\Filesystem;

/**
 * Class Processvector
 * @package app\controllers\upload
 */
class Image extends \app\inc\Controller
{

    function __construct()
    {

        //Session::start();
        //Session::authenticate(null);
    }

    public function get_index()
    {
        die("GET");

    }

    public function createThumbnail($image_name, $new_width, $new_height, $uploadDir, $moveToDir)
    {
        $path = $uploadDir . '/' . $image_name;

        $mime = getimagesize($path);

        if ($mime['mime'] == 'image/png') {
            $src_img = imagecreatefrompng($path);
        }
        if ($mime['mime'] == 'image/jpg' || $mime['mime'] == 'image/jpeg' || $mime['mime'] == 'image/pjpeg') {
            $src_img = imagecreatefromjpeg($path);
        }

        $old_x = imageSX($src_img);
        $old_y = imageSY($src_img);

        if ($old_x > $old_y) {
            $thumb_w = $new_width;
            $thumb_h = $old_y * ($new_height / $old_x);
        }

        if ($old_x < $old_y) {
            $thumb_w = $old_x * ($new_width / $old_y);
            $thumb_h = $new_height;
        }

        if ($old_x == $old_y) {
            $thumb_w = $new_width;
            $thumb_h = $new_height;
        }

        $dst_img = ImageCreateTrueColor($thumb_w, $thumb_h);

        imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_x, $old_y);


        // New save location
        $new_thumb_loc = $moveToDir . $image_name;

        if ($mime['mime'] == 'image/png') {
            $result = imagepng($dst_img, $new_thumb_loc, 8);
        }
        if ($mime['mime'] == 'image/jpg' || $mime['mime'] == 'image/jpeg' || $mime['mime'] == 'image/pjpeg') {
            $result = imagejpeg($dst_img, $new_thumb_loc, 80);
        }

        imagedestroy($dst_img);
        imagedestroy($src_img);

        return $result;
    }

    public function post_index()
    {

        @set_time_limit(5 * 60);
        $mainDir = App::$param['path'] . "/app/tmp/fkg";
        $targetDir = $mainDir . "/__images";

        $thumbNailsSizes = [171, 360, 560, 1600];


        $cleanupTargetDir = true;
        $maxFileAge = 5 * 3600;

        if (!file_exists($mainDir)) {
            @mkdir($mainDir);
        }
        if (!file_exists($targetDir)) {
            @mkdir($targetDir);
        }

        foreach ($thumbNailsSizes as $size) {
            if (!file_exists($targetDir . "/" . (string)$size)) {
                @mkdir($targetDir . "/" . (string)$size);
            }
        }

        if (isset($_REQUEST["names"])) {
            $fileNames = $_REQUEST["names"];
        } elseif (!empty($_FILES)) {
            $fileNames = $_FILES["files"]["name"];
        } else {
            $fileName = uniqid("file_");
        }

        $chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
        $chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 0;


        $client = new S3Client([
            'credentials' => [
                'key' => App::$param["s3"]["id"],
                'secret' => App::$param["s3"]["secret"],
            ],
            'region' => 'eu-west-1',
            'version' => 'latest',
        ]);

        $adapter = new AwsS3Adapter($client, 'mapcentia-www');

        $filesystem = new Filesystem($adapter);


        if (!empty($_FILES)) {
            for ($i = 0; $i < sizeof($_FILES["files"]["name"]); $i++) {
                $filePath = $targetDir . DIRECTORY_SEPARATOR . $fileNames[$i];

                if ($cleanupTargetDir) {
                    if (!is_dir($targetDir) || !$dir = opendir($targetDir)) {
                        return [
                            "success" => false,
                            "code" => 100,
                            "message" => "Failed to open temp directory.",
                        ];
                    }
                    while (($file = readdir($dir)) !== false) {
                        $tmpfilePath = $targetDir . DIRECTORY_SEPARATOR . $file;

                        // If temp file is current file proceed to the next
                        if ($tmpfilePath == "{$filePath}.part") {
                            continue;
                        }

                        // Remove temp file if it is older than the max age and is not the current file
                        if (preg_match('/\.part$/', $file) && (filemtime($tmpfilePath) < time() - $maxFileAge)) {
                            @unlink($tmpfilePath);
                        }
                    }
                    closedir($dir);
                }
                // Open temp file
                if (!$out = @fopen("{$filePath}.part", $chunks ? "ab" : "wb")) {
                    return [
                        "success" => false,
                        "code" => 102,
                        "message" => "Failed to open output stream.",
                    ];
                }

                if ($_FILES["files"]["error"][$i] || !is_uploaded_file($_FILES["files"]["tmp_name"][$i])) {
                    return [
                        "success" => false,
                        "code" => 103,
                        "message" => "Failed to move uploaded file.",
                    ];
                }

                // Read binary input stream and append it to temp file
                if (!$in = @fopen($_FILES["files"]["tmp_name"][$i], "rb")) {
                    return [
                        "success" => false,
                        "code" => 101,
                        "message" => "Failed to open input stream.",
                    ];
                }

                while ($buff = fread($in, 4096)) {
                    fwrite($out, $buff);
                }

                @fclose($out);
                @fclose($in);

                // Check if file has been uploaded
                if (!$chunks || $chunk == $chunks - 1) {
                    // Strip the temp .part suffix off
                    rename("{$filePath}.part", $filePath);
                }

                $response = $filesystem->put("test/" . $fileNames[$i], file_get_contents($filePath));

                foreach ($thumbNailsSizes as $size) {
                    $this->createThumbnail($fileNames[$i], $size, $size, $targetDir, $targetDir . DIRECTORY_SEPARATOR . (string)$size . DIRECTORY_SEPARATOR);
                    $response = $filesystem->put("test" . DIRECTORY_SEPARATOR . (string)$size . DIRECTORY_SEPARATOR . $fileNames[$i], file_get_contents($targetDir . DIRECTORY_SEPARATOR . (string)$size . DIRECTORY_SEPARATOR . $fileNames[$i]));
                }
            }
        } else {
            if (!$in = @fopen("php://input", "rb")) {
                return [
                    "success" => false,
                    "code" => 101,
                    "message" => "Failed to open input stream.",
                ];
            }
        }


        return [
            "success" => true,
            "image" => $fileName,
        ];
    }


    public function delete_index()
    {

    }
}
